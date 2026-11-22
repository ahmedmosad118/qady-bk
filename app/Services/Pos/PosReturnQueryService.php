<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosReturn;
use App\Models\PosReturnItem;
use App\Models\PosSale;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PosReturnQueryService
{
    /**
     * Sum already-returned quantities per pos_sale_item_id in a single grouped query.
     *
     * @param  array<int, int>  $saleItemIds
     * @return Collection<int, mixed>
     */
    public function sumReturnedQuantitiesBySaleItemIds(array $saleItemIds): Collection
    {
        if ($saleItemIds === []) {
            return collect();
        }

        return PosReturnItem::query()
            ->whereIn('pos_sale_item_id', array_values(array_unique($saleItemIds)))
            ->selectRaw('pos_sale_item_id, COALESCE(SUM(quantity), 0) as total_returned')
            ->groupBy('pos_sale_item_id')
            ->pluck('total_returned', 'pos_sale_item_id');
    }

    /**
     * Inspect returnable items for an existing sale invoice.
     *
     * @return array<string, mixed>
     */
    public function getReturnableItems(PosSale $sale): array
    {
        $sale->loadMissing(['items.product', 'paymentMethod', 'customer']);

        $returnedByItemId = $this->sumReturnedQuantitiesBySaleItemIds(
            $sale->items->pluck('id')->all()
        );

        $itemsSummary = [];
        foreach ($sale->items as $item) {
            $alreadyReturnedStr = Decimal::fromNumeric($returnedByItemId[$item->id] ?? 0, Decimal::QUANTITY_SCALE);
            $soldQtyStr = Decimal::fromNumeric($item->quantity, Decimal::QUANTITY_SCALE);
            $remainingQtyStr = Decimal::max(
                '0.000',
                Decimal::sub($soldQtyStr, $alreadyReturnedStr, Decimal::QUANTITY_SCALE),
                Decimal::QUANTITY_SCALE
            );

            $netLineTotal = Decimal::sub(
                (string) $item->total,
                (string) $item->allocated_invoice_discount,
                Decimal::CALCULATION_SCALE
            );
            $netPaidUnitPrice = Decimal::round(
                Decimal::div($netLineTotal, $soldQtyStr, Decimal::CALCULATION_SCALE),
                Decimal::MONEY_SCALE
            );

            $itemsSummary[] = [
                'pos_sale_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'barcode' => $item->product?->barcode,
                'sold_quantity' => (float) $soldQtyStr,
                'already_returned_quantity' => (float) $alreadyReturnedStr,
                'remaining_returnable_quantity' => (float) $remainingQtyStr,
                'original_unit_price' => (string) $item->original_unit_price,
                'sold_unit_price' => (string) $item->unit_price,
                'net_paid_unit_price' => $netPaidUnitPrice,
                'can_return' => Decimal::isPositive($remainingQtyStr, Decimal::QUANTITY_SCALE),
            ];
        }

        return [
            'sale' => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'sold_at' => $sale->sold_at?->toIso8601String(),
                'payment_method' => $sale->paymentMethod?->name,
                'customer' => $sale->customer?->name,
                'subtotal' => (string) $sale->subtotal,
                'items_discount_total' => (string) $sale->items_discount_total,
                'invoice_discount_amount' => (string) $sale->invoice_discount_amount,
                'grand_total' => (string) $sale->grand_total,
            ],
            'items' => $itemsSummary,
        ];
    }

    /**
     * Paginate POS returns with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateReturns(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $shiftId = $filters['shift_id'] ?? null;
        $posSaleId = $filters['pos_sale_id'] ?? null;
        $paymentMethodId = $filters['payment_method_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return PosReturn::query()
            ->with([
                'sale:id,invoice_number,customer_id,grand_total,sold_at',
                'sale.customer:id,name,phone',
                'creator:id,name',
                'paymentMethod:id,name,type',
                'shift:id,shift_number',
                'inventoryDocuments:id,document_number,referenceable_type,referenceable_id,status',
            ])
            ->withCount('items')
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('return_number', 'like', "%{$search}%")
                        ->orWhereHas('sale', function ($sq) use ($search): void {
                            $sq->where('invoice_number', 'like', "%{$search}%")
                                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
                        })
                        ->orWhereHas('creator', fn ($uq) => $uq->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($shiftId, fn ($q) => $q->where('shift_id', $shiftId))
            ->when($posSaleId, fn ($q) => $q->where('pos_sale_id', $posSaleId))
            ->when($paymentMethodId, fn ($q) => $q->where('payment_method_id', $paymentMethodId))
            ->when($dateFrom, fn ($q) => $q->whereDate('returned_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('returned_at', '<=', $dateTo))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Get POS return with complete details including sale, items, and linked inventory document.
     */
    public function getReturnDetails(PosReturn $posReturn): PosReturn
    {
        return $posReturn->load([
            'items.product:id,name,barcode',
            'items.saleItem:id,sale_id,product_id,quantity,unit_price,total,allocated_invoice_discount',
            'paymentMethod:id,name,type',
            'sale:id,invoice_number,customer_id,payment_method_id,subtotal,items_discount_total,invoice_discount_amount,grand_total,sold_at',
            'sale.customer:id,name,phone,email',
            'sale.paymentMethod:id,name,type',
            'shift:id,shift_number,opened_at,closed_at',
            'creator:id,name,email',
            'inventoryDocuments.sourceWarehouse:id,name,code',
            'inventoryDocuments.destinationWarehouse:id,name,code',
            'inventoryDocuments.items.product:id,name,barcode',
            'inventoryDocuments.creator:id,name',
        ]);
    }
}
