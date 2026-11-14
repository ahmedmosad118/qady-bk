<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'reference_note',
    'shift_id',
    'customer_id',
    'subtotal',
    'invoice_discount_type',
    'invoice_discount_value',
    'invoice_discount_amount',
    'grand_total',
    'notes',
    'created_by',
])]
class PosHeldSale extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'invoice_discount_value' => 'decimal:2',
            'invoice_discount_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    /**
     * The associated shift.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    /**
     * The customer (optional).
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * The held sale item lines.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosHeldSaleItem::class, 'pos_held_sale_id');
    }

    /**
     * The user who held the sale.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
