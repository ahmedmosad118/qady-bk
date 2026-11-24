<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductRepository
{
    /**
     * Build the base filtered query for listing and exporting products.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Product>
     */
    public function filteredQuery(array $filters = []): Builder
    {
        $query = Product::query()
            ->with('category:id,name,parent_id')
            ->withSum('warehouseStocks', 'quantity');

        if (! empty($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $filters['ids'])));
            $query->whereIn('id', array_slice($ids, 0, 200));
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('publisher', 'like', "%{$search}%");
            });
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '') {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (isset($filters['show_in_shop']) && $filters['show_in_shop'] !== '') {
            $query->where('show_in_shop', filter_var($filters['show_in_shop'], FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['name', 'barcode', 'selling_price', 'purchase_price', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'id';

        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortOrder);
    }

    /**
     * Get paginated products with eager-loaded relations and applied filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $limit = min(max($perPage, 1), 100);

        return $this->filteredQuery($filters)->paginate($limit);
    }

    /**
     * Aggregate counters used by the products dashboard cards.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    public function stats(): array
    {
        $row = Product::query()
            ->selectRaw(
                'COUNT(*) as total, '
                .'COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active, '
                .'COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) as inactive'
            )
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active ?? 0),
            'inactive' => (int) ($row->inactive ?? 0),
        ];
    }

    /**
     * Find a product by id.
     */
    public function findById(int $id): ?Product
    {
        return Product::with('category:id,name,parent_id')->find($id);
    }
}
