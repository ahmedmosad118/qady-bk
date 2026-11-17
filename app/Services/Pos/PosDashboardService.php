<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PosReturn;
use App\Models\PosSale;
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
        $targetDate = ($date ?? Carbon::now())->toDateString();

        $todaySales = PosSale::query()
            ->whereDate('sold_at', $targetDate)
            ->with('paymentMethod')
            ->get();

        $todayReturns = PosReturn::query()
            ->whereDate('returned_at', $targetDate)
            ->get();

        $invoiceCount = $todaySales->count();
        $grossSales = '0.00';
        $cashTotal = '0.00';
        $visaTotal = '0.00';
        $bankTransferTotal = '0.00';

        foreach ($todaySales as $sale) {
            $grossSales = bcadd($grossSales, (string) $sale->grand_total, 2);

            match ($sale->paymentMethod?->type) {
                PaymentMethodType::CASH => $cashTotal = bcadd($cashTotal, (string) $sale->grand_total, 2),
                PaymentMethodType::VISA => $visaTotal = bcadd($visaTotal, (string) $sale->grand_total, 2),
                PaymentMethodType::BANK_TRANSFER => $bankTransferTotal = bcadd($bankTransferTotal, (string) $sale->grand_total, 2),
                default => null,
            };
        }

        $totalReturns = '0.00';
        foreach ($todayReturns as $ret) {
            $totalReturns = bcadd($totalReturns, (string) $ret->total_returned, 2);
        }

        $netSales = bcsub($grossSales, $totalReturns, 2);

        return [
            'date' => $targetDate,
            'invoice_count' => $invoiceCount,
            'gross_sales' => $grossSales,
            'cash_sales' => $cashTotal,
            'visa_sales' => $visaTotal,
            'bank_transfer_sales' => $bankTransferTotal,
            'returns_count' => $todayReturns->count(),
            'total_returns' => $totalReturns,
            'net_sales' => $netSales,
        ];
    }
}
