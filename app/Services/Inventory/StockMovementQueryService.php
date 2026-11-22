<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StockMovementDirection;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StockMovementQueryService
{
    /**
     * Get Stock Card / chronological movement history with running balance for a product in a warehouse.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     product: Product,
     *     warehouse: Warehouse,
     *     current_stock: string,
     *     opening_balance: string,
     *     movements: array<int, array<string, mixed>>
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

        $dateFromStr = ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'])->toDateString() : null;
        $dateToStr = ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'])->toDateString() : null;
        $filterDir = ! empty($filters['direction']) ? strtolower(trim((string) $filters['direction'])) : null;

        $openingBalance = '0.000';
        if ($dateFromStr !== null) {
            $openingRaw = StockMovement::query()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->whereDate('movement_date', '<', $dateFromStr)
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN direction = ? THEN quantity ELSE -quantity END), 0) as net_qty',
                    [StockMovementDirection::INBOUND->value]
                )
                ->value('net_qty');

            $openingBalance = Decimal::fromNumeric($openingRaw ?? 0, Decimal::QUANTITY_SCALE);
        }

        $query = StockMovement::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->with([
                'inventoryDocument:id,document_number,document_type,document_date,status',
                'creator:id,name',
            ])
            ->orderBy('movement_date', 'asc')
            ->orderBy('id', 'asc');

        if ($dateFromStr !== null) {
            $query->whereDate('movement_date', '>=', $dateFromStr);
        }

        if ($dateToStr !== null) {
            $query->whereDate('movement_date', '<=', $dateToStr);
        }

        if ($filterDir !== null && $filterDir !== '') {
            $movements = $query->limit(500)->get();
        } else {
            $perPage = min(max((int) ($filters['per_page'] ?? 200), 1), 500);
            $movements = $query->paginate($perPage)->items();
        }

        $runningBalance = $openingBalance;
        $timeline = [];

        foreach ($movements as $mov) {
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

            if ($filterDir && $dirVal !== $filterDir) {
                continue;
            }

            $timeline[] = [
                'id' => $mov->id,
                'movement_date' => $mov->movement_date->toDateString(),
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
            'opening_balance' => $openingBalance,
            'movements' => $timeline,
        ];
    }

    /**
     * Paginated list of warehouse stocks with filtering.
     *
     * @param  array<string, mixed>  $filters
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
            ->orderBy(
                in_array($filters['sort_by'] ?? null, ['id', 'warehouse_id', 'product_id', 'quantity', 'created_at', 'updated_at'], true)
                    ? (string) $filters['sort_by']
                    : 'warehouse_id',
                strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc'
            )
            ->paginate($perPage);
    }

    /**
     * Paginated audit log of historical stock movements.
     *
     * @param  array<string, mixed>  $filters
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
