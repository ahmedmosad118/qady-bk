<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PosShiftTransactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'shift_id',
    'type',
    'amount',
    'pos_sale_id',
    'pos_return_id',
    'name',
    'description',
    'created_by',
    'created_at',
])]
class PosShiftTransaction extends Model
{
    use HasFactory;

    public const TYPE_CASH_SALE = PosShiftTransactionType::CASH_SALE->value;

    public const TYPE_CASH_RETURN = PosShiftTransactionType::CASH_RETURN->value;

    public const TYPE_EXPENSE = PosShiftTransactionType::EXPENSE->value;

    public const TYPE_WITHDRAWAL = PosShiftTransactionType::WITHDRAWAL->value;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PosShiftTransactionType::class,
            'amount' => 'decimal:2',
            'created_at' => 'datetime',
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
     * Associated POS sale (if cash_sale).
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /**
     * Associated POS return (if cash_return).
     */
    public function return(): BelongsTo
    {
        return $this->belongsTo(PosReturn::class, 'pos_return_id');
    }

    /**
     * User who recorded the transaction.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('POS shift transactions are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('POS shift transactions are immutable and cannot be deleted.');
        });
    }
}
