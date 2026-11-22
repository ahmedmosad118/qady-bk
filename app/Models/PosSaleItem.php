<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable([
    'sale_id',
    'product_id',
    'product_snapshot',
    'quantity',
    'original_unit_price',
    'unit_price',
    'discount_type',
    'discount_value',
    'discount_amount',
    'subtotal',
    'total',
    'allocated_invoice_discount',
])]
class PosSaleItem extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_snapshot' => 'array',
            'quantity' => 'decimal:3',
            'original_unit_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'allocated_invoice_discount' => 'decimal:2',
        ];
    }

    /**
     * The parent sale.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'sale_id');
    }

    /**
     * The product sold.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Associated return line items.
     */
    public function returnItems(): HasMany
    {
        return $this->hasMany(PosReturnItem::class, 'pos_sale_item_id');
    }

    /**
     * Calculate remaining returnable quantity for this item.
     */
    public function returnableQuantity(): string
    {
        $returned = Decimal::fromNumeric($this->returnItems()->sum('quantity') ?? 0, Decimal::QUANTITY_SCALE);
        $remaining = Decimal::sub(
            Decimal::fromNumeric($this->quantity, Decimal::QUANTITY_SCALE),
            $returned,
            Decimal::QUANTITY_SCALE
        );

        return Decimal::max($remaining, '0.000', Decimal::QUANTITY_SCALE);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('POS sale items are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('POS sale items cannot be deleted.');
        });
    }
}
