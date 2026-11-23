<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AccountingPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class AccountingPeriodRepository
{
    /**
     * Paginated accounting periods listing ordered by period_number.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = AccountingPeriod::query()
            ->with([
                'fiscalYear:id,code,name,start_date,end_date,status',
                'closedBy:id,name',
            ]);

        if (! empty($filters['fiscal_year_id'])) {
            $query->where('fiscal_year_id', (int) $filters['fiscal_year_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('period_number', 'like', "%{$term}%");
            });
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['period_number', 'name', 'start_date', 'end_date', 'status', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'period_number';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): AccountingPeriod
    {
        return AccountingPeriod::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(AccountingPeriod $period, array $attributes): AccountingPeriod
    {
        $period->update($attributes);

        return $period;
    }

    public function delete(AccountingPeriod $period): bool
    {
        return (bool) $period->delete();
    }
}
