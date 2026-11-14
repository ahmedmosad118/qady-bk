<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pos_held_sale_id',
    'product_id',
    'quantity',
    'unit_price',
    'discount_type',
    'discount_value',
    'discount_amount',
    'subtotal',
    'total',
])]
class PosHeldSaleItem extends Model
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
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Parent held sale.
     */
    public function heldSale(): BelongsTo
    {
        return $this->belongsTo(PosHeldSale::class, 'pos_held_sale_id');
    }

    /**
     * The product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
