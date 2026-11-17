<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosSyncTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PosSyncService
{
    public function __construct(
        protected PosSaleService $saleService,
        protected PosDefaultWarehouseResolver $warehouseResolver,
    ) {}

    /**
     * Synchronize a local offline sale transaction idempotently.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     status: string,
     *     is_idempotent: bool,
     *     sale: ?PosSale,
     *     conflict_reason: ?string
     * }
     */
    public function syncOfflineSale(array $payload, User $user): array
    {
        $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
        if ($clientUuid === '') {
            throw ValidationException::withMessages([
                'client_uuid' => ['معرف العملية المحلي الفريد (UUID) مطلوب لإجراء المزامنة.'],
            ]);
        }

        // Ignore any client-supplied warehouse_id (POS strictly uses the single default warehouse)
        unset($payload['warehouse_id']);

        // Consolidate requested quantities per product_id and bulk-load products in 1 query
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $requestedByProductId = [];

        foreach ($items as $item) {
            if (! is_array($item) || empty($item['product_id']) || ! is_numeric($item['product_id'])) {
                continue;
            }
            $prodId = (int) $item['product_id'];
            $reqQtyStr = Decimal::fromNumeric($item['quantity'] ?? 0, Decimal::QUANTITY_SCALE);

            $requestedByProductId[$prodId] = isset($requestedByProductId[$prodId])
                ? Decimal::add($requestedByProductId[$prodId], $reqQtyStr, Decimal::QUANTITY_SCALE)
                : $reqQtyStr;
        }

        $products = $this->saleService->loadProducts(array_keys($requestedByProductId));

        // 0. Re-verify permissions and server-side prices using the bulk-loaded products map (0 extra queries)
        $this->saleService->authorizeSalePayload($payload, $products, $user);

        // 1. Idempotency Check: check if sale already exists in official pos_sales
        $existingSale = PosSale::query()
            ->where('client_uuid', $clientUuid)
            ->with(PosSaleService::SALE_RELATIONS)
            ->first();

        if ($existingSale) {
            PosSyncTransaction::query()->updateOrCreate(
                ['client_uuid' => $clientUuid],
                [
                    'shift_id' => $existingSale->shift_id,
                    'pos_sale_id' => $existingSale->id,
                    'payload' => $payload,
                    'status' => PosSyncTransaction::STATUS_SYNCED,
                    'conflict_reason' => null,
                    'synced_at' => Carbon::now(),
                ]
            );

            return [
                'status' => 'synced',
                'is_idempotent' => true,
                'sale' => $existingSale,
                'conflict_reason' => null,
            ];
        }

        // Check if already recorded as conflict
        $existingSync = PosSyncTransaction::query()
            ->where('client_uuid', $clientUuid)
            ->first();

        if ($existingSync && $existingSync->status === PosSyncTransaction::STATUS_CONFLICT) {
            return [
                'status' => 'conflict',
                'is_idempotent' => true,
                'sale' => null,
                'conflict_reason' => $existingSync->conflict_reason,
            ];
        }

        // 2. Validate shift
        $shiftId = isset($payload['shift_id']) ? (int) $payload['shift_id'] : null;
        if ($shiftId) {
            $shift = PosShift::query()->find($shiftId);
            if (! $shift) {
                $conflictReason = "الوردية المحددة للعملية (#{$shiftId}) غير موجودة على الخادم.";
                $this->recordConflict($clientUuid, null, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }

            if ($shift->isClosed()) {
                $conflictReason = "الوردية المحددة للعملية ({$shift->shift_number}) مغلقة بالفعل على الخادم. لا يمكن ترحيل مبيعات أوفلاين قديمة إلى وردية مغلقة.";
                $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }
        } else {
            $shift = PosShift::query()->open()->first();
            if (! $shift) {
                $conflictReason = 'لا توجد وردية مفتوحة على الخادم لاستقبال المزامنة.';
                $this->recordConflict($clientUuid, null, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }
            $payload['shift_id'] = $shift->id;
        }

        // 3. Resolve the single default warehouse via PosDefaultWarehouseResolver without fallback
        try {
            $warehouse = $this->warehouseResolver->resolve();
        } catch (ValidationException) {
            $conflictReason = __('pos.errors.default_warehouse_missing');
            $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

            return [
                'status' => 'conflict',
                'is_idempotent' => false,
                'sale' => null,
                'conflict_reason' => $conflictReason,
            ];
        }

        // 4. Bulk check product existence and stock sufficiency in memory (0 queries inside loops)
        if ($requestedByProductId !== []) {
            foreach ($requestedByProductId as $prodId => $reqQtyStr) {
                if (! $products->has($prodId)) {
                    $conflictReason = __('pos.errors.product_not_found', ['id' => $prodId]);
                    $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

                    return [
                        'status' => 'conflict',
                        'is_idempotent' => false,
                        'sale' => null,
                        'conflict_reason' => $conflictReason,
                    ];
                }
            }

            $stocksByProductId = WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('product_id', array_keys($requestedByProductId))
                ->pluck('quantity', 'product_id');

            foreach ($requestedByProductId as $prodId => $reqQtyStr) {
                $availStr = Decimal::fromNumeric($stocksByProductId[$prodId] ?? 0, Decimal::QUANTITY_SCALE);

                if (Decimal::comp($reqQtyStr, $availStr, Decimal::QUANTITY_SCALE) > 0) {
                    /** @var Product $product */
                    $product = $products->get($prodId);
                    $prodName = $product->name;
                    $conflictReason = "تعارض مخزون: الرصيد غير كافٍ للمنتج '{$prodName}' في المخزن '{$warehouse->name}'. الرصيد المتاح: {$availStr}، الكمية المطلوبة محلياً: {$reqQtyStr}.";

                    $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

                    return [
                        'status' => 'conflict',
                        'is_idempotent' => false,
                        'sale' => null,
                        'conflict_reason' => $conflictReason,
                    ];
                }
            }
        }

        // 5. Attempt official sale creation (createSale is internally locked atomically on client_uuid and warehouse_stocks)
        try {
            $sale = $this->saleService->createSale($payload, $user);

            PosSyncTransaction::query()->updateOrCreate(
                ['client_uuid' => $clientUuid],
                [
                    'shift_id' => $shift->id,
                    'pos_sale_id' => $sale->id,
                    'payload' => $payload,
                    'status' => PosSyncTransaction::STATUS_SYNCED,
                    'conflict_reason' => null,
                    'synced_at' => Carbon::now(),
                ]
            );

            return [
                'status' => 'synced',
                'is_idempotent' => false,
                'sale' => $sale,
                'conflict_reason' => null,
            ];
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Check if concurrent idempotent request already completed the sale
            $existingSale = PosSale::query()
                ->where('client_uuid', $clientUuid)
                ->with(PosSaleService::SALE_RELATIONS)
                ->first();

            if ($existingSale) {
                return [
                    'status' => 'synced',
                    'is_idempotent' => true,
                    'sale' => $existingSale,
                    'conflict_reason' => null,
                ];
            }

            $conflictReason = $e->getMessage();
            $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

            return [
                'status' => 'conflict',
                'is_idempotent' => false,
                'sale' => null,
                'conflict_reason' => $conflictReason,
            ];
        }
    }

    /**
     * Record a sync conflict preserving the local operation payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function recordConflict(string $uuid, ?int $shiftId, array $payload, string $reason): void
    {
        try {
            PosSyncTransaction::query()->updateOrCreate(
                ['client_uuid' => $uuid],
                [
                    'shift_id' => $shiftId,
                    'pos_sale_id' => null,
                    'payload' => $payload,
                    'status' => PosSyncTransaction::STATUS_CONFLICT,
                    'conflict_reason' => $reason,
                    'synced_at' => null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to record POS sync conflict: ' . $e->getMessage(), [
                'client_uuid' => $uuid,
                'shift_id' => $shiftId,
            ]);
        }
    }

    /**
     * Get offline bootstrap dataset (products, barcodes, prices, stock, payment methods)
     * scoped strictly to the single default warehouse.
     *
     * @return array<string, mixed>
     */
    public function getBootstrapData(): array
    {
        $warehouse = $this->warehouseResolver->resolve();
        $whId = $warehouse->id;

        $products = Product::query()
            ->with(['category:id,name', 'warehouseStocks' => function ($q) use ($whId): void {
                $q->where('warehouse_id', $whId);
            }])
            ->get()
            ->map(function (Product $p) use ($whId): array {
                $stock = $p->warehouseStocks->firstWhere('warehouse_id', $whId);
                $availableStr = Decimal::fromNumeric($stock?->quantity ?? 0, Decimal::QUANTITY_SCALE);
                $available = (float) $availableStr;

                return [
                    'id' => $p->id,
                    'category_id' => $p->category_id,
                    'name' => $p->name,
                    'barcode' => $p->barcode,
                    'selling_price' => (string) $p->selling_price,
                    'purchase_price' => (string) $p->purchase_price,
                    'category' => $p->category?->name,
                    'current_stock' => $available,
                    'stock_quantity' => $available,
                ];
            });

        $paymentMethods = PaymentMethod::query()
            ->get(['id', 'name', 'type']);

        $openShifts = PosShift::query()
            ->open()
            ->with('openedBy:id,name')
            ->get(['id', 'shift_number', 'opened_at', 'opened_by', 'opening_balance'])
            ->map(fn (PosShift $s): array => [
                'id' => $s->id,
                'shift_number' => $s->shift_number,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'opened_by' => $s->opened_by,
                'opened_by_name' => $s->openedBy?->name ?? 'غير محدد',
                'opening_balance' => (string) $s->opening_balance,
            ]);

        return [
            'warehouse' => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
            ],
            'products' => $products,
            'payment_methods' => $paymentMethods,
            'open_shifts' => $openShifts,
            'server_time' => Carbon::now()->toIso8601String(),
        ];
    }
}
