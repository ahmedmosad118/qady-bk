<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'shift_number',
    'opening_balance',
    'expected_cash',
    'actual_cash',
    'difference',
    'opened_by',
    'opened_at',
    'closed_by',
    'closed_at',
    'notes',
])]
class PosShift extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Check if the shift is currently open.
     */
    public function isOpen(): bool
    {
        return is_null($this->closed_at);
    }

    /**
     * Check if the shift is closed.
     */
    public function isClosed(): bool
    {
        return ! is_null($this->closed_at);
    }

    /**
     * Scope to open shifts.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /**
     * Scope to closed shifts.
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereNotNull('closed_at');
    }

    /**
     * User who opened the shift.
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * User who closed the shift.
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Cash transactions recorded within this shift.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PosShiftTransaction::class, 'shift_id');
    }

    /**
     * Sales recorded within this shift.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'shift_id');
    }

    /**
     * Returns recorded within this shift.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class, 'shift_id');
    }

    /**
     * Held sales belonging to this shift.
     */
    public function heldSales(): HasMany
    {
        return $this->hasMany(PosHeldSale::class, 'shift_id');
    }
}
