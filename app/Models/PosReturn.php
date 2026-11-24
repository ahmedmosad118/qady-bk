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
    'return_number',
    'pos_sale_id',
    'shift_id',
    'payment_method_id',
    'total_returned',
    'notes',
    'created_by',
    'returned_at',
    'client_uuid',
])]
class PosReturn extends Model
{
    use HasFactory, LogsModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['client_uuid'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('pos_return');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_returned' => 'decimal:2',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * The original POS sale.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /**
     * The shift during which this return was processed.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    /**
     * Payment method for refund (must match original sale).
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * The returned items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PosReturnItem::class, 'pos_return_id');
    }

    /**
     * User who processed the return.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Associated cash shift transaction (if refunded cash).
     */
    public function shiftTransaction(): HasOne
    {
        return $this->hasOne(PosShiftTransaction::class, 'pos_return_id');
    }

    /**
     * Inventory documents linked to this return (inventory return).
     */
    public function inventoryDocuments(): MorphMany
    {
        return $this->morphMany(InventoryDocument::class, 'referenceable');
    }

    protected static function booted(): void
    {
        static::creating(function (PosReturn $return): void {
            if (empty($return->return_number)) {
                $return->return_number = 'TMP-'.Str::uuid()->toString();
            }
        });

        static::created(function (PosReturn $return): void {
            if (empty($return->return_number) || str_starts_with((string) $return->return_number, 'TMP-')) {
                $return->forceFill([
                    'return_number' => sprintf('RET-%s-%05d', now()->format('Y'), $return->id),
                ])->saveQuietly();
            }
        });

        static::updating(function (): void {
            throw new LogicException('POS returns are finalized and immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('POS returns cannot be deleted.');
        });
    }
}
