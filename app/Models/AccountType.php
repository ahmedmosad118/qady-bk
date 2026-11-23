<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'account_class_id',
    'code',
    'name',
    'include_in_balance_sheet',
    'include_in_profit_loss',
    'sort_order',
    'is_active',
])]
class AccountType extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_class_id' => 'integer',
            'include_in_balance_sheet' => 'boolean',
            'include_in_profit_loss' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Main accounting class this type belongs to.
     */
    public function accountClass(): BelongsTo
    {
        return $this->belongsTo(AccountClass::class, 'account_class_id');
    }

    /**
     * Chart accounts of this type.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'account_type_id');
    }
}
