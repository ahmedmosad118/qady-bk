<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosSaleItem;
use App\Support\Decimal;

/**
 * Pure BCMath calculator for POS return pro-rated refunds and discounts.
 * Zero DB queries, zero side effects.
 */
class PosReturnCalculator
{
    /**
     * Calculate pro-rated net refund and allocated discount for a returned line item.
     * Multiply before dividing to avoid repeating-decimal truncation.
     *
     * @return array{refund_amount: string, allocated_discount: string}
     */
    public function calculateLine(PosSaleItem $saleItem, string $reqQtyStr, string $origQtyStr): array
    {
        $netLineTotal = Decimal::sub(
            (string) $saleItem->total,
            (string) $saleItem->allocated_invoice_discount,
            Decimal::CALCULATION_SCALE
        );

        $lineRefund = Decimal::round(
            Decimal::div(
                Decimal::mul($netLineTotal, $reqQtyStr, Decimal::CALCULATION_SCALE),
                $origQtyStr,
                Decimal::CALCULATION_SCALE
            ),
            Decimal::MONEY_SCALE
        );

        $totalLineDiscount = Decimal::add(
            (string) $saleItem->discount_amount,
            (string) $saleItem->allocated_invoice_discount,
            Decimal::CALCULATION_SCALE
        );

        $lineAllocatedDiscount = Decimal::round(
            Decimal::div(
                Decimal::mul($totalLineDiscount, $reqQtyStr, Decimal::CALCULATION_SCALE),
                $origQtyStr,
                Decimal::CALCULATION_SCALE
            ),
            Decimal::MONEY_SCALE
        );

        return [
            'refund_amount' => $lineRefund,
            'allocated_discount' => $lineAllocatedDiscount,
        ];
    }
}
