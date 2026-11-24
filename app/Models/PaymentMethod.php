<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodType;
use App\Models\Concerns\LogsModelActivity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;

#[Fillable([
    'name',
    'type',
    'account_id',
    'is_active',
])]
class PaymentMethod extends Model
{
    use HasFactory, LogsModelActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('payment_method');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Linked Asset account in the Chart of Accounts.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
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
