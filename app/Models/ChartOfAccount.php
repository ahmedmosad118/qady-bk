<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'code',
    'name',
    'account_type',
    'account_purpose',
    'description',
])]
class ChartOfAccount extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'account_purpose' => AccountPurpose::class,
        ];
    }

    /**
     * Contact that owns this account as its receivable account, if any.
     */
    public function receivableContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'receivable_account_id');
    }

    /**
     * Contact that owns this account as its payable account, if any.
     */
    public function payableContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'payable_account_id');
    }

    /**
     * Payment methods linked to this account.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class, 'chart_of_account_id');
    }

    /**
     * Scope by account type.
     */
    public function scopeOfType(Builder $query, AccountType|string $type): Builder
    {
        $value = $type instanceof AccountType ? $type->value : $type;

        return $query->where('account_type', $value);
    }

    /**
     * Scope by account purpose.
     */
    public function scopeOfPurpose(Builder $query, AccountPurpose|string $purpose): Builder
    {
        $value = $purpose instanceof AccountPurpose ? $purpose->value : $purpose;

        return $query->where('account_purpose', $value);
    }

    /**
     * Scope search by code, name, or description.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    public function isAsset(): bool
    {
        return $this->account_type === AccountType::ASSET;
    }

    public function isCash(): bool
    {
        return $this->account_purpose === AccountPurpose::CASH;
    }

    public function isBank(): bool
    {
        return $this->account_purpose === AccountPurpose::BANK;
    }

    public function isGeneral(): bool
    {
        return $this->account_purpose === AccountPurpose::GENERAL;
    }
}
