<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\StockAdjustmentDirection;
use App\Enums\StockMovementDirection;
use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeStatus;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\StocktakeItem;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryDocumentWorkflowService;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

class StockMovementService
{
    /**
     * Post an approved inventory document:
     * 1. Locks the document row and validates it is approved and has items.
     * 2. Resolves destination/source warehouse and direction for each item line.
     * 3. Locks all affected warehouse_stocks rows in deterministic order.
     * 4. Enforces strict non-negative stock rules.
     * 5. Creates immutable stock_movements and returns persisted models with IDs.
     * 6. Updates current warehouse_stocks balances.
     *
     * @return array<StockMovement>
     */
    public function postDocumentMovements(InventoryDocument $document, int $userId): array
    {
        return DB::transaction(function () use ($document, $userId): array {
            /** @var InventoryDocument $lockedDocument */
            $lockedDocument = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! InventoryDocumentWorkflowService::isAuthoritativeTransitionActive((int) $lockedDocument->id, InventoryDocumentStatus::POSTED)) {
                throw new LogicException(
                    "Cannot post stock movements for document {$lockedDocument->document_number} outside InventoryDocumentWorkflowService."
                );
            }

            // 1. Validate status
            if ($lockedDocument->status !== InventoryDocumentStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => ["لا يمكن ترحيل المستند وهو في حالة '{$lockedDocument->status->value}'. يجب أن يكون معتمداً أولاً."],
                ]);
            }

            // 2. Validate items exist
            $items = $lockedDocument->items()->with('product')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['المستند لا يحتوي على أية بنود أو أصناف للترحيل.'],
                ]);
            }

            // 3. Prevent duplicate posting / idempotency under document row lock
            if ($lockedDocument->stockMovements()->whereNull('reversal_of_id')->exists()) {
                throw new LogicException("المستند {$lockedDocument->document_number} تم ترحيله مسبقاً وسجل حركاته موجود بالفعل.");
            }

            // 4. Validate warehouses exist
            $this->validateWarehousesExist($lockedDocument);

            // 4b. Validate Purchase Invoice Goods Receipt completeness & status
            if (in_array($lockedDocument->referenceable_type, ['purchase_invoice', PurchaseInvoice::class], true)) {
                $this->validatePurchaseInvoiceGoodsReceipt($lockedDocument, $items);
            }

            // 5. Build planned movements
            $plannedMovements = $this->buildPlannedMovementsForDocument($lockedDocument, $items, $userId);

            // 6. Lock and update stocks within the transaction
            return $this->applyMovements($plannedMovements);
        });
    }

    /**
     * Ensure a Goods Receipt linked to a Purchase Invoice receives the complete invoice quantities in one operation.
     *
     * @param Collection<int, InventoryDocumentItem> $docItems
     */
    protected function validatePurchaseInvoiceGoodsReceipt(InventoryDocument $document, Collection $docItems): void
    {
        if (! $document->referenceable_id) {
            throw ValidationException::withMessages([
                'referenceable_id' => ['Goods receipt is not linked to a valid purchase invoice.'],
            ]);
        }

        /** @var PurchaseInvoice|null $invoice */
        $invoice = PurchaseInvoice::query()
            ->with('items')
            ->lockForUpdate()
            ->find($document->referenceable_id);

        if (! $invoice) {
            throw ValidationException::withMessages([
                'referenceable_id' => ['Linked purchase invoice could not be found.'],
            ]);
        }

        if ($invoice->status === PurchaseInvoiceStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => ['Cannot post a goods receipt for a cancelled purchase invoice.'],
            ]);
        }

        $invoiceQtyByProduct = [];
        foreach ($invoice->items as $invItem) {
            $pid = (int) $invItem->product_id;
            $qty = Decimal::fromNumeric($invItem->quantity, Decimal::QUANTITY_SCALE);
            $invoiceQtyByProduct[$pid] = isset($invoiceQtyByProduct[$pid])
                ? Decimal::add($invoiceQtyByProduct[$pid], $qty, Decimal::QUANTITY_SCALE)
                : $qty;
        }

        $docQtyByProduct = [];
        foreach ($docItems as $docItem) {
            $pid = (int) $docItem->product_id;
            $qty = Decimal::fromNumeric($docItem->quantity, Decimal::QUANTITY_SCALE);
            $docQtyByProduct[$pid] = isset($docQtyByProduct[$pid])
                ? Decimal::add($docQtyByProduct[$pid], $qty, Decimal::QUANTITY_SCALE)
                : $qty;
        }

        if (count($invoiceQtyByProduct) !== count($docQtyByProduct)) {
            throw ValidationException::withMessages([
                'items' => ['Partial goods receipt is not supported; all purchase invoice items and quantities must be received together.'],
            ]);
        }

        foreach ($invoiceQtyByProduct as $productId => $expectedQty) {
            if (! isset($docQtyByProduct[$productId]) || Decimal::comp($docQtyByProduct[$productId], $expectedQty, Decimal::QUANTITY_SCALE) !== 0) {
                throw ValidationException::withMessages([
                    'items' => ['Partial goods receipt is not supported; goods receipt quantities must match purchase invoice quantities.'],
                ]);
            }
        }
    }

    /**
     * Reverse a posted inventory document:
     * 1. Locks the document row and validates it is posted.
     * 2. Loads and locks all original non-reversal movements.
     * 3. Verifies none have already been reversed.
     * 4. Inverts direction for each movement (INBOUND -> OUTBOUND, OUTBOUND -> INBOUND).
     * 5. Enforces non-negative stock check on the reversed movements (especially inverted INBOUND).
     * 6. Creates reversal stock_movements linking to original movements via reversal_of_id.
     * 7. Updates current warehouse_stocks balances.
     *
     * @return array<StockMovement>
     */
    public function reverseDocumentMovements(InventoryDocument $document, int $userId): array
    {
        return DB::transaction(function () use ($document, $userId): array {
            /** @var InventoryDocument $lockedDocument */
            $lockedDocument = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! InventoryDocumentWorkflowService::isAuthoritativeTransitionActive((int) $lockedDocument->id, InventoryDocumentStatus::REVERSED)) {
                throw new LogicException(
                    "Cannot reverse stock movements for document {$lockedDocument->document_number} outside InventoryDocumentWorkflowService."
                );
            }

            if ($lockedDocument->status !== InventoryDocumentStatus::POSTED) {
                throw ValidationException::withMessages([
                    'status' => ["لا يمكن عكس المستند وهو في حالة '{$lockedDocument->status->value}'. يمكن عكس المستندات المرحّلة فقط."],
                ]);
            }

            /** @var Collection<int, StockMovement> $originalMovements */
            $originalMovements = $lockedDocument->stockMovements()
                ->whereNull('reversal_of_id')
                ->with(['product', 'warehouse'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($originalMovements->isEmpty()) {
                throw ValidationException::withMessages([
                    'movements' => ['لم يتم العثور على أية حركات مخزنية أصلية لعكس هذا المستند.'],
                ]);
            }

            // Ensure no movement has already been reversed under row lock
            $alreadyReversed = StockMovement::query()
                ->whereIn('reversal_of_id', $originalMovements->pluck('id')->all())
                ->lockForUpdate()
                ->exists();

            if ($alreadyReversed) {
                throw new LogicException("لا يمكن عكس المستند أكثر من مرة؛ بعض أو كل حركاته تم عكسها سابقاً.");
            }

            $today = Carbon::now()->toDateString();
            $plannedReversals = [];

            foreach ($originalMovements as $orig) {
                $oppositeDir = $orig->direction->opposite();

                $plannedReversals[] = [
                    'inventory_document_id' => $orig->inventory_document_id,
                    'inventory_document_item_id' => $orig->inventory_document_item_id,
                    'product_id' => $orig->product_id,
                    'warehouse_id' => $orig->warehouse_id,
                    'direction' => $oppositeDir,
                    'quantity' => Decimal::fromNumeric($orig->quantity, Decimal::QUANTITY_SCALE),
                    'movement_date' => $today,
                    'reversal_of_id' => $orig->id,
                    'created_by' => $userId,
                    'product' => $orig->product,
                    'warehouse' => $orig->warehouse,
                ];
            }

            return $this->applyMovements($plannedReversals);
        });
    }

    /**
     * Atomically locks affected stock records, verifies stock sufficiency,
     * creates StockMovement records, and updates WarehouseStock balances.
     *
     * @param array<array<string, mixed>> $planned
     * @return array<StockMovement>
     */
    protected function applyMovements(array $planned): array
    {
        if ($planned === []) {
            return [];
        }

        $now = Carbon::now();
        [$productsByWarehouse, $deltas] = $this->prepareSortedMovementPlan($planned);

        $this->ensureMissingStockRowsExist($productsByWarehouse, $now);
        $lockedStocks = $this->lockStocksAndVerifyStocktake($productsByWarehouse);
        $newBalancesByWarehouse = $this->computeValidatedBalances($deltas, $lockedStocks);

        return $this->persistMovementsAndUpdateStocks($planned, $newBalancesByWarehouse, $now);
    }

    /**
     * Validate positive quantities, aggregate net deltas, and sort (warehouse_id, product_id) keys deterministically.
     *
     * @param array<array<string, mixed>> $planned
     * @return array{0: array<int, array<int, int>>, 1: array<string, string>}
     */
    protected function prepareSortedMovementPlan(array $planned): array
    {
        $productsByWarehouse = [];
        $deltas = [];

        foreach ($planned as $m) {
            $whId = (int) $m['warehouse_id'];
            $prodId = (int) $m['product_id'];
            $qty = Decimal::fromNumeric($m['quantity'], Decimal::QUANTITY_SCALE);

            if (! Decimal::isPositive($qty, Decimal::QUANTITY_SCALE)) {
                throw ValidationException::withMessages([
                    'items' => ['Stock movement quantity must be greater than zero.'],
                ]);
            }

            $productsByWarehouse[$whId][$prodId] = $prodId;
            $key = $whId . '_' . $prodId;

            if (! isset($deltas[$key])) {
                $deltas[$key] = '0.000';
            }

            $deltas[$key] = $m['direction'] === StockMovementDirection::INBOUND
                ? Decimal::add($deltas[$key], $qty, Decimal::QUANTITY_SCALE)
                : Decimal::sub($deltas[$key], $qty, Decimal::QUANTITY_SCALE);
        }

        ksort($productsByWarehouse);
        foreach ($productsByWarehouse as $whId => $prodIdMap) {
            $prodIds = array_values($prodIdMap);
            sort($prodIds);
            $productsByWarehouse[$whId] = $prodIds;
        }

        return [$productsByWarehouse, $deltas];
    }

    /**
     * Ensure missing WarehouseStock rows exist without holding FOR UPDATE gap locks.
     * Locking missing parent Product rows in ascending ID order prevents InnoDB INSERT IGNORE deadlocks.
     *
     * @param array<int, array<int, int>> $productsByWarehouse
     */
    protected function ensureMissingStockRowsExist(array $productsByWarehouse, Carbon $now): void
    {
        $missingRows = [];
        $missingProdIds = [];

        foreach ($productsByWarehouse as $whId => $prodIds) {
            $existingIds = WarehouseStock::query()
                ->where('warehouse_id', $whId)
                ->whereIn('product_id', $prodIds)
                ->pluck('product_id')
                ->all();

            $existingMap = array_flip(array_map('intval', $existingIds));

            foreach ($prodIds as $prodId) {
                if (! isset($existingMap[$prodId])) {
                    $missingRows[] = [
                        'warehouse_id' => $whId,
                        'product_id' => $prodId,
                        'quantity' => '0.000',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $missingProdIds[$prodId] = $prodId;
                }
            }
        }

        if ($missingRows === []) {
            return;
        }

        $sortedMissingProdIds = array_values($missingProdIds);
        sort($sortedMissingProdIds);

        Product::query()
            ->whereIn('id', $sortedMissingProdIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        WarehouseStock::query()->insertOrIgnore($missingRows);
    }

    /**
     * Lock affected stock rows in deterministic (warehouse_id ASC, product_id ASC) order
     * and verify none are locked in COUNTING status by an active stocktake.
     *
     * @param array<int, array<int, int>> $productsByWarehouse
     * @return array<string, WarehouseStock>
     */
    protected function lockStocksAndVerifyStocktake(array $productsByWarehouse): array
    {
        $lockedStocks = [];

        foreach ($productsByWarehouse as $whId => $prodIds) {
            $stocks = WarehouseStock::query()
                ->where('warehouse_id', $whId)
                ->whereIn('product_id', $prodIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            if ($stocks->count() !== count($prodIds)) {
                $actualIds = array_map('intval', $stocks->keys()->all());
                $missingIds = array_values(array_diff($prodIds, $actualIds));

                throw new RuntimeException(
                    "Failed to lock warehouse_stocks for warehouse #{$whId}, products [" . implode(', ', $missingIds) . '].'
                );
            }

            $lockedProductId = StocktakeItem::query()
                ->whereIn('product_id', $prodIds)
                ->where('count_status', StocktakeItemStatus::COUNTING)
                ->whereHas('stocktake', function (Builder $q) use ($whId): void {
                    $q->where('warehouse_id', $whId)
                        ->whereIn('status', [
                            StocktakeStatus::IN_PROGRESS,
                            StocktakeStatus::PENDING_APPROVAL,
                            StocktakeStatus::APPROVED,
                            StocktakeStatus::REJECTED,
                        ]);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->value('product_id');

            if ($lockedProductId !== null) {
                $wh = Warehouse::query()->find($whId);
                $prod = Product::query()->find($lockedProductId);

                throw ValidationException::withMessages([
                    'stocktake_locked' => [
                        "المنتج '{$prod?->name}' في المخزن '{$wh?->name}' قيد الجرد حاليًا (جاري العد) ولا يمكن تنفيذ حركات مخزنية عليه حتى اكتمال العد.",
                    ],
                ]);
            }

            foreach ($prodIds as $prodId) {
                $lockedStocks[$whId . '_' . $prodId] = $stocks->get($prodId);
            }
        }

        return $lockedStocks;
    }

    /**
     * Validate every delta against its locked stock balance and compute non-negative balances.
     *
     * @param array<string, string> $deltas
     * @param array<string, WarehouseStock> $lockedStocks
     * @return array<int, array<int, string>>
     */
    protected function computeValidatedBalances(array $deltas, array $lockedStocks): array
    {
        $newBalancesByWarehouse = [];

        foreach ($deltas as $key => $delta) {
            $stock = $lockedStocks[$key] ?? null;
            if (! $stock instanceof WarehouseStock) {
                throw new RuntimeException("Missing locked warehouse stock record for key [{$key}].");
            }

            $currentQty = Decimal::fromNumeric($stock->quantity, Decimal::QUANTITY_SCALE);
            $projected = Decimal::add($currentQty, $delta, Decimal::QUANTITY_SCALE);

            if (Decimal::isNegative($projected, Decimal::QUANTITY_SCALE)) {
                $wh = Warehouse::query()->find($stock->warehouse_id);
                $prod = Product::query()->find($stock->product_id);
                $shortage = Decimal::mul($projected, '-1', Decimal::QUANTITY_SCALE);
                $requestedOutbound = Decimal::mul($delta, '-1', Decimal::QUANTITY_SCALE);

                throw ValidationException::withMessages([
                    'stock' => [
                        "الرصيد غير كافٍ للمنتج '{$prod?->name}' في المخزن '{$wh?->name}'. " .
                        "الرصيد الحالي: {$currentQty}، " .
                        "الكمية المطلوبة صرفها: {$requestedOutbound}، " .
                        "مقدار العجز: {$shortage}.",
                    ],
                ]);
            }

            $stock->quantity = $projected;
            $newBalancesByWarehouse[(int) $stock->warehouse_id][(int) $stock->product_id] = $projected;
        }

        return $newBalancesByWarehouse;
    }

    /**
     * Bulk insert movements, batch update WarehouseStock balances via SQL CASE, and fetch persisted models.
     *
     * @param array<array<string, mixed>> $planned
     * @param array<int, array<int, string>> $newBalancesByWarehouse
     * @return array<StockMovement>
     */
    protected function persistMovementsAndUpdateStocks(array $planned, array $newBalancesByWarehouse, Carbon $now): array
    {
        $movementRows = [];
        foreach ($planned as $m) {
            $dirVal = $m['direction'] instanceof StockMovementDirection ? $m['direction']->value : (string) $m['direction'];
            $movementRows[] = [
                'inventory_document_id' => (int) $m['inventory_document_id'],
                'inventory_document_item_id' => (int) $m['inventory_document_item_id'],
                'product_id' => (int) $m['product_id'],
                'warehouse_id' => (int) $m['warehouse_id'],
                'direction' => $dirVal,
                'quantity' => Decimal::fromNumeric($m['quantity'], Decimal::QUANTITY_SCALE),
                'movement_date' => $m['movement_date'],
                'reversal_of_id' => $m['reversal_of_id'] ?? null,
                'created_by' => $m['created_by'] ?? null,
                'created_at' => $now,
            ];
        }

        StockMovement::query()->insert($movementRows);

        foreach ($newBalancesByWarehouse as $whId => $balancesByProductId) {
            if ($balancesByProductId === []) {
                continue;
            }

            $cases = [];
            $bindings = [];
            foreach ($balancesByProductId as $prodId => $newQty) {
                $cases[] = 'WHEN ? THEN ?';
                $bindings[] = (int) $prodId;
                $bindings[] = $newQty;
            }
            $bindings[] = $now->toDateTimeString();
            $bindings[] = (int) $whId;
            foreach (array_keys($balancesByProductId) as $prodId) {
                $bindings[] = (int) $prodId;
            }

            $inPlaceholders = implode(', ', array_fill(0, count($balancesByProductId), '?'));
            $caseSql = 'CASE product_id ' . implode(' ', $cases) . ' END';

            DB::update(
                "UPDATE warehouse_stocks SET quantity = {$caseSql}, updated_at = ? WHERE warehouse_id = ? AND product_id IN ({$inPlaceholders})",
                $bindings
            );
        }

        $documentId = (int) $movementRows[0]['inventory_document_id'];
        $isReversal = $movementRows[0]['reversal_of_id'] !== null;

        return StockMovement::query()
            ->where('inventory_document_id', $documentId)
            ->when(
                $isReversal,
                fn (Builder $q) => $q->whereNotNull('reversal_of_id'),
                fn (Builder $q) => $q->whereNull('reversal_of_id')
            )
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Validate that all warehouses attached to this document exist.
     */
    protected function validateWarehousesExist(InventoryDocument $document): void
    {
        if ($document->source_warehouse_id) {
            $sourceWh = Warehouse::query()->find($document->source_warehouse_id);
            if (! $sourceWh) {
                throw ValidationException::withMessages([
                    'source_warehouse_id' => ['المخزن المصدر المحدد غير موجود.'],
                ]);
            }
        }

        if ($document->destination_warehouse_id) {
            $destWh = Warehouse::query()->find($document->destination_warehouse_id);
            if (! $destWh) {
                throw ValidationException::withMessages([
                    'destination_warehouse_id' => ['المخزن المستهدف المحدد غير موجود.'],
                ]);
            }
        }
    }

    /**
     * Build the list of movements to create for the document.
     *
     * @param Collection<int, InventoryDocumentItem> $items
     * @return array<array<string, mixed>>
     */
    protected function buildPlannedMovementsForDocument(InventoryDocument $document, Collection $items, int $userId): array
    {
        $planned = [];
        $type = $document->document_type;
        $docDate = $document->document_date->toDateString();

        foreach ($items as $item) {
            $qty = Decimal::fromNumeric($item->quantity, Decimal::QUANTITY_SCALE);

            if (! Decimal::isPositive($qty, Decimal::QUANTITY_SCALE)) {
                throw ValidationException::withMessages([
                    'items' => ["الكمية للصنف '{$item->product?->name}' يجب أن تكون أكبر من الصفر."],
                ]);
            }

            switch ($type) {
                case InventoryDocumentType::GOODS_RECEIPT:
                case InventoryDocumentType::OPENING_BALANCE:
                    $planned[] = $this->makeMovementPlan(
                        $document, $item, (int) $document->destination_warehouse_id,
                        StockMovementDirection::INBOUND, $qty, $docDate, $userId
                    );
                    break;

                case InventoryDocumentType::GOODS_DELIVERY:
                case InventoryDocumentType::STOCK_WRITE_OFF:
                    $planned[] = $this->makeMovementPlan(
                        $document, $item, (int) $document->source_warehouse_id,
                        StockMovementDirection::OUTBOUND, $qty, $docDate, $userId
                    );
                    break;

                case InventoryDocumentType::WAREHOUSE_TRANSFER:
                    if ($document->source_warehouse_id === $document->destination_warehouse_id) {
                        throw ValidationException::withMessages([
                            'destination_warehouse_id' => ['لا يمكن التحويل لنفس المخزن المصدر.'],
                        ]);
                    }

                    // Dual atomic movement: OUTBOUND from source + INBOUND to destination
                    $planned[] = $this->makeMovementPlan(
                        $document, $item, (int) $document->source_warehouse_id,
                        StockMovementDirection::OUTBOUND, $qty, $docDate, $userId
                    );
                    $planned[] = $this->makeMovementPlan(
                        $document, $item, (int) $document->destination_warehouse_id,
                        StockMovementDirection::INBOUND, $qty, $docDate, $userId
                    );
                    break;

                case InventoryDocumentType::STOCK_ADJUSTMENT:
                    if ($document->adjustment_direction === StockAdjustmentDirection::INCREASE) {
                        $planned[] = $this->makeMovementPlan(
                            $document, $item, (int) $document->destination_warehouse_id,
                            StockMovementDirection::INBOUND, $qty, $docDate, $userId
                        );
                    } else {
                        $planned[] = $this->makeMovementPlan(
                            $document, $item, (int) $document->source_warehouse_id,
                            StockMovementDirection::OUTBOUND, $qty, $docDate, $userId
                        );
                    }
                    break;

                case InventoryDocumentType::INVENTORY_RETURN:
                    if ($document->return_direction === InventoryReturnDirection::INBOUND) {
                        $planned[] = $this->makeMovementPlan(
                            $document, $item, (int) $document->destination_warehouse_id,
                            StockMovementDirection::INBOUND, $qty, $docDate, $userId
                        );
                    } else {
                        $planned[] = $this->makeMovementPlan(
                            $document, $item, (int) $document->source_warehouse_id,
                            StockMovementDirection::OUTBOUND, $qty, $docDate, $userId
                        );
                    }
                    break;
            }
        }

        return $planned;
    }

    /**
     * Helper to prepare single movement payload.
     *
     * @return array<string, mixed>
     */
    protected function makeMovementPlan(
        InventoryDocument $document,
        InventoryDocumentItem $item,
        int $warehouseId,
        StockMovementDirection $direction,
        string $quantity,
        string $date,
        int $userId
    ): array {
        if ($warehouseId <= 0) {
            throw ValidationException::withMessages([
                'warehouse' => ["لم يتم تحديد المخزن المطلوب للحركة المخزنية في المستند {$document->document_number}."],
            ]);
        }

        return [
            'inventory_document_id' => $document->id,
            'inventory_document_item_id' => $item->id,
            'product_id' => $item->product_id,
            'warehouse_id' => $warehouseId,
            'direction' => $direction,
            'quantity' => $quantity,
            'movement_date' => $date,
            'reversal_of_id' => null,
            'created_by' => $userId,
            'product' => $item->product,
        ];
    }

    /**
     * Get Stock Card / chronological movement history with running balance for a product in a warehouse.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     product: Product,
     *     warehouse: Warehouse,
     *     current_stock: string,
     *     movements: array<array<string, mixed>>
     * }
     */
    public function getStockCard(int $productId, int $warehouseId, array $filters = []): array
    {
        $product = Product::query()->findOrFail($productId);
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        $currentStock = $stock ? (string) $stock->quantity : '0.000';

        $query = StockMovement::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->with([
                'inventoryDocument:id,document_number,document_type,document_date,status',
                'creator:id,name',
            ])
            ->orderBy('movement_date', 'asc')
            ->orderBy('id', 'asc');

        if (! empty($filters['date_to'])) {
            $query->whereDate('movement_date', '<=', Carbon::parse($filters['date_to'])->toDateString());
        }

        $allMovements = $query->get();

        $runningBalance = '0.000';
        $timeline = [];

        $dateFromStr = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->toDateString() : null;
        $filterDir = ! empty($filters['direction']) ? strtolower(trim((string) $filters['direction'])) : null;

        foreach ($allMovements as $mov) {
            $qty = Decimal::fromNumeric($mov->quantity, Decimal::QUANTITY_SCALE);
            $dirVal = $mov->direction instanceof StockMovementDirection ? $mov->direction->value : (string) $mov->direction;

            if ($dirVal === StockMovementDirection::INBOUND->value) {
                $runningBalance = Decimal::add($runningBalance, $qty, Decimal::QUANTITY_SCALE);
                $inQty = $qty;
                $outQty = '0.000';
            } else {
                $runningBalance = Decimal::sub($runningBalance, $qty, Decimal::QUANTITY_SCALE);
                $inQty = '0.000';
                $outQty = $qty;
            }

            $movDateStr = $mov->movement_date->toDateString();

            // Apply date_from filter for presentation while keeping accumulated balance
            if ($dateFromStr && $movDateStr < $dateFromStr) {
                continue;
            }

            // Apply direction filter for presentation
            if ($filterDir && $dirVal !== $filterDir) {
                continue;
            }

            $timeline[] = [
                'id' => $mov->id,
                'movement_date' => $movDateStr,
                'created_at' => $mov->created_at?->toIso8601String(),
                'document_id' => $mov->inventory_document_id,
                'document_number' => $mov->inventoryDocument?->document_number,
                'document_type' => $mov->inventoryDocument?->document_type?->value,
                'direction' => $dirVal,
                'in_quantity' => $inQty,
                'out_quantity' => $outQty,
                'balance_after' => $runningBalance,
                'is_reversal' => ! is_null($mov->reversal_of_id),
                'reversal_of_id' => $mov->reversal_of_id,
                'created_by_name' => $mov->creator?->name,
            ];
        }

        return [
            'product' => $product,
            'warehouse' => $warehouse,
            'current_stock' => $currentStock,
            'movements' => $timeline,
        ];
    }

    /**
     * Paginated list of warehouse stocks with filtering.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateWarehouseStocks(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return WarehouseStock::query()
            ->with([
                'warehouse:id,code,name,is_default',
                'product:id,category_id,name,barcode,purchase_price,selling_price',
                'product.category:id,name',
            ])
            ->when(! empty($filters['warehouse_id']), function (Builder $q, $whId): void {
                $q->where('warehouse_id', (int) $whId);
            })
            ->when(! empty($filters['product_id']), function (Builder $q, $prodId): void {
                $q->where('product_id', (int) $prodId);
            })
            ->when(! empty($filters['search']), function (Builder $q, $search): void {
                $q->whereHas('product', function (Builder $sub) use ($search): void {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            })
            ->when(isset($filters['has_stock']) && $filters['has_stock'] !== '', function (Builder $q) use ($filters): void {
                if ((bool) $filters['has_stock']) {
                    $q->where('quantity', '>', 0);
                } else {
                    $q->where('quantity', '=', 0);
                }
            })
            ->orderBy($filters['sort_by'] ?? 'warehouse_id', $filters['sort_order'] ?? 'asc')
            ->paginate($perPage);
    }

    /**
     * Paginated audit log of historical stock movements.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateMovements(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return StockMovement::query()
            ->with([
                'warehouse:id,code,name',
                'product:id,name,barcode',
                'inventoryDocument:id,document_number,document_type,document_date,status',
                'creator:id,name',
            ])
            ->when(! empty($filters['warehouse_id']), function (Builder $q, $whId): void {
                $q->where('warehouse_id', (int) $whId);
            })
            ->when(! empty($filters['product_id']), function (Builder $q, $prodId): void {
                $q->where('product_id', (int) $prodId);
            })
            ->when(! empty($filters['direction']), function (Builder $q, $dir): void {
                $q->where('direction', $dir);
            })
            ->when(! empty($filters['document_id']), function (Builder $q, $docId): void {
                $q->where('inventory_document_id', (int) $docId);
            })
            ->when(! empty($filters['search']), function (Builder $q, $search): void {
                $q->where(function (Builder $sub) use ($search): void {
                    $sub->whereHas('inventoryDocument', function (Builder $d) use ($search): void {
                        $d->where('document_number', 'like', "%{$search}%");
                    })->orWhereHas('product', function (Builder $p) use ($search): void {
                        $p->where('name', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
                });
            })
            ->when(! empty($filters['date_from']), function (Builder $q, $from): void {
                $q->whereDate('movement_date', '>=', Carbon::parse($from)->toDateString());
            })
            ->when(! empty($filters['date_to']), function (Builder $q, $to): void {
                $q->whereDate('movement_date', '<=', Carbon::parse($to)->toDateString());
            })
            ->orderBy('movement_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }
}
