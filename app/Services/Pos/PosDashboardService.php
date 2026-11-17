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
            $saleTotal = Decimal::fromNumeric($sale->grand_total, Decimal::MONEY_SCALE);
            $grossSales = Decimal::add($grossSales, $saleTotal, Decimal::MONEY_SCALE);

            match ($sale->paymentMethod?->type) {
                PaymentMethodType::CASH => $cashTotal = Decimal::add($cashTotal, $saleTotal, Decimal::MONEY_SCALE),
                PaymentMethodType::VISA => $visaTotal = Decimal::add($visaTotal, $saleTotal, Decimal::MONEY_SCALE),
                PaymentMethodType::BANK_TRANSFER => $bankTransferTotal = Decimal::add($bankTransferTotal, $saleTotal, Decimal::MONEY_SCALE),
                default => null,
            };
        }

        $totalReturns = '0.00';
        foreach ($todayReturns as $ret) {
            $totalReturns = Decimal::add(
                $totalReturns,
                Decimal::fromNumeric($ret->total_returned, Decimal::MONEY_SCALE),
                Decimal::MONEY_SCALE
            );
        }

        $netSales = Decimal::sub($grossSales, $totalReturns, Decimal::MONEY_SCALE);

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
