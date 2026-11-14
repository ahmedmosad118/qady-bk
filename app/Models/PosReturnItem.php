<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'pos_return_id',
    'pos_sale_item_id',
    'product_id',
    'quantity',
    'unit_price',
    'allocated_discount',
    'refund_amount',
])]
class PosReturnItem extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'allocated_discount' => 'decimal:2',
            'refund_amount' => 'decimal:2',
        ];
    }

    /**
     * The parent return.
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    /**
     * The original sale item line.
     */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(PosSaleItem::class, 'pos_sale_item_id');
    }

    /**
     * The returned product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('POS return items are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('POS return items cannot be deleted.');
        });
    }
}
