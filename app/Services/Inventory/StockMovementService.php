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
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class StockMovementService
{
    /**
     * Post an approved inventory document:
     * 1. Validates document is approved and has items.
     * 2. Resolves destination/source warehouse and direction for each item line.
     * 3. Locks all affected warehouse_stocks rows in deterministic order.
     * 4. Enforces strict non-negative stock rules.
     * 5. Creates immutable stock_movements.
     * 6. Updates current warehouse_stocks balances.
     *
     * @return array<StockMovement>
     */
    public function postDocumentMovements(InventoryDocument $document, int $userId): array
    {
        // 1. Validate status
        if ($document->status !== InventoryDocumentStatus::APPROVED) {
            throw ValidationException::withMessages([
                'status' => ["لا يمكن ترحيل المستند وهو في حالة '{$document->status->value}'. يجب أن يكون معتمداً أولاً."],
            ]);
        }

        // 2. Validate items exist
        $items = $document->items()->with('product')->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['المستند لا يحتوي على أية بنود أو أصناف للترحيل.'],
            ]);
        }

        // 3. Prevent duplicate posting / idempotency
        if ($document->stockMovements()->exists()) {
            throw new LogicException("المستند {$document->document_number} تم ترحيله مسبقاً وسجل حركاته موجود بالفعل.");
        }

        // 4. Validate warehouses are active
        $this->validateWarehousesActive($document);

        // 4b. Validate Purchase Invoice Goods Receipt completeness & status
        if (in_array($document->referenceable_type, ['purchase_invoice', PurchaseInvoice::class], true)) {
            $this->validatePurchaseInvoiceGoodsReceipt($document, $items);
        }

        // 5. Build planned movements
        $plannedMovements = $this->buildPlannedMovementsForDocument($document, $items, $userId);

        // 6. Lock and update stocks within the outer transaction
        return $this->applyMovements($plannedMovements);
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
            $invoiceQtyByProduct[(int) $invItem->product_id] = number_format((float) $invItem->quantity, 3, '.', '');
        }

        $docQtyByProduct = [];
        foreach ($docItems as $docItem) {
            $pid = (int) $docItem->product_id;
            $qty = number_format((float) $docItem->quantity, 3, '.', '');
            $docQtyByProduct[$pid] = isset($docQtyByProduct[$pid])
                ? bcadd($docQtyByProduct[$pid], $qty, 3)
                : $qty;
        }

        if (count($invoiceQtyByProduct) !== count($docQtyByProduct)) {
            throw ValidationException::withMessages([
                'items' => ['Partial goods receipt is not supported; all purchase invoice items and quantities must be received together.'],
            ]);
        }

        foreach ($invoiceQtyByProduct as $productId => $expectedQty) {
            if (! isset($docQtyByProduct[$productId]) || bccomp($docQtyByProduct[$productId], $expectedQty, 3) !== 0) {
                throw ValidationException::withMessages([
                    'items' => ['Partial goods receipt is not supported; goods receipt quantities must match purchase invoice quantities.'],
                ]);
            }
        }
    }

    /**
     * Reverse a posted inventory document:
     * 1. Validates document is posted.
     * 2. Loads all original non-reversal movements.
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
        if ($document->status !== InventoryDocumentStatus::POSTED) {
            throw ValidationException::withMessages([
                'status' => ["لا يمكن عكس المستند وهو في حالة '{$document->status->value}'. يمكن عكس المستندات المرحّلة فقط."],
            ]);
        }

        /** @var Collection<int, StockMovement> $originalMovements */
        $originalMovements = $document->stockMovements()
            ->whereNull('reversal_of_id')
            ->with(['product', 'warehouse'])
            ->get();

        if ($originalMovements->isEmpty()) {
            throw ValidationException::withMessages([
                'movements' => ['لم يتم العثور على أية حركات مخزنية أصلية لعكس هذا المستند.'],
            ]);
        }

        // Ensure no movement has already been reversed
        $alreadyReversedCount = StockMovement::query()
            ->whereIn('reversal_of_id', $originalMovements->pluck('id'))
            ->count();

        if ($alreadyReversedCount > 0) {
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
                'quantity' => (string) $orig->quantity,
                'movement_date' => $today,
                'reversal_of_id' => $orig->id,
                'created_by' => $userId,
                'product' => $orig->product,
                'warehouse' => $orig->warehouse,
            ];
        }

        return $this->applyMovements($plannedReversals);
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
        // 1. Collect distinct warehouse-product pairs
        $pairs = [];
        foreach ($planned as $m) {
            $key = $m['warehouse_id'] . '_' . $m['product_id'];
            if (! isset($pairs[$key])) {
                $pairs[$key] = [
                    'warehouse_id' => (int) $m['warehouse_id'],
                    'product_id' => (int) $m['product_id'],
                ];
            }
        }

        // 2. Sort pairs deterministically by warehouse_id ASC, product_id ASC to prevent deadlocks
        usort($pairs, function (array $a, array $b): int {
            return ($a['warehouse_id'] <=> $b['warehouse_id'])
                ?: ($a['product_id'] <=> $b['product_id']);
        });

        // 3. Ensure stock rows exist and lock them with lockForUpdate()
        $lockedStocks = [];
        foreach ($pairs as $pair) {
            $whId = $pair['warehouse_id'];
            $prodId = $pair['product_id'];

            // Concurrency-safe initialization
            $this->ensureStockRowExists($whId, $prodId);

            /** @var WarehouseStock $stock */
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $whId)
                ->where('product_id', $prodId)
                ->lockForUpdate()
                ->firstOrFail();

            // Check if this product is currently locked in COUNTING status in an active stocktake
            $isLockedForStocktake = StocktakeItem::query()
                ->where('product_id', $prodId)
                ->where('count_status', StocktakeItemStatus::COUNTING)
                ->whereHas('stocktake', function ($q) use ($whId) {
                    $q->where('warehouse_id', $whId)
                        ->whereIn('status', [
                            StocktakeStatus::IN_PROGRESS,
                            StocktakeStatus::PENDING_APPROVAL,
                            StocktakeStatus::APPROVED,
                            StocktakeStatus::REJECTED,
                        ]);
                })
                ->exists();

            if ($isLockedForStocktake) {
                $wh = Warehouse::query()->find($whId);
                $prod = Product::query()->find($prodId);

                throw ValidationException::withMessages([
                    'stocktake_locked' => [
                        "المنتج '{$prod?->name}' في المخزن '{$wh?->name}' قيد الجرد حاليًا (جاري العد) ولا يمكن تنفيذ حركات مخزنية عليه حتى اكتمال العد."
                    ],
                ]);
            }

            $lockedStocks[$whId . '_' . $prodId] = $stock;
        }

        // 4. Calculate net delta per stock row and verify against negative stock
        $deltas = [];
        foreach ($planned as $m) {
            $key = $m['warehouse_id'] . '_' . $m['product_id'];
            $qty = (string) $m['quantity'];

            if (! isset($deltas[$key])) {
                $deltas[$key] = '0.000';
            }

            if ($m['direction'] === StockMovementDirection::INBOUND) {
                $deltas[$key] = bcadd($deltas[$key], $qty, 3);
            } else {
                $deltas[$key] = bcsub($deltas[$key], $qty, 3);
            }
        }

        // Check each locked stock against its calculated delta
        foreach ($lockedStocks as $key => $stock) {
            $currentQty = (string) $stock->quantity;
            $delta = $deltas[$key] ?? '0.000';
            $projected = bcadd($currentQty, $delta, 3);

            if (bccomp($projected, '0.000', 3) < 0) {
                // Find details for a clear, actionable exception message
                $wh = Warehouse::query()->find($stock->warehouse_id);
                $prod = Product::query()->find($stock->product_id);

                $shortage = bcmul(bcsub($projected, '0.000', 3), '-1', 3);
                $requestedOutbound = bcmul($delta, '-1', 3);

                throw ValidationException::withMessages([
                    'stock' => [
                        "الرصيد غير كافٍ للمنتج '{$prod?->name}' في المخزن '{$wh?->name}'. " .
                        "الرصيد الحالي: " . number_format((float) $currentQty, 3) . "، " .
                        "الكمية المطلوبة صرفها: " . number_format((float) $requestedOutbound, 3) . "، " .
                        "مقدار العجز: " . number_format((float) $shortage, 3) . "."
                    ],
                ]);
            }
        }

        // 5. Create movements and update warehouse stocks
        $createdMovements = [];
        foreach ($planned as $m) {
            $movement = new StockMovement();
            $movement->inventory_document_id = $m['inventory_document_id'];
            $movement->inventory_document_item_id = $m['inventory_document_item_id'];
            $movement->product_id = $m['product_id'];
            $movement->warehouse_id = $m['warehouse_id'];
            $movement->direction = $m['direction'];
            $movement->quantity = $m['quantity'];
            $movement->movement_date = $m['movement_date'];
            $movement->reversal_of_id = $m['reversal_of_id'] ?? null;
            $movement->created_by = $m['created_by'] ?? null;
            $movement->save();

            $createdMovements[] = $movement;

            // Update balance
            $key = $m['warehouse_id'] . '_' . $m['product_id'];
            $stock = $lockedStocks[$key];

            if ($m['direction'] === StockMovementDirection::INBOUND) {
                $stock->quantity = bcadd((string) $stock->quantity, (string) $m['quantity'], 3);
            } else {
                $stock->quantity = bcsub((string) $stock->quantity, (string) $m['quantity'], 3);
            }
            $stock->save();
        }

        return $createdMovements;
    }

    /**
     * Safely ensures a WarehouseStock record exists for warehouse and product,
     * handling potential race conditions gracefully.
     */
    protected function ensureStockRowExists(int $warehouseId, int $productId): void
    {
        $exists = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->exists();

        if (! $exists) {
            try {
                WarehouseStock::query()->firstOrCreate(
                    ['warehouse_id' => $warehouseId, 'product_id' => $productId],
                    ['quantity' => '0.000']
                );
            } catch (\Throwable) {
                // Record created concurrently by another transaction; safe to continue
            }
        }
    }

    /**
     * Validate that all warehouses attached to this document are active.
     */
    protected function validateWarehousesActive(InventoryDocument $document): void
    {
        if ($document->source_warehouse_id) {
            $sourceWh = Warehouse::query()->find($document->source_warehouse_id);
            if (! $sourceWh || ! $sourceWh->is_active) {
                throw ValidationException::withMessages([
                    'source_warehouse_id' => ["المخزن المصدر '{$sourceWh?->name}' معطل وغير نشط، ولا يمكن الترحيل منه."],
                ]);
            }
        }

        if ($document->destination_warehouse_id) {
            $destWh = Warehouse::query()->find($document->destination_warehouse_id);
            if (! $destWh || ! $destWh->is_active) {
                throw ValidationException::withMessages([
                    'destination_warehouse_id' => ["المخزن المستهدف '{$destWh?->name}' معطل وغير نشط، ولا يمكن الترحيل إليه."],
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
            $qty = (string) $item->quantity;

            if (bccomp($qty, '0.000', 3) <= 0) {
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
            $qty = (string) $mov->quantity;
            $dirVal = $mov->direction instanceof StockMovementDirection ? $mov->direction->value : (string) $mov->direction;

            if ($dirVal === StockMovementDirection::INBOUND->value) {
                $runningBalance = bcadd($runningBalance, $qty, 3);
                $inQty = $qty;
                $outQty = '0.000';
            } else {
                $runningBalance = bcsub($runningBalance, $qty, 3);
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
                'warehouse:id,code,name,is_default,is_active',
                'product:id,category_id,name,barcode,purchase_price,selling_price,is_active',
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
