<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosHeldSale;
use App\Models\PosHeldSaleItem;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosHeldSaleService
{
    public function __construct(
        protected PosNumberService $numberService,
    ) {}

    /**
     * Save a temporary held sale cart (no inventory impact).
     *
     * @param array<string, mixed> $data
     */
    public function holdSale(array $data, User $user): PosHeldSale
    {
        return DB::transaction(function () use ($data, $user): PosHeldSale {
            /** @var PosShift $shift */
            $shift = PosShift::query()->findOrFail($data['shift_id']);
            if ($shift->isClosed()) {
                throw ValidationException::withMessages([
                    'shift_id' => ['لا يمكن تعليق فاتورة على وردية مغلقة.'],
                ]);
            }

            $reference = ! empty($data['reference_note'])
                ? trim((string) $data['reference_note'])
                : $this->numberService->generateHoldReference();

            $calculated = $this->calculateCart($data['items'] ?? [], $data);

            /** @var PosHeldSale $heldSale */
            $heldSale = PosHeldSale::query()->create([
                'reference_note' => $reference,
                'shift_id' => $shift->id,
                'customer_id' => $data['customer_id'] ?? null,
                'subtotal' => $calculated['subtotal'],
                'invoice_discount_type' => $data['invoice_discount_type'] ?? null,
                'invoice_discount_value' => isset($data['invoice_discount_value']) ? (float) $data['invoice_discount_value'] : null,
                'invoice_discount_amount' => $calculated['invoice_discount_amount'],
                'grand_total' => $calculated['grand_total'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($calculated['items'] as $item) {
                PosHeldSaleItem::query()->create([
                    'pos_held_sale_id' => $heldSale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_type' => $item['discount_type'],
                    'discount_value' => $item['discount_value'],
                    'discount_amount' => $item['discount_amount'],
                    'subtotal' => $item['subtotal'],
                    'total' => $item['total'],
                ]);
            }

            return $heldSale->load(['items.product', 'customer', 'shift', 'creator']);
        });
    }

    /**
     * Update an existing held sale cart.
     *
     * @param array<string, mixed> $data
     */
    public function updateHeldSale(PosHeldSale $heldSale, array $data, User $user): PosHeldSale
    {
        return DB::transaction(function () use ($heldSale, $data): PosHeldSale {
            $reference = ! empty($data['reference_note'])
                ? trim((string) $data['reference_note'])
                : $heldSale->reference_note;

            $itemsData = $data['items'] ?? [];
            $calculated = $this->calculateCart($itemsData, $data);

            $heldSale->update([
                'reference_note' => $reference,
                'customer_id' => array_key_exists('customer_id', $data) ? $data['customer_id'] : $heldSale->customer_id,
                'subtotal' => $calculated['subtotal'],
                'invoice_discount_type' => $data['invoice_discount_type'] ?? $heldSale->invoice_discount_type,
                'invoice_discount_value' => array_key_exists('invoice_discount_value', $data) ? $data['invoice_discount_value'] : $heldSale->invoice_discount_value,
                'invoice_discount_amount' => $calculated['invoice_discount_amount'],
                'grand_total' => $calculated['grand_total'],
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $heldSale->notes,
            ]);

            // Replace items
            $heldSale->items()->delete();
            foreach ($calculated['items'] as $item) {
                PosHeldSaleItem::query()->create([
                    'pos_held_sale_id' => $heldSale->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_type' => $item['discount_type'],
                    'discount_value' => $item['discount_value'],
                    'discount_amount' => $item['discount_amount'],
                    'subtotal' => $item['subtotal'],
                    'total' => $item['total'],
                ]);
            }

            return $heldSale->fresh(['items.product', 'customer', 'shift', 'creator']);
        });
    }

    /**
     * List held sales, optionally filtered by shift.
     *
     * @return Collection<int, PosHeldSale>
     */
    public function listHeldSales(?int $shiftId = null): Collection
    {
        return PosHeldSale::query()
            ->when($shiftId, function ($q, $id): void {
                $q->where('shift_id', $id);
            })
            ->with(['items.product', 'customer', 'creator'])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get held sale by ID with relations.
     */
    public function getHeldSale(PosHeldSale $heldSale): PosHeldSale
    {
        return $heldSale->load(['items.product', 'customer', 'shift', 'creator']);
    }

    /**
     * Delete a held sale cart (e.g. discarded or resumed and completed).
     */
    public function deleteHeldSale(PosHeldSale $heldSale): void
    {
        DB::transaction(function () use ($heldSale): void {
            $heldSale->items()->delete();
            $heldSale->delete();
        });
    }

    /**
     * Helper to compute subtotal, discounts, and totals for cart lines.
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @param array<string, mixed> $data
     * @return array{
     *     subtotal: string,
     *     invoice_discount_amount: string,
     *     grand_total: string,
     *     items: array<array<string, mixed>>
     * }
     */
    protected function calculateCart(array $rawItems, array $data): array
    {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['يجب إضافة صنف واحد على الأقل في الفاتورة المعلقة.'],
            ]);
        }

        $processed = [];
        $subtotalSum = '0.00';
        $sumLineTotals = '0.00';

        foreach ($rawItems as $raw) {
            $product = Product::query()->findOrFail($raw['product_id']);
            $qty = (float) $raw['quantity'];
            $qtyStr = number_format(max(0.001, $qty), 3, '.', '');

            $unitPrice = isset($raw['unit_price'])
                ? number_format((float) $raw['unit_price'], 2, '.', '')
                : (string) $product->selling_price;

            $lineSubtotal = bcmul($qtyStr, $unitPrice, 2);

            $discType = $raw['discount_type'] ?? null;
            $discVal = isset($raw['discount_value']) ? (float) $raw['discount_value'] : null;
            $discAmount = '0.00';

            if ($discType === 'percentage' && $discVal !== null) {
                $discAmount = bcdiv(bcmul($lineSubtotal, (string) $discVal, 4), '100', 2);
            } elseif ($discType === 'fixed' && $discVal !== null) {
                $discAmount = number_format(min((float) $lineSubtotal, $discVal), 2, '.', '');
            }

            if (bccomp($discAmount, $lineSubtotal, 2) > 0) {
                $discAmount = $lineSubtotal;
            }

            $lineTotal = bcsub($lineSubtotal, $discAmount, 2);

            $subtotalSum = bcadd($subtotalSum, $lineSubtotal, 2);
            $sumLineTotals = bcadd($sumLineTotals, $lineTotal, 2);

            $processed[] = [
                'product_id' => $product->id,
                'quantity' => $qtyStr,
                'unit_price' => $unitPrice,
                'discount_type' => $discType,
                'discount_value' => $discVal !== null ? number_format($discVal, 2, '.', '') : null,
                'discount_amount' => $discAmount,
                'subtotal' => $lineSubtotal,
                'total' => $lineTotal,
            ];
        }

        $invDiscType = $data['invoice_discount_type'] ?? null;
        $invDiscVal = isset($data['invoice_discount_value']) ? (float) $data['invoice_discount_value'] : null;
        $invDiscAmount = '0.00';

        if ($invDiscType === 'percentage' && $invDiscVal !== null) {
            $invDiscAmount = bcdiv(bcmul($sumLineTotals, (string) $invDiscVal, 4), '100', 2);
        } elseif ($invDiscType === 'fixed' && $invDiscVal !== null) {
            $invDiscAmount = number_format(min((float) $sumLineTotals, $invDiscVal), 2, '.', '');
        }

        if (bccomp($invDiscAmount, $sumLineTotals, 2) > 0) {
            $invDiscAmount = $sumLineTotals;
        }

        $grandTotal = bcsub($sumLineTotals, $invDiscAmount, 2);

        return [
            'subtotal' => $subtotalSum,
            'invoice_discount_amount' => $invDiscAmount,
            'grand_total' => $grandTotal,
            'items' => $processed,
        ];
    }
}
