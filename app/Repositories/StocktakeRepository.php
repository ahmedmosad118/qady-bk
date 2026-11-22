<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeStatus;
use App\Models\Stocktake;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class StocktakeRepository
{
    /**
     * Build filtered query for stocktakes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function query(array $filters = []): Builder
    {
        $query = Stocktake::query()
            ->with(['warehouse', 'creator', 'starter', 'submitter', 'approver', 'rejecter', 'canceller', 'completer'])
            ->withCount([
                'items as total_items_count',
                'items as counted_items_count' => function ($q) {
                    $q->where('count_status', StocktakeItemStatus::COUNTED);
                },
                'items as counting_items_count' => function ($q) {
                    $q->where('count_status', StocktakeItemStatus::COUNTING);
                },
                'items as pending_items_count' => function ($q) {
                    $q->where('count_status', StocktakeItemStatus::PENDING);
                },
            ]);

        // Search term (stocktake number or notes)
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $q) use ($term): void {
                $q->where('stocktake_number', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['scope'])) {
            $query->where('scope', $filters['scope']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('stocktake_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('stocktake_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', (int) $filters['created_by']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'stocktake_number', 'stocktake_date', 'status', 'scope', 'created_at'];
        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderByDesc('id');
        }

        return $query;
    }

    /**
     * Paginate filtered stocktakes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage);
    }

    /**
     * Calculate summary statistics for stocktakes.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function stats(array $filters = []): array
    {
        // Base query without status filter so status counts reflect full scope
        $base = Stocktake::query();

        if (! empty($filters['warehouse_id'])) {
            $base->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['date_from'])) {
            $base->whereDate('stocktake_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $base->whereDate('stocktake_date', '<=', $filters['date_to']);
        }

        $rows = (clone $base)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $rows->sum(),
            'draft' => (int) ($rows[StocktakeStatus::DRAFT->value] ?? 0),
            'in_progress' => (int) ($rows[StocktakeStatus::IN_PROGRESS->value] ?? 0),
            'pending_approval' => (int) ($rows[StocktakeStatus::PENDING_APPROVAL->value] ?? 0),
            'approved' => (int) ($rows[StocktakeStatus::APPROVED->value] ?? 0),
            'rejected' => (int) ($rows[StocktakeStatus::REJECTED->value] ?? 0),
            'cancelled' => (int) ($rows[StocktakeStatus::CANCELLED->value] ?? 0),
            'completed' => (int) ($rows[StocktakeStatus::COMPLETED->value] ?? 0),
        ];
    }
}
