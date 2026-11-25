<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\Accounting\JournalType;
use App\Models\Journal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class JournalRepository
{
    /**
     * Paginated journals listing with server-side filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Journal::query();

        if (! empty($filters['type'])) {
            $rawType = (string) $filters['type'];
            $normalizedType = JournalType::tryFrom($rawType)?->value
                ?? JournalType::tryFrom(ucfirst(strtolower($rawType)))?->value
                ?? $rawType;
            $query->where('type', $normalizedType);
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

        $sortBy = in_array($filters['sort_by'] ?? null, ['code', 'name', 'type', 'is_active', 'created_at'], true)
            ? (string) $filters['sort_by']
            : 'code';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Journal
    {
        return Journal::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Journal $journal, array $attributes): Journal
    {
        $journal->update($attributes);

        return $journal;
    }

    public function delete(Journal $journal): bool
    {
        return (bool) $journal->delete();
    }
}
