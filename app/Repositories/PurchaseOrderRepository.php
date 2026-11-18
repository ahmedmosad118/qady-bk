<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PurchaseOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseOrderRepository
{
    public const MAX_PER_PAGE = 100;

    /**
     * Paginate purchase orders with filtering and eager loading.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()
            ->with([
                'supplier:id,name,phone,mobile,tax_number',
                'creator:id,name',
                'confirmer:id,name',
                'purchaseInvoices:id,invoice_number,purchase_order_id,status,total,invoice_date',
            ])
            ->withCount('items')
            ->withSum('items', 'discount_amount');

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['order_date_from'])) {
            $query->whereDate('order_date', '>=', (string) $filters['order_date_from']);
        }

        if (! empty($filters['order_date_to'])) {
            $query->whereDate('order_date', '<=', (string) $filters['order_date_to']);
        }

        if (! empty($filters['delivery_date_from'])) {
            $query->whereDate('expected_delivery_date', '>=', (string) $filters['delivery_date_from']);
        }

        if (! empty($filters['delivery_date_to'])) {
            $query->whereDate('expected_delivery_date', '<=', (string) $filters['delivery_date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'order_number', 'order_date', 'expected_delivery_date', 'total', 'status', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $limit = min(max($perPage, 1), self::MAX_PER_PAGE);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Find a purchase order with all related records.
     */
    public function findWithRelations(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'items.product',
                'creator:id,name,email',
                'confirmer:id,name,email',
                'purchaseInvoices:id,invoice_number,purchase_order_id,status,total,invoice_date',
            ])
            ->findOrFail($id);
    }
}
