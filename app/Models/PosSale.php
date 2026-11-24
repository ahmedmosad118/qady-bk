<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'invoice_number',
    'shift_id',
    'customer_id',
    'payment_method_id',
    'subtotal',
    'items_discount_total',
    'invoice_discount_type',
    'invoice_discount_value',
    'invoice_discount_amount',
    'grand_total',
    'notes',
    'created_by',
    'sold_at',
    'client_uuid',
])]
class PosSale extends Model
{
    use HasFactory, LogsModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['client_uuid'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('pos_sale');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'items_discount_total' => 'decimal:2',
            'invoice_discount_value' => 'decimal:2',
            'invoice_discount_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'sold_at' => 'datetime',
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
     * The payment method used.
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * The sale items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosSaleItem::class, 'sale_id');
    }

    /**
     * Returns issued against this sale.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class, 'pos_sale_id');
    }

    /**
     * The user / cashier who created the sale.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The associated cash shift transaction (if paid cash).
     */
    public function shiftTransaction(): HasOne
    {
        return $this->hasOne(PosShiftTransaction::class, 'pos_sale_id');
    }

    /**
     * Inventory documents linked to this sale (stock issue).
     */
    public function inventoryDocuments(): MorphMany
    {
        return $this->morphMany(InventoryDocument::class, 'referenceable');
    }

    protected static function booted(): void
    {
        static::creating(function (PosSale $sale): void {
            if (empty($sale->invoice_number)) {
                $sale->invoice_number = 'TMP-'.Str::uuid()->toString();
            }
        });

        static::created(function (PosSale $sale): void {
            if (empty($sale->invoice_number) || str_starts_with((string) $sale->invoice_number, 'TMP-')) {
                $sale->forceFill([
                    'invoice_number' => sprintf('INV-%s-%05d', now()->format('Y'), $sale->id),
                ])->saveQuietly();
            }
        });

        static::updating(function (): void {
            throw new LogicException('POS sales are finalized and immutable. Corrections must be handled via returns.');
        });

        static::deleting(function (): void {
            throw new LogicException('POS sales cannot be deleted.');
        });
    }
}
