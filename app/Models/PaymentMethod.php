<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'type',
    'is_active',
])]
class PaymentMethod extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Determine whether this payment method is Cash.
     */
    public function isCash(): bool
    {
        return $this->type === PaymentMethodType::CASH;
    }

    /**
     * Determine whether this payment method is Visa.
     */
    public function isVisa(): bool
    {
        return $this->type === PaymentMethodType::VISA;
    }

    /**
     * Determine whether this payment method is Bank Transfer.
     */
    public function isBankTransfer(): bool
    {
        return $this->type === PaymentMethodType::BANK_TRANSFER;
    }

    /**
     * Scope to active payment methods only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Sales paid using this payment method.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class, 'payment_method_id');
    }

    /**
     * Returns refunded using this payment method.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(PosReturn::class, 'payment_method_id');
    }
}
