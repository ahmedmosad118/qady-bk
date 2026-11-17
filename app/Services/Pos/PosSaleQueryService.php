<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosSale;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of POS sales: listing, lookup and detail views.
 * Write side lives in PosSaleService.
 */
class PosSaleQueryService
{
    /**
     * Paginate sales with filters: search, shift_id, payment_method_id, date_from, date_to.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateSales(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return PosSale::query()
            ->with([
                'customer:id,name,phone',
                'creator:id,name',
                'paymentMethod:id,name,type',
                'shift:id,shift_number',
                'inventoryDocuments:id,document_number,referenceable_type,referenceable_id',
            ])
            ->withCount(['items', 'returns'])
            ->withSum('returns as returns_total_amount', 'total_returned')
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('creator', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"));
            }))
            ->when($filters['shift_id'] ?? null, fn (Builder $q, $id) => $q->where('shift_id', $id))
            ->when($filters['payment_method_id'] ?? null, fn (Builder $q, $id) => $q->where('payment_method_id', $id))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $from) => $q->whereDate('sold_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $to) => $q->whereDate('sold_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function getSaleByInvoiceNumber(string $invoiceNumber): PosSale
    {
        return PosSale::query()
            ->where('invoice_number', $invoiceNumber)
            ->with(['items.product', 'paymentMethod', 'customer', 'shift', 'creator', 'returns'])
            ->firstOrFail();
    }

    public function getSaleDetails(PosSale $sale): PosSale
    {
        return $sale->load([
            'items.product',
            'paymentMethod',
            'customer',
            'shift.openedBy:id,name',
            'shift.closedBy:id,name',
            'creator:id,name,email',
            'returns.items.product:id,name,barcode',
            'returns.creator:id,name',
            'returns.paymentMethod:id,name,type',
            'inventoryDocuments.sourceWarehouse:id,name,code',
            'inventoryDocuments.destinationWarehouse:id,name,code',
            'inventoryDocuments.items.product:id,name,barcode',
            'inventoryDocuments.creator:id,name',
        ]);
    }
}
