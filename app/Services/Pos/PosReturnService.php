<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PaymentMethodType;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\PosReturn;
use App\Models\PosReturnItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryDocumentNumberService;
use App\Services\InventoryDocumentWorkflowService;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosReturnService
{
    /**
     * @var array<int, string>
     */
    public const RETURN_RELATIONS = [
        'items.product',
        'items.saleItem',
        'paymentMethod',
        'sale',
        'shift',
        'creator',
        'inventoryDocuments',
    ];

    public function __construct(
        protected PosNumberService $numberService,
        protected InventoryDocumentNumberService $invDocNumberService,
        protected InventoryDocumentWorkflowService $invWorkflowService,
        protected PosShiftService $shiftService,
        protected PosDefaultWarehouseResolver $warehouseResolver,
        protected PosReturnCalculator $calculator,
        protected PosReturnQueryService $queryService,
    ) {}

    /**
     * Process a return against an existing POS sale invoice.
     * Enforces atomic idempotency on client_uuid to prevent duplicate returns,
     * duplicate refunding, and duplicate inventory documents.
     *
     * @param  array<string, mixed>  $data
     */
    public function processReturn(array $data, User $user): PosReturn
    {
        $clientUuid = isset($data['client_uuid']) && trim((string) $data['client_uuid']) !== ''
            ? trim((string) $data['client_uuid'])
            : null;

        if ($clientUuid !== null) {
            $existing = $this->findReturnByClientUuid($clientUuid);
            if ($existing) {
                return $existing;
            }

            $lock = Cache::lock('pos_return_uuid_'.$clientUuid, 15);

            return $lock->block(15, function () use ($data, $user, $clientUuid): PosReturn {
                $existing = $this->findReturnByClientUuid($clientUuid);
                if ($existing) {
                    return $existing;
                }

                return $this->executeReturnProcessing($data, $user, $clientUuid);
            });
        }

        return $this->executeReturnProcessing($data, $user, null);
    }

    protected function findReturnByClientUuid(string $clientUuid): ?PosReturn
    {
        return PosReturn::query()
            ->where('client_uuid', $clientUuid)
            ->with(self::RETURN_RELATIONS)
            ->first();
    }

    /**
     * Aggregate previously returned quantities per pos_sale_item_id in a single GROUP BY query.
     *
     * @param  array<int, int>  $saleItemIds
     * @return Collection<int, mixed>
     */
    public function sumReturnedQuantitiesBySaleItemIds(array $saleItemIds): Collection
    {
        return $this->queryService->sumReturnedQuantitiesBySaleItemIds($saleItemIds);
    }

    /**
     * Determine whether a sale item is eligible for return.
     * Only missing/deleted products or defective/damaged items are rejected.
     *
     * @param  array<string, mixed>  $rawItem
     */
    protected function assertItemReturnable(PosSaleItem $saleItem, array $rawItem): void
    {
        $product = $saleItem->product;

        if (! $product) {
            throw ValidationException::withMessages([
                'items' => ['المنتج المرتبط بسطر الفاتورة غير موجود ولا يمكن إرجاعه.'],
            ]);
        }

        $attributes = $product->getAttributes();
        $isDefective = ! empty($rawItem['is_defective'])
            || ! empty($rawItem['is_damaged'])
            || ($rawItem['condition'] ?? null) === 'defective'
            || ! empty($attributes['is_defective'])
            || (array_key_exists('is_returnable', $attributes) && ! $attributes['is_returnable']);

        if ($isDefective) {
            throw ValidationException::withMessages([
                'items' => ["المنتج '{$product->name}' معيب أو تالف ولا يقبل الإرجاع."],
            ]);
        }
    }

    /**
     * Internal atomic execution of return processing.
     *
     * @param  array<string, mixed>  $data
     */
    protected function executeReturnProcessing(array $data, User $user, ?string $clientUuid): PosReturn
    {
        try {
            return DB::transaction(function () use ($data, $user, $clientUuid): PosReturn {
                // 1. Lock and validate shift
                /** @var PosShift $shift */
                $shift = PosShift::query()->lockForUpdate()->findOrFail($data['shift_id']);
                if ((int) $shift->opened_by !== (int) $user->id) {
                    throw ValidationException::withMessages([
                        'shift_id' => [__('pos.errors.shift_belongs_to_another_user')],
                    ]);
                }
                if ($shift->isClosed()) {
                    throw ValidationException::withMessages([
                        'shift_id' => ['الوردية المحددة مغلقة. لا يمكن إجراء عمليات إرجاع على وردية مغلقة.'],
                    ]);
                }

                // 2. Lock original sale and its items to serialize concurrent returns
                /** @var PosSale $sale */
                $sale = PosSale::query()
                    ->lockForUpdate()
                    ->with(['paymentMethod', 'inventoryDocuments'])
                    ->findOrFail($data['pos_sale_id']);

                $saleItems = PosSaleItem::query()
                    ->where('sale_id', $sale->id)
                    ->lockForUpdate()
                    ->with('product')
                    ->get();

                $sale->setRelation('items', $saleItems);

                // 3. Resolve destination warehouse (client warehouse_id is strictly ignored)
                $warehouse = $this->resolveReturnWarehouse($sale);

                // 4. Validate return items
                $rawItems = $data['items'] ?? [];
                if (empty($rawItems)) {
                    throw ValidationException::withMessages([
                        'items' => ['يجب تحديد صنف واحد على الأقل للإرجاع.'],
                    ]);
                }

                $saleItemsById = $saleItems->keyBy('id');
                $returnedByItemId = $this->sumReturnedQuantitiesBySaleItemIds($saleItemsById->keys()->all())->all();

                $processedItems = [];
                $totalRefundAmount = '0.00';

                foreach ($rawItems as $raw) {
                    $saleItemId = (int) ($raw['pos_sale_item_id'] ?? 0);
                    $parsedQty = Decimal::parse($raw['quantity'] ?? null);
                    $reqQtyStr = $parsedQty !== null ? Decimal::round($parsedQty, Decimal::QUANTITY_SCALE) : null;

                    if ($reqQtyStr === null || ! Decimal::isPositive($reqQtyStr, Decimal::QUANTITY_SCALE)) {
                        throw ValidationException::withMessages([
                            'items' => ['الكمية المرتجعة يجب أن تكون أكبر من الصفر.'],
                        ]);
                    }

                    /** @var PosSaleItem|null $saleItem */
                    $saleItem = $saleItemsById->get($saleItemId);
                    if (! $saleItem) {
                        throw ValidationException::withMessages([
                            'items' => ["سطر الفاتورة المعرف بـ {$saleItemId} لا ينتمي إلى هذه الفاتورة."],
                        ]);
                    }

                    $this->assertItemReturnable($saleItem, is_array($raw) ? $raw : []);

                    // Check remaining returnable quantity across all prior returns using BCMath
                    $alreadyReturnedStr = Decimal::fromNumeric($returnedByItemId[$saleItem->id] ?? 0, Decimal::QUANTITY_SCALE);
                    $origQtyStr = Decimal::fromNumeric($saleItem->quantity, Decimal::QUANTITY_SCALE);
                    $remainingQtyStr = Decimal::max(
                        '0.000',
                        Decimal::sub($origQtyStr, $alreadyReturnedStr, Decimal::QUANTITY_SCALE),
                        Decimal::QUANTITY_SCALE
                    );

                    if (Decimal::comp($reqQtyStr, $remainingQtyStr, Decimal::QUANTITY_SCALE) > 0) {
                        $prodName = $saleItem->product?->name ?? 'المنتج';
                        throw ValidationException::withMessages([
                            'items' => [
                                "الكمية المطلوبة لإرجاع '{$prodName}' ({$reqQtyStr}) تتجاوز الكمية المتاحة للإرجاع ({$remainingQtyStr}). المباع: {$origQtyStr}، المرتجع سابقاً: {$alreadyReturnedStr}.",
                            ],
                        ]);
                    }

                    // Track cumulative returned quantity in case same sale item appears twice in payload
                    $returnedByItemId[$saleItem->id] = Decimal::add($alreadyReturnedStr, $reqQtyStr, Decimal::QUANTITY_SCALE);

                    $lineCalc = $this->calculator->calculateLine($saleItem, $reqQtyStr, $origQtyStr);
                    $lineRefund = $lineCalc['refund_amount'];
                    $lineAllocatedDiscount = $lineCalc['allocated_discount'];

                    $totalRefundAmount = Decimal::add($totalRefundAmount, $lineRefund, Decimal::MONEY_SCALE);

                    $processedItems[] = [
                        'pos_sale_item_id' => $saleItem->id,
                        'product_id' => $saleItem->product_id,
                        'quantity' => $reqQtyStr,
                        'unit_price' => Decimal::fromNumeric($saleItem->unit_price, Decimal::MONEY_SCALE),
                        'allocated_discount' => $lineAllocatedDiscount,
                        'refund_amount' => $lineRefund,
                    ];
                }

                // 5. Create POS Return record
                $now = Carbon::now();

                /** @var PosReturn $posReturn */
                $posReturn = PosReturn::query()->create([
                    'pos_sale_id' => $sale->id,
                    'shift_id' => $shift->id,
                    'payment_method_id' => $sale->payment_method_id, // Must match original payment method!
                    'total_returned' => $totalRefundAmount,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $user->id,
                    'returned_at' => $now,
                    'client_uuid' => $clientUuid,
                ]);

                // 6. Bulk insert POS Return Items in 1 query
                PosReturnItem::query()->insert(array_map(static fn (array $itemRow): array => [
                    'pos_return_id' => $posReturn->id,
                    'pos_sale_item_id' => $itemRow['pos_sale_item_id'],
                    'product_id' => $itemRow['product_id'],
                    'quantity' => $itemRow['quantity'],
                    'unit_price' => $itemRow['unit_price'],
                    'allocated_discount' => $itemRow['allocated_discount'],
                    'refund_amount' => $itemRow['refund_amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $processedItems));

                // 7. If original sale was cash, record cash_return transaction in the current shift
                if ($sale->paymentMethod?->type === PaymentMethodType::CASH) {
                    $tx = new PosShiftTransaction;
                    $tx->shift_id = $shift->id;
                    $tx->type = PosShiftTransaction::TYPE_CASH_RETURN;
                    $tx->amount = $totalRefundAmount;
                    $tx->pos_sale_id = null;
                    $tx->pos_return_id = $posReturn->id;
                    $tx->name = "مرتجع نقدي - فاتورة {$sale->invoice_number} - إشعار مرتجع {$posReturn->return_number}";
                    $tx->description = null;
                    $tx->created_by = $user->id;
                    $tx->created_at = $now;
                    $tx->save();

                    // Refresh expected cash
                    $shift->update([
                        'expected_cash' => $this->shiftService->calculateExpectedCash($shift),
                    ]);
                }

                // 8. Create Goods Receipt document and bulk-insert its items, then post it
                $docNumber = $this->invDocNumberService->generate(InventoryDocumentType::GOODS_RECEIPT);

                /** @var InventoryDocument $invDoc */
                $invDoc = InventoryDocument::query()->create([
                    'document_number' => $docNumber,
                    'document_type' => InventoryDocumentType::GOODS_RECEIPT,
                    'referenceable_type' => 'pos_return',
                    'referenceable_id' => $posReturn->id,
                    'source_warehouse_id' => null,
                    'destination_warehouse_id' => $warehouse->id,
                    'adjustment_direction' => null,
                    'return_direction' => null,
                    'status' => InventoryDocumentStatus::APPROVED,
                    'document_date' => $now->toDateString(),
                    'notes' => "إذن استلام مخزني تلقائي لمرتجع نقطة البيع رقم: {$posReturn->return_number}",
                    'created_by' => $user->id,
                    'approved_by' => $user->id,
                    'approved_at' => $now,
                ]);

                InventoryDocumentItem::query()->insert(array_map(static fn (array $itemRow): array => [
                    'inventory_document_id' => $invDoc->id,
                    'product_id' => $itemRow['product_id'],
                    'quantity' => $itemRow['quantity'],
                    'notes' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $processedItems));

                $postedDoc = $this->invWorkflowService->post($invDoc, $user, false);

                return $this->hydrateReturn($posReturn, $sale, $shift, $user, $saleItemsById, $postedDoc);
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            if ($clientUuid !== null && str_contains($e->getMessage(), 'client_uuid')) {
                $existing = $this->findReturnByClientUuid($clientUuid);
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }

    /**
     * Resolve the destination warehouse for a POS return.
     * Enforces that a system default warehouse exists, uses the original sale's source warehouse
     * when present, and never accepts client-supplied warehouse_id or random warehouse fallbacks.
     */
    protected function resolveReturnWarehouse(PosSale $sale): Warehouse
    {
        $defaultWarehouse = $this->warehouseResolver->resolve();

        $origDoc = $sale->inventoryDocuments->first();
        $origWhId = $origDoc?->source_warehouse_id;

        if ($origWhId && (int) $origWhId !== (int) $defaultWarehouse->id) {
            $origWarehouse = Warehouse::query()->find($origWhId);
            if ($origWarehouse) {
                return $origWarehouse;
            }
        }

        return $defaultWarehouse;
    }

    /**
     * Hydrate return relations in memory from already-loaded models.
     *
     * @param  EloquentCollection<int, PosSaleItem>  $saleItemsById
     */
    protected function hydrateReturn(
        PosReturn $posReturn,
        PosSale $sale,
        PosShift $shift,
        User $user,
        EloquentCollection $saleItemsById,
        InventoryDocument $postedDoc
    ): PosReturn {
        $posReturn->setRelation('sale', $sale->withoutRelations())
            ->setRelation('shift', $shift)
            ->setRelation('paymentMethod', $sale->paymentMethod)
            ->setRelation('creator', $user->withoutRelations())
            ->setRelation('inventoryDocuments', new EloquentCollection([$postedDoc->withoutRelations()]))
            ->load('items');

        foreach ($posReturn->items as $returnItem) {
            /** @var PosSaleItem|null $saleItem */
            $saleItem = $saleItemsById->get($returnItem->pos_sale_item_id);
            $returnItem->setRelation('saleItem', $saleItem?->withoutRelations());
            $returnItem->setRelation('product', $saleItem?->product);
        }

        return $posReturn;
    }

    /**
     * Inspect returnable items for an existing sale invoice.
     *
     * @return array<string, mixed>
     */
    public function getReturnableItems(PosSale $sale): array
    {
        return $this->queryService->getReturnableItems($sale);
    }

    /**
     * Paginate POS returns with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateReturns(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryService->paginateReturns($filters, $perPage);
    }

    /**
     * Get POS return with complete details including sale, items, and linked inventory document.
     */
    public function getReturnDetails(PosReturn $posReturn): PosReturn
    {
        return $this->queryService->getReturnDetails($posReturn);
    }
}
