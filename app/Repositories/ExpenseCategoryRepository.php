<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ExpenseCategoryRepository
{
    /**
     * Paginate expense categories with search, sorting, and expense usage count.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ExpenseCategory::query()->withCount('expenses');

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
        return ExpenseCategory::query()
            ->withCount('expenses')
            ->orderBy('name')
            ->get();
    }

    /**
     * Find a category with its expense count.
     */
    public function findWithCounts(int $id): ExpenseCategory
    {
        return ExpenseCategory::query()
            ->withCount('expenses')
            ->findOrFail($id);
    }
}
