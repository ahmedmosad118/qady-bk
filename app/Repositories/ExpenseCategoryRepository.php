<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ExpenseCategoryRepository
{
    /**
     * Paginate expense categories with search, sorting, and unique expense usage count.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'name', 'created_at', 'expenses_count'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Return all categories ordered by name for selector dropdowns.
     *
     * @return Collection<int, ExpenseCategory>
     */
    public function listAll(): Collection
    {
        return $this->baseQuery()
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a category with its unique expense usage count.
     */
    public function findWithCounts(int $id): ExpenseCategory
    {
        return $this->baseQuery()->findOrFail($id);
    }

    /**
     * @return Builder<ExpenseCategory>
     */
    private function baseQuery(): Builder
    {
        return ExpenseCategory::query()->withCount([
            'items as expenses_count' => static function (Builder $query): void {
                $query->select(DB::raw('COUNT(DISTINCT expense_id)'));
            },
        ]);
    }
}
