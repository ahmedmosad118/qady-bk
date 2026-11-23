<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\FiscalYear;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class FiscalYearRepository
{
    /**
     * Paginated fiscal years listing with periods count and server-side filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = FiscalYear::query()->withCount('accountingPeriods');

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['search'])) {
            $term = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($term): void {
                $q->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%");
            });
        }

        $sortBy = in_array($filters['sort_by'] ?? null, ['code', 'name', 'start_date', 'end_date', 'status', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'start_date';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): FiscalYear
    {
        return FiscalYear::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(FiscalYear $fiscalYear, array $attributes): FiscalYear
    {
        $fiscalYear->update($attributes);

        return $fiscalYear;
    }

    public function delete(FiscalYear $fiscalYear): bool
    {
        return (bool) $fiscalYear->delete();
    }
}
