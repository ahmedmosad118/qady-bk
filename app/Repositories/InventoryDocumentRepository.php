<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\InventoryDocument;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class InventoryDocumentRepository
{
    /**
     * Paginate inventory documents based on filter criteria.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
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
            ->orderBy($filters['sort_by'] ?? 'id', $filters['sort_order'] ?? 'desc')
            ->paginate($perPage);
    }

    /**
     * Summary statistics for dashboard cards or filter badges, scoped by filters (e.g. document_type).
     *
     * @param array<string, mixed> $filters
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

        return [
            'total' => (clone $baseQuery)->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'pending_approval' => (clone $baseQuery)->where('status', 'pending_approval')->count(),
            'approved' => (clone $baseQuery)->where('status', 'approved')->count(),
            'posted' => (clone $baseQuery)->where('status', 'posted')->count(),
            'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
            'cancelled' => (clone $baseQuery)->where('status', 'cancelled')->count(),
            'reversed' => (clone $baseQuery)->where('status', 'reversed')->count(),
        ];
    }

    /**
     * Find document with complete relations for display.
     */
    public function findWithRelations(InventoryDocument $document): InventoryDocument
    {
        return $document->load([
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
            'items.product:id,name,sku,barcode,purchase_price,selling_price',
        ]);
    }

    /**
     * Build base query with filters.
     *
     * @param array<string, mixed> $filters
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
