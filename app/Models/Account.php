<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Accounting\NormalBalance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_type_id',
    'code',
    'name',
    'normal_balance_override',
    'reconcile',
    'is_active',
    'is_system',
    'system_key',
])]
class Account extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type_id' => 'integer',
            'normal_balance_override' => NormalBalance::class,
            'reconcile' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Account type classification for this account.
     */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    /**
     * Contacts using this account as their receivable account.
     */
    public function receivableContacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'receivable_account_id');
    }

    /**
     * Contacts using this account as their payable account.
     */
    public function payableContacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'payable_account_id');
    }

    /**
     * Payment methods linked to this account.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'account_id');
    }
}
