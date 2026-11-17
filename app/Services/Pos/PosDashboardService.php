<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Support\Decimal;
use Carbon\Carbon;

class PosDashboardService
{
    /**
     * Get POS dashboard statistics for today (or specified date).
     *
     * @return array<string, mixed>
     */
    public function getTodayStats(?Carbon $date = null): array
    {
        $day = ($date ?? Carbon::now())->copy();
        $targetDate = $day->toDateString();
        $startOfDay = $day->copy()->startOfDay();
        $endOfDay = $day->copy()->endOfDay();

        $salesStats = PosSale::query()
            ->leftJoin('payment_methods', 'pos_sales.payment_method_id', '=', 'payment_methods.id')
            ->whereBetween('pos_sales.sold_at', [$startOfDay, $endOfDay])
            ->selectRaw(
                'COUNT(pos_sales.id) as invoice_count, ' .
                'COALESCE(SUM(pos_sales.grand_total), 0) as gross_sales, ' .
                'COALESCE(SUM(CASE WHEN payment_methods.type = ? THEN pos_sales.grand_total ELSE 0 END), 0) as cash_sales, ' .
                'COALESCE(SUM(CASE WHEN payment_methods.type = ? THEN pos_sales.grand_total ELSE 0 END), 0) as visa_sales, ' .
                'COALESCE(SUM(CASE WHEN payment_methods.type = ? THEN pos_sales.grand_total ELSE 0 END), 0) as bank_transfer_sales',
                [
                    PaymentMethodType::CASH->value,
                    PaymentMethodType::VISA->value,
                    PaymentMethodType::BANK_TRANSFER->value,
                ]
            )
            ->toBase()
            ->first();

        $returnsStats = PosReturn::query()
            ->whereBetween('returned_at', [$startOfDay, $endOfDay])
            ->selectRaw('COUNT(id) as returns_count, COALESCE(SUM(total_returned), 0) as total_returns')
            ->toBase()
            ->first();

        $grossSales = Decimal::fromNumeric($salesStats->gross_sales ?? 0, Decimal::MONEY_SCALE);
        $cashTotal = Decimal::fromNumeric($salesStats->cash_sales ?? 0, Decimal::MONEY_SCALE);
        $visaTotal = Decimal::fromNumeric($salesStats->visa_sales ?? 0, Decimal::MONEY_SCALE);
        $bankTransferTotal = Decimal::fromNumeric($salesStats->bank_transfer_sales ?? 0, Decimal::MONEY_SCALE);

        $totalReturns = Decimal::fromNumeric($returnsStats->total_returns ?? 0, Decimal::MONEY_SCALE);
        $netSales = Decimal::sub($grossSales, $totalReturns, Decimal::MONEY_SCALE);

        return [
            'date' => $targetDate,
            'invoice_count' => (int) ($salesStats->invoice_count ?? 0),
            'gross_sales' => $grossSales,
            'cash_sales' => $cashTotal,
            'visa_sales' => $visaTotal,
            'bank_transfer_sales' => $bankTransferTotal,
            'returns_count' => (int) ($returnsStats->returns_count ?? 0),
            'total_returns' => $totalReturns,
            'net_sales' => $netSales,
        ];
    }
}
