<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ProductRepository
{
    /**
     * Get paginated products with eager-loaded relations and applied filters.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->with('category:id,name,parent_id')
            ->withSum('warehouseStocks', 'quantity');

        if (!empty($filters['ids'])) {
            $ids = array_values(array_filter(array_map('intval', (array) $filters['ids'])));
            $query->whereIn('id', array_slice($ids, 0, 200));
        }

        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('author', 'like', "%{$search}%")
                    ->orWhere('publisher', 'like', "%{$search}%");
            });
        }

        if (isset($filters['category_id']) && $filters['category_id'] !== '' && $filters['category_id'] !== null) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['name', 'barcode', 'selling_price', 'purchase_price', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'id';

        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Aggregate counters used by the products dashboard cards.
     *
     * @return array{total: int, active: int, inactive: int}
     */
    public function stats(): array
    {
        return [
            'total' => Product::query()->count(),
            'active' => Product::query()->where('is_active', true)->count(),
            'inactive' => Product::query()->where('is_active', false)->count(),
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
