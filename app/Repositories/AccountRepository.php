<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Account;
use App\Models\AccountClass;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AccountRepository
{
    /**
     * Return virtual tree: AccountClasses -> AccountTypes with account counts.
     *
     * @return Collection<int, AccountClass>
     */
    public function getTree(): Collection
    {
        return AccountClass::query()
            ->orderBy('sort_order')
            ->with([
                'accountTypes' => static function ($query): void {
                    $query->orderBy('sort_order')
                        ->withCount('accounts');
                },
            ])
            ->get(['id', 'code', 'name', 'sort_order']);
    }

    /**
     * Paginated accounts listing with server-side filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Account::query()
            ->with([
                'accountType:id,account_class_id,code,name',
                'accountType.accountClass:id,code,name',
            ])
            ->withExists([
                'paymentMethods',
                'receivableContacts',
                'payableContacts',
            ]);

        if (! empty($filters['account_type_id'])) {
            $query->where('account_type_id', (int) $filters['account_type_id']);
        } elseif (! empty($filters['account_class_id'])) {
            $classId = (int) $filters['account_class_id'];
            $query->whereHas('accountType', static function (Builder $q) use ($classId): void {
                $q->where('account_class_id', $classId);
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%");
            });
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['code', 'name', 'is_active', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'code';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = min(max($perPage, 1), 100);

        $paginator = $query->orderBy($sortBy, $sortOrder)->paginate($limit);

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Account $account): array => $this->transformAccount($account))
        );

        return $paginator;
    }

    /**
     * Load detailed view of a single account.
     *
     * @return array<string, mixed>
     */
    public function getDetails(Account $account): array
    {
        $account->load([
            'accountType:id,account_class_id,code,name',
            'accountType.accountClass:id,code,name',
        ])->loadExists([
            'paymentMethods',
            'receivableContacts',
            'payableContacts',
        ]);

        return $this->transformAccount($account);
    }

    /**
     * @return array<string, mixed>
     */
    private function transformAccount(Account $account): array
    {
        $hasLinks = (bool) ($account->payment_methods_exists ?? false)
            || (bool) ($account->receivable_contacts_exists ?? false)
            || (bool) ($account->payable_contacts_exists ?? false);

        return [
            'id' => $account->id,
            'account_type_id' => $account->account_type_id,
            'account_type' => $account->accountType ? [
                'id' => $account->accountType->id,
                'code' => $account->accountType->code,
                'name' => $account->accountType->name,
                'account_class_id' => $account->accountType->account_class_id,
                'account_class' => $account->accountType->accountClass ? [
                    'id' => $account->accountType->accountClass->id,
                    'code' => $account->accountType->accountClass->code,
                    'name' => $account->accountType->accountClass->name,
                ] : null,
            ] : null,
            'code' => $account->code,
            'name' => $account->name,
            'normal_balance_override' => $account->normal_balance_override?->value,
            'reconcile' => (bool) $account->reconcile,
            'is_active' => (bool) $account->is_active,
            'is_system' => (bool) $account->is_system,
            'can_delete' => ! $account->is_system && ! $hasLinks,
            'created_at' => $account->created_at,
            'updated_at' => $account->updated_at,
        ];
    }
}
