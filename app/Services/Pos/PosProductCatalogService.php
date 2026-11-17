<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only product lookups for the POS screen (catalog grid + barcode scan).
 * Stock is always scoped to the default warehouse.
 */
class PosProductCatalogService
{
    public function __construct(
        protected PosDefaultWarehouseResolver $warehouseResolver,
    ) {}

    /**
     * Paginate products by name/barcode, optionally within a category and its direct children.
     */
    public function paginateProducts(string $search = '', ?int $categoryId = null, int $perPage = 24): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if ($categoryId !== null) {
            // Single query: category itself OR its direct children (subquery, no extra round trip).
            $query->where(function (Builder $q) use ($categoryId): void {
                $q->where('category_id', $categoryId)
                    ->orWhereIn('category_id', ProductCategory::query()->select('id')->where('parent_id', $categoryId));
            });
        }

        $term = trim($search);
        if ($term !== '') {
            $query->where(function (Builder $q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%");
            });
        }

        return $query->orderBy('name')->paginate($perPage);
    }

    /**
     * Exact barcode lookup (uses the products.barcode index).
     */
    public function lookupBarcode(string $barcode): ?Product
    {
        return $this->baseQuery()->where('barcode', $barcode)->first();
    }

    /**
     * @return Builder<Product>
     */
    protected function baseQuery(): Builder
    {
        $warehouseId = $this->warehouseResolver->resolve()->id;

        return Product::query()->with([
            'category:id,name',
            'warehouseStocks' => fn ($q) => $q->where('warehouse_id', $warehouseId),
        ]);
    }
}
