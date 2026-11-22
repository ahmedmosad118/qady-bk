<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StockMovementDirection;
use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeStatus;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StocktakeItem;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockBalanceApplier
{
    /**
     * Atomically lock stock rows, validate stocktake & non-negative invariants,
     * bulk-insert StockMovement rows, and bulk-update WarehouseStock balances.
     *
     * @param  array<int, array<string, mixed>>  $planned
     * @return Collection<int, StockMovement>
     */
    public function apply(array $planned): Collection
    {
        if (empty($planned)) {
            return new Collection;
        }

        // 1. Group distinct product IDs by warehouse_id (sorted deterministically to prevent deadlocks)
        $productsByWarehouse = [];
        foreach ($planned as $m) {
            $whId = (int) $m['warehouse_id'];
            $prodId = (int) $m['product_id'];
            $productsByWarehouse[$whId][$prodId] = $prodId;
        }

        ksort($productsByWarehouse);

        // 2. Batch-ensure stock rows exist, batch-lock them with lockForUpdate(), and batch-check stocktake locks per warehouse
        $lockedStocks = [];
        $now = Carbon::now();

        foreach ($productsByWarehouse as $whId => $prodIdMap) {
            $prodIds = array_values($prodIdMap);
            sort($prodIds);

            $stocks = WarehouseStock::query()
                ->where('warehouse_id', $whId)
                ->whereIn('product_id', $prodIds)
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            if ($stocks->count() < count($prodIds)) {
                $missingRows = [];
                foreach ($prodIds as $prodId) {
                    if (! $stocks->has($prodId)) {
                        $missingRows[] = [
                            'warehouse_id' => $whId,
                            'product_id' => $prodId,
                            'quantity' => '0.000',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($missingRows !== []) {
                    WarehouseStock::query()->insertOrIgnore($missingRows);
                }

                $stocks = WarehouseStock::query()
                    ->where('warehouse_id', $whId)
                    ->whereIn('product_id', $prodIds)
                    ->orderBy('product_id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('product_id');
            }

            // Batch-check if any product in this warehouse is locked in COUNTING status in an active stocktake (1 locked query)
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
                ->lockForUpdate()
                ->value('product_id');

            if ($lockedProductId !== null) {
                $wh = Warehouse::query()->find($whId);
                $prod = Product::query()->find($lockedProductId);

                throw ValidationException::withMessages([
                    'stocktake_locked' => [
                        (string) __('inventory.errors.stocktake_locked', [
                            'product' => (string) $prod?->name,
                            'warehouse' => (string) $wh?->name,
                        ]),
                    ],
                ]);
            }

            foreach ($stocks as $prodId => $stock) {
                $lockedStocks[$whId.'_'.$prodId] = $stock;
            }
        }

        // 3. Calculate net delta per stock row and verify against negative stock using Decimal
        $deltas = [];
        foreach ($planned as $m) {
            $key = $m['warehouse_id'].'_'.$m['product_id'];
            $qty = Decimal::fromNumeric($m['quantity'], Decimal::QUANTITY_SCALE);

            if (! isset($deltas[$key])) {
                $deltas[$key] = '0.000';
            }

            if ($m['direction'] === StockMovementDirection::INBOUND) {
                $deltas[$key] = Decimal::add($deltas[$key], $qty, Decimal::QUANTITY_SCALE);
            } else {
                $deltas[$key] = Decimal::sub($deltas[$key], $qty, Decimal::QUANTITY_SCALE);
            }
        }

        $newBalancesByWarehouse = [];
        foreach ($lockedStocks as $key => $stock) {
            $currentQty = Decimal::fromNumeric($stock->quantity, Decimal::QUANTITY_SCALE);
            $delta = $deltas[$key] ?? '0.000';
            $projected = Decimal::add($currentQty, $delta, Decimal::QUANTITY_SCALE);

            if (Decimal::isNegative($projected, Decimal::QUANTITY_SCALE)) {
                $wh = Warehouse::query()->find($stock->warehouse_id);
                $prod = Product::query()->find($stock->product_id);

                $shortage = Decimal::mul($projected, '-1', Decimal::QUANTITY_SCALE);
                $requestedOutbound = Decimal::mul($delta, '-1', Decimal::QUANTITY_SCALE);

                throw ValidationException::withMessages([
                    'stock' => [
                        (string) __('inventory.errors.insufficient_stock', [
                            'product' => (string) $prod?->name,
                            'warehouse' => (string) $wh?->name,
                            'current' => $currentQty,
                            'requested' => $requestedOutbound,
                            'shortage' => $shortage,
                        ]),
                    ],
                ]);
            }

            $stock->quantity = $projected;
            $newBalancesByWarehouse[(int) $stock->warehouse_id][(int) $stock->product_id] = $projected;
        }

        // 4. Bulk insert StockMovement rows in 1 query
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

        // 5. Batch update WarehouseStock balances in 1 CASE query per warehouse
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
            $caseSql = 'CASE product_id '.implode(' ', $cases).' END';

            DB::update(
                "UPDATE warehouse_stocks SET quantity = {$caseSql}, updated_at = ? WHERE warehouse_id = ? AND product_id IN ({$inPlaceholders})",
                $bindings
            );
        }

        // 6. Query back the persisted StockMovement records in 1 query so returned models have real DB IDs
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
            ->get();
    }
}
