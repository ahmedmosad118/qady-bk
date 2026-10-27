<?php

namespace App\Models;

use App\Services\ProductSkuGenerator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'category_id', 'name', 'sku', 'barcode', 'author', 'publisher',
    'edition', 'publication_year', 'cover_image',
    'purchase_price', 'selling_price', 'is_active',
])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The "products" table holds the SKU column and uses it as a regular
     * (non-incrementing) attribute; keep the primary key on "id".
     */
    public const SKU_PREFIX = 'LBK-';

    /**
     * The book category this edition belongs to (may be uncategorized).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * Scope to the active products only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (blank($product->sku)) {
                $product->sku = ProductSkuGenerator::next();
            }
        });
    }
}
