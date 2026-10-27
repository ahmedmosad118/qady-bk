<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class ProductService
{
    /**
     * Create a new product. SKU is auto-generated if blank.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = new Product();
            $product->fill($data);
            $product->save();

            return $product->fresh(['category:id,name,parent_id']);
        });
    }

    /**
     * Update an existing product.
     *
     * @param array<string, mixed> $data
     */
    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update($data);

            return $product->fresh(['category:id,name,parent_id']);
        });
    }

    /**
     * Delete products by IDs.
     *
     * @param array<int|string> $ids
     */
    public function delete(array $ids): int
    {
        $idArray = array_values(array_filter(array_map('intval', $ids)));

        return DB::transaction(function () use ($idArray) {
            return Product::whereIn('id', $idArray)->delete();
        });
    }
}
