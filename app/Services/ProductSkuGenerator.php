<?php

namespace App\Services;

use App\Models\Product;

class ProductSkuGenerator
{
    /**
     * Generate the next sequential internal SKU (e.g. LBK-0001, LBK-0002).
     *
     * Existing rows that do not follow the LBK-#### pattern are ignored, so a
     * manually entered SKU never breaks sequential generation.
     */
    public static function next(): string
    {
        $last = Product::withTrashed()
            ->where('sku', 'like', Product::SKU_PREFIX.'%')
            ->orderByDesc('id')
            ->value('sku');

        $nextNumber = 1;

        if ($last !== null) {
            $suffix = substr($last, strlen(Product::SKU_PREFIX));
            if (ctype_digit($suffix)) {
                $nextNumber = ((int) $suffix) + 1;
            }
        }

        return Product::SKU_PREFIX.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
