<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryDocumentRepository
{
    /**
     * Paginate inventory documents based on filter criteria.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $allowedSortColumns = [
            'id',
            'document_number',
            'document_type',
            'document_date',
            'status',
            'created_at',
            'updated_at',
            'posted_at',
            'approved_at',
        ];
        $sortBy = in_array($filters['sort_by'] ?? null, $allowedSortColumns, true)
            ? (string) $filters['sort_by']
            : 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $this->queryWithFilters($filters)
            ->with([
                'sourceWarehouse:id,code,name',
                'destinationWarehouse:id,code,name',
                'creator:id,name',
                'approver:id,name',
                'poster:id,name',
                'referenceable',
            ])
            ->withCount('items')
            ->orderBy($sortBy, $sortOrder)
            ->paginate($perPage);
    }

    /**
     * Summary statistics for dashboard cards or filter badges, scoped by filters (e.g. document_type).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function stats(array $filters = []): array
    {
        $baseQuery = InventoryDocument::query();

        if (! empty($filters['document_type'])) {
            $type = str_replace('-', '_', (string) $filters['document_type']);
            $baseQuery->where('document_type', $type);
        }

        if (! empty($filters['source_warehouse_id'])) {
            $baseQuery->where('source_warehouse_id', (int) $filters['source_warehouse_id']);
        }

        $rows = (clone $baseQuery)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'total' => 0,
            'draft' => (int) ($rows[InventoryDocumentStatus::DRAFT->value] ?? 0),
            'pending_approval' => (int) ($rows[InventoryDocumentStatus::PENDING_APPROVAL->value] ?? 0),
            'approved' => (int) ($rows[InventoryDocumentStatus::APPROVED->value] ?? 0),
            'posted' => (int) ($rows[InventoryDocumentStatus::POSTED->value] ?? 0),
            'rejected' => (int) ($rows[InventoryDocumentStatus::REJECTED->value] ?? 0),
            'cancelled' => (int) ($rows[InventoryDocumentStatus::CANCELLED->value] ?? 0),
            'reversed' => (int) ($rows[InventoryDocumentStatus::REVERSED->value] ?? 0),
        ];
        $counts['total'] = (int) $rows->sum();

        return $counts;
    }

    /**
     * Find document with complete relations for display.
     */
    public function findWithRelations(InventoryDocument $document): InventoryDocument
    {
        return $document->loadMissing([
            'sourceWarehouse',
            'destinationWarehouse',
            'creator:id,name,email',
            'reviewer:id,name,email',
            'approver:id,name,email',
            'poster:id,name,email',
            'rejecter:id,name,email',
            'canceller:id,name,email',
            'reverser:id,name,email',
            'referenceable',
            'items.product:id,name,barcode,purchase_price,selling_price',
        ]);
    }

    /**
     * Build base query with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function queryWithFilters(array $filters): Builder
    {
        return InventoryDocument::query()
            ->when($filters['search'] ?? null, function (Builder $q, $search): void {
                $q->where('document_number', 'like', "%{$search}%");
            })
            ->when($filters['document_type'] ?? null, function (Builder $q, $type): void {
                $q->where('document_type', str_replace('-', '_', (string) $type));
            })
            ->when($filters['status'] ?? null, function (Builder $q, $status): void {
                $q->where('status', $status);
            })
            ->when($filters['source_warehouse_id'] ?? null, function (Builder $q, $srcId): void {
                $q->where('source_warehouse_id', (int) $srcId);
            })
            ->when($filters['destination_warehouse_id'] ?? null, function (Builder $q, $destId): void {
                $q->where('destination_warehouse_id', (int) $destId);
            })
            ->when($filters['date_from'] ?? null, function (Builder $q, $dateFrom): void {
                $q->whereDate('document_date', '>=', Carbon::parse($dateFrom)->toDateString());
            })
            ->when($filters['date_to'] ?? null, function (Builder $q, $dateTo): void {
                $q->whereDate('document_date', '<=', Carbon::parse($dateTo)->toDateString());
            })
            ->when($filters['created_by'] ?? null, function (Builder $q, $userId): void {
                $q->where('created_by', (int) $userId);
            })
            ->when($filters['product_id'] ?? null, function (Builder $q, $productId): void {
                $q->whereHas('items', function (Builder $sub) use ($productId): void {
                    $sub->where('product_id', (int) $productId);
                });
            });
    }
}
