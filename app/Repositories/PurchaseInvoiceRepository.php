<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\PurchaseInvoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PurchaseInvoiceRepository
{
    /**
     * Paginate purchase invoices with filtering and eager loading.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseInvoice::query()
            ->with([
                'supplier:id,name,phone,mobile,tax_number',
                'purchaseOrder:id,order_number,status',
                'creator:id,name',
                'goodsReceipt:id,document_number,document_type,referenceable_type,referenceable_id,destination_warehouse_id,status,posted_at',
            ])
            ->withCount(['items', 'expenses'])
            ->withSum('items', 'discount_amount');

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', (int) $filters['purchase_order_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['invoice_date_from'])) {
            $query->whereDate('invoice_date', '>=', (string) $filters['invoice_date_from']);
        }

        if (! empty($filters['invoice_date_to'])) {
            $query->whereDate('invoice_date', '<=', (string) $filters['invoice_date_to']);
        }

        if (! empty($filters['due_date_from'])) {
            $query->whereDate('due_date', '>=', (string) $filters['due_date_from']);
        }

        if (! empty($filters['due_date_to'])) {
            $query->whereDate('due_date', '<=', (string) $filters['due_date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'status', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $limit = min(max($perPage, 1), 100);

        return $query->orderBy($sortBy, $sortOrder)->paginate($limit);
    }

    /**
     * Find a purchase invoice with all related records.
     */
    public function findWithRelations(int $id): PurchaseInvoice
    {
        return PurchaseInvoice::query()
            ->with([
                'supplier',
                'purchaseOrder',
                'items.product',
                'expenses',
                'creator:id,name,email',
                'goodsReceipt.destinationWarehouse',
                'goodsReceipt.items.product',
            ])
            ->findOrFail($id);
    }
}
