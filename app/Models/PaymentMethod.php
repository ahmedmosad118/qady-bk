<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
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
            'is_active' => 'boolean',
        ];
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
