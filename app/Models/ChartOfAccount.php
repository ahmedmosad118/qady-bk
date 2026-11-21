<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'parent_id',
    'code',
    'name',
    'account_type',
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
        ];
    }

    /**
     * Parent account in the chart hierarchy.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct child accounts in the chart hierarchy.
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * Contact that owns this account as its receivable sub-account, if any.
     */
    public function receivableContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'receivable_account_id');
    }

    /**
     * Contact that owns this account as its payable sub-account, if any.
     */
    public function payableContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'payable_account_id');
    }

    /**
     * Scope to root (top-level) accounts.
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
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
     * Scope to detail (leaf / postable) accounts that have no children.
     */
    public function scopePostable(Builder $query): Builder
    {
        return $query->whereDoesntHave('children');
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

    /**
     * Determine whether this account is a group (parent) account.
     * Accounts with one or more children are group accounts.
     */
    public function isGroup(): bool
    {
        if (array_key_exists('children_count', $this->attributes)) {
            return (int) $this->attributes['children_count'] > 0;
        }

        if ($this->relationLoaded('children')) {
            return $this->children->isNotEmpty();
        }

        return $this->children()->exists();
    }

    /**
     * Determine whether this account is a detail (leaf) account eligible for direct posting.
     */
    public function isPostable(): bool
    {
        return ! $this->isGroup();
    }

    /**
     * Guard against posting transactions to a group account.
     */
    public function assertPostable(): void
    {
        if ($this->isGroup()) {
            throw new LogicException((string) __('accounting.errors.cannot_post_to_group_account'));
        }
    }

    /**
     * Dynamically compute the 1-based depth level of this account in the tree.
     */
    public function calculateLevel(): int
    {
        $level = 1;
        $visited = [$this->id => true];
        $currentParentId = $this->parent_id;
        $current = $this;

        while ($currentParentId !== null) {
            if (isset($visited[$currentParentId])) {
                break;
            }
            $visited[$currentParentId] = true;
            $level++;

            if ($current->relationLoaded('parent') && $current->parent !== null) {
                $current = $current->parent;
                $currentParentId = $current->parent_id;
            } else {
                $nextParentId = self::query()->whereKey($currentParentId)->value('parent_id');
                $currentParentId = $nextParentId !== null ? (int) $nextParentId : null;
            }
        }

        return $level;
    }
}
