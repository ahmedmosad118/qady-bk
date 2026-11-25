<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\Payment\PaymentStatus;
use App\Models\PaymentAllocation;
use App\Models\PurchaseInvoice;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PurchaseInvoiceRepository
{
    /**
     * Paginate purchase invoices with filtering and eager loading.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseInvoice::query()
            ->with([
                'supplier:id,name,phone,mobile,tax_number',
                'purchaseOrder:id,order_number,status',
                'journal:id,code,name,type',
                'creator:id,name',
                'postedBy:id,name',
                'goodsReceipt:id,document_number,document_type,referenceable_type,referenceable_id,destination_warehouse_id,status,posted_at',
                'journalEntry:id,entry_number,status,source_type,source_id,entry_date,posted_at',
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
            $query->where('invoice_date', '>=', (string) $filters['invoice_date_from']);
        }

        if (! empty($filters['invoice_date_to'])) {
            $query->where('invoice_date', '<=', (string) $filters['invoice_date_to']);
        }

        if (! empty($filters['due_date_from'])) {
            $query->where('due_date', '>=', (string) $filters['due_date_from']);
        }

        if (! empty($filters['due_date_to'])) {
            $query->where('due_date', '<=', (string) $filters['due_date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'invoice_number', 'invoice_date', 'due_date', 'total', 'status', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        $limit = min(max($perPage, 1), 100);

        $paginator = $query->orderBy($sortBy, $sortOrder)->paginate($limit);
        $this->attachPaymentSummary($paginator->getCollection());

        return $paginator;
    }

    /**
     * Find a purchase invoice with all related records.
     */
    public function findWithRelations(int $id): PurchaseInvoice
    {
        $invoice = PurchaseInvoice::query()
            ->with([
                'supplier',
                'purchaseOrder',
                'journal:id,code,name,type',
                'items.product',
                'expenses',
                'creator:id,name,email',
                'postedBy:id,name,email',
                'goodsReceipt.destinationWarehouse',
                'goodsReceipt.items.product',
                'journalEntry.journal:id,code,name',
                'journalEntry.postedBy:id,name',
                'journalEntry.lines.account:id,code,name',
            ])
            ->findOrFail($id);

        $this->attachPaymentSummary(collect([$invoice]));

        return $invoice;
    }

    /**
     * Attach posted paid_amount and outstanding_amount using a single grouped query.
     *
     * @param  Collection<int, PurchaseInvoice>  $invoices
     */
    private function attachPaymentSummary(Collection $invoices): void
    {
        if ($invoices->isEmpty()) {
            return;
        }

        $invoiceIds = $invoices->pluck('id')->all();
        $morphType = (new PurchaseInvoice)->getMorphClass();

        $postedPaidByInvoice = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.allocatable_type', $morphType)
            ->whereIn('payment_allocations.allocatable_id', $invoiceIds)
            ->where('payments.status', PaymentStatus::POSTED->value)
            ->groupBy('payment_allocations.allocatable_id')
            ->selectRaw('payment_allocations.allocatable_id, COALESCE(SUM(payment_allocations.allocated_amount), 0) as paid_total')
            ->pluck('paid_total', 'allocatable_id');

        foreach ($invoices as $invoice) {
            $total = Decimal::fromNumeric($invoice->total ?? 0, Decimal::MONEY_SCALE);
            $paid = Decimal::fromNumeric($postedPaidByInvoice->get($invoice->id, 0), Decimal::MONEY_SCALE);
            $outstanding = Decimal::sub($total, $paid, Decimal::MONEY_SCALE);

            $invoice->setAttribute('paid_amount', $paid);
            $invoice->setAttribute('outstanding_amount', $outstanding);
        }
    }
}
