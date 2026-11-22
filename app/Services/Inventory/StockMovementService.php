<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\StockAdjustmentDirection;
use App\Enums\StockMovementDirection;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class StockMovementService
{
    /**
     * Attempts for transactions that can lose a lock race (InnoDB deadlock => automatic retry).
     */
    public const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        protected StockBalanceApplier $balanceApplier,
        protected StockMovementQueryService $queryService,
    ) {}

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
            // Lock order: purchase invoice FIRST, then the inventory document (same order as
            // PurchaseInvoiceService update/cancel/delete) so the two flows can never deadlock.
            $this->lockLinkedPurchaseInvoice($document);

            /** @var InventoryDocument $lockedDocument */
            $lockedDocument = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            // 1. Validate status
            if ($lockedDocument->status !== InventoryDocumentStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('inventory.errors.cannot_post_status', ['status' => $lockedDocument->status->value])],
                ]);
            }

            // 2. Validate items exist
            $items = $lockedDocument->items()->with('product')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('inventory.errors.post_no_items')],
                ]);
            }

            // 3. Prevent duplicate posting / idempotency under document row lock
            if ($lockedDocument->stockMovements()->whereNull('reversal_of_id')->exists()) {
                throw new LogicException((string) __('inventory.errors.already_posted', ['number' => $lockedDocument->document_number]));
            }

            // 4. Validate warehouses exist
            $this->validateWarehousesActive($lockedDocument);

            // 4b. Validate Purchase Invoice Goods Receipt completeness & status
            if (in_array($lockedDocument->referenceable_type, ['purchase_invoice', PurchaseInvoice::class], true)) {
                $this->validatePurchaseInvoiceGoodsReceipt($lockedDocument, $items);
            }

            // 5. Build planned movements
            $plannedMovements = $this->buildPlannedMovementsForDocument($lockedDocument, $items, $userId);

            // 6. Lock and update stocks within the transaction
            $movements = $this->applyMovements($plannedMovements);

            $movementCollection = new Collection($movements);
            $lockedDocument->setRelation('stockMovements', $movementCollection);
            $document->setRelation('stockMovements', $movementCollection);

            return $movements;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Lock the purchase invoice a goods receipt belongs to (no-op for any other document).
     *
     * Global lock order for invoice-linked flows is: purchase invoice -> inventory document.
     * Call this BEFORE locking the document. Re-locking inside the same transaction is harmless.
     */
    public function lockLinkedPurchaseInvoice(InventoryDocument $document): ?PurchaseInvoice
    {
        if (! $document->referenceable_id
            || ! in_array($document->referenceable_type, ['purchase_invoice', PurchaseInvoice::class], true)) {
            return null;
        }

        return PurchaseInvoice::query()->lockForUpdate()->find($document->referenceable_id);
    }

    /**
     * Ensure a Goods Receipt linked to a Purchase Invoice receives the complete invoice quantities in one operation.
     *
     * @param  Collection<int, InventoryDocumentItem>  $docItems
     */
    protected function validatePurchaseInvoiceGoodsReceipt(InventoryDocument $document, Collection $docItems): void
    {
        if (! $document->referenceable_id) {
            throw ValidationException::withMessages([
                'referenceable_id' => [(string) __('inventory.errors.gr_invoice_not_linked')],
            ]);
        }

        /** @var PurchaseInvoice|null $invoice */
        $invoice = PurchaseInvoice::query()
            ->with('items')
            ->lockForUpdate()
            ->find($document->referenceable_id);

        if (! $invoice) {
            throw ValidationException::withMessages([
                'referenceable_id' => [(string) __('inventory.errors.gr_invoice_not_found')],
            ]);
        }

        if ($invoice->status === PurchaseInvoiceStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => [(string) __('inventory.errors.gr_post_cancelled_invoice')],
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
                'items' => [(string) __('inventory.errors.gr_partial_items')],
            ]);
        }

        foreach ($invoiceQtyByProduct as $productId => $expectedQty) {
            if (! isset($docQtyByProduct[$productId]) || Decimal::comp($docQtyByProduct[$productId], $expectedQty, Decimal::QUANTITY_SCALE) !== 0) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('inventory.errors.gr_partial_quantities')],
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

            if ($lockedDocument->status !== InventoryDocumentStatus::POSTED) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('inventory.errors.cannot_reverse_status', ['status' => $lockedDocument->status->value])],
                ]);
            }

            /** @var Collection<int, StockMovement> $originalMovements */
            $originalMovements = $lockedDocument->stockMovements()
                ->whereNull('reversal_of_id')
                ->with(['product', 'warehouse'])
                ->lockForUpdate()
                ->get();

            if ($originalMovements->isEmpty()) {
                throw ValidationException::withMessages([
                    'movements' => [(string) __('inventory.errors.reverse_no_movements')],
                ]);
            }

            // Ensure no movement has already been reversed under row lock
            $alreadyReversed = StockMovement::query()
                ->whereIn('reversal_of_id', $originalMovements->pluck('id')->all())
                ->lockForUpdate()
                ->exists();

            if ($alreadyReversed) {
                throw new LogicException((string) __('inventory.errors.already_reversed'));
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
     * @param  array<array<string, mixed>>  $planned
     * @return array<StockMovement>
     */
    protected function applyMovements(array $planned): array
    {
        return $this->balanceApplier->apply($planned)->all();
    }

    /**
     * Validate that all warehouses attached to this document exist.
     */
    protected function validateWarehousesActive(InventoryDocument $document): void
    {
        $warehouseIds = array_values(array_unique(array_filter([
            $document->source_warehouse_id,
            $document->destination_warehouse_id,
        ])));

        if ($warehouseIds === []) {
            return;
        }

        $foundIds = Warehouse::query()
            ->whereIn('id', $warehouseIds)
            ->pluck('id')
            ->all();

        if ($document->source_warehouse_id && ! in_array($document->source_warehouse_id, $foundIds, true)) {
            throw ValidationException::withMessages([
                'source_warehouse_id' => [(string) __('inventory.errors.source_warehouse_missing')],
            ]);
        }

        if ($document->destination_warehouse_id && ! in_array($document->destination_warehouse_id, $foundIds, true)) {
            throw ValidationException::withMessages([
                'destination_warehouse_id' => [(string) __('inventory.errors.destination_warehouse_missing')],
            ]);
        }
    }

    /**
     * Build the list of movements to create for the document.
     *
     * @param  Collection<int, InventoryDocumentItem>  $items
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
                    'items' => [(string) __('inventory.errors.item_quantity_positive', ['name' => (string) $item->product?->name])],
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
                            'destination_warehouse_id' => [(string) __('inventory.errors.transfer_warehouses_must_differ')],
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
                'warehouse' => [(string) __('inventory.errors.movement_warehouse_unspecified', ['number' => $document->document_number])],
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
     * @param  array<string, mixed>  $filters
     * @return array{
     *     product: Product,
     *     warehouse: Warehouse,
     *     current_stock: string,
     *     movements: array<array<string, mixed>>
     * }
     */
    public function getStockCard(int $productId, int $warehouseId, array $filters = []): array
    {
        return $this->queryService->getStockCard($productId, $warehouseId, $filters);
    }

    /**
     * Paginated list of warehouse stocks with filtering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateWarehouseStocks(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryService->paginateWarehouseStocks($filters, $perPage);
    }

    /**
     * Paginated audit log of historical stock movements.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateMovements(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryService->paginateMovements($filters, $perPage);
    }
}
