<?php

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'category_id', 'name', 'barcode', 'author', 'publisher',
    'edition', 'publication_year', 'cover_image',
    'purchase_price', 'selling_price', 'is_active', 'show_in_shop',
])]
class Product extends Model
{
    use HasFactory, LogsModelActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('product');
    }

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_in_shop' => 'boolean',
        ];
    }

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

    /**
     * Scope to products visible in the online shop.
     */
    public function scopeVisibleInShop(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('show_in_shop', true);
    }

    /**
     * Historical stock movements for this product.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    /**
     * Stock balances across warehouses for this product.
     */
    public function warehouseStocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'product_id');
    }

    /**
     * Stocktake items associated with this product.
     */
    public function stocktakeItems(): HasMany
    {
        return $this->hasMany(StocktakeItem::class, 'product_id');
    }

    /**
     * POS sale items associated with this product.
     */
    public function posSaleItems(): HasMany
    {
        return $this->hasMany(PosSaleItem::class, 'product_id');
    }

    /**
     * POS return items associated with this product.
     */
    public function posReturnItems(): HasMany
    {
        return $this->hasMany(PosReturnItem::class, 'product_id');
    }

    /**
     * Purchase order items associated with this product.
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'product_id');
    }

    /**
     * Purchase invoice items associated with this product.
     */
    public function purchaseInvoiceItems(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'product_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            if ($product->stockMovements()->exists()) {
                throw new \LogicException('Cannot delete product that has historical stock movements.');
            }
            if ($product->stocktakeItems()->exists()) {
                throw new \LogicException('Cannot delete product that has associated stocktake records.');
            }
            if ($product->posSaleItems()->exists()) {
                throw new \LogicException('Cannot delete product that has associated POS sales.');
            }
            if ($product->posReturnItems()->exists()) {
                throw new \LogicException('Cannot delete product that has associated POS returns.');
            }
            if ($product->purchaseOrderItems()->exists()) {
                throw new \LogicException('Cannot delete product that has associated purchase order items.');
            }
            if ($product->purchaseInvoiceItems()->exists()) {
                throw new \LogicException('Cannot delete product that has associated purchase invoice items.');
            }
        });
    }
}
