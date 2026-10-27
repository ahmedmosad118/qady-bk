<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCategoryService
{
    /**
     * Get the 2-level taxonomy tree.
     */
    public function getTree(): Collection
    {
        return ProductCategory::query()
            ->whereNull('parent_id')
            ->with([
                'children' => fn ($q) => $q->orderBy('name'),
                'products' => fn ($q) => $q->active(),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a category.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): ProductCategory
    {
        return DB::transaction(fn () => ProductCategory::create($data));
    }

    /**
     * Update a category.
     *
     * @param array<string, mixed> $data
     */
    public function update(ProductCategory $category, array $data): ProductCategory
    {
        return DB::transaction(function () use ($category, $data) {
            $category->update($data);

            return $category->fresh();
        });
    }

    /**
     * Delete categories by IDs, refusing any category that still has subcategories.
     *
     * @param array<int> $ids
     */
    public function delete(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $withChildren = ProductCategory::whereIn('id', $ids)->whereHas('children')->exists();

            if ($withChildren) {
                throw ValidationException::withMessages([
                    'category' => [__('products.delete_has_children')],
                ]);
            }

            return ProductCategory::whereIn('id', $ids)->delete();
        });
    }
}
