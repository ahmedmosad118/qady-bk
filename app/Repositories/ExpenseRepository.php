<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ExpenseRepository
{
    /**
     * Paginate expenses with filtering, sorting, and eager loading.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Expense::query()
            ->with([
                'contact:id,name,phone,mobile',
                'journal:id,code,name,type',
                'creator:id,name',
                'postedBy:id,name',
                'items.category:id,name',
                'items.account:id,code,name',
            ])
            ->withCount('items');

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['expense_category_id'])) {
            $categoryId = (int) $filters['expense_category_id'];
            $query->whereHas('items', static function (Builder $itemQuery) use ($categoryId): void {
                $itemQuery->where('expense_category_id', $categoryId);
            });
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', (int) $filters['contact_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['expense_date_from'])) {
            $query->whereDate('expense_date', '>=', (string) $filters['expense_date_from']);
        }

        if (! empty($filters['expense_date_to'])) {
            $query->whereDate('expense_date', '<=', (string) $filters['expense_date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'expense_number', 'expense_date', 'subtotal', 'total', 'status', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Find a single expense with full header, items, and attachments.
     */
    public function findWithRelations(int $id): Expense
    {
        return Expense::query()
            ->with([
                'contact:id,name,type,phone,mobile,email',
                'journal:id,code,name,type',
                'items' => static fn ($q) => $q->orderBy('id'),
                'items.category:id,name,description',
                'items.account:id,code,name',
                'items.attachments' => static fn ($q) => $q->with('creator:id,name')->orderBy('id'),
                'creator:id,name,email',
                'postedBy:id,name,email',
            ])
            ->findOrFail($id);
    }
}
