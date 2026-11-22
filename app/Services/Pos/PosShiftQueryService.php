<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\User;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class PosShiftQueryService
{
    /**
     * Get list of open shifts (optionally scoped to a specific user).
     *
     * @return Collection<int, PosShift>
     */
    public function listOpenShifts(?User $user = null): Collection
    {
        return PosShift::query()
            ->open()
            ->when($user !== null, fn ($q) => $q->where('opened_by', $user->id))
            ->with(['openedBy'])
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Paginate POS shifts with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateShifts(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        return PosShift::query()
            ->with(['openedBy:id,name', 'closedBy:id,name'])
            ->withSum('sales', 'grand_total')
            ->when($status === 'open', fn ($q) => $q->open())
            ->when($status === 'closed', fn ($q) => $q->closed())
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('shift_number', 'like', "%{$search}%")
                        ->orWhereHas('openedBy', fn ($uq) => $uq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('closedBy', fn ($uq) => $uq->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($dateFrom, fn ($q) => $q->whereDate('opened_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('opened_at', '<=', $dateTo))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Paginate cash transactions belonging to a shift.
     */
    public function paginateTransactions(PosShift $shift, int $perPage = 50): LengthAwarePaginator
    {
        return $shift->transactions()
            ->with(['creator', 'sale', 'return'])
            ->orderBy('id', 'asc')
            ->paginate(min(max($perPage, 1), 100));
    }

    /**
     * Paginate POS sales belonging to a shift.
     */
    public function paginateSales(PosShift $shift, int $perPage = 20): LengthAwarePaginator
    {
        return $shift->sales()
            ->with(['customer:id,name,phone', 'creator:id,name', 'paymentMethod:id,name'])
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    /**
     * Aggregate shift sales count and totals by payment method type in a single SQL query.
     *
     * @return array{sales_count: int, total_sales_amount: string, visa_sales: string, bank_transfer_sales: string}
     */
    public function aggregateShiftSales(PosShift $shift): array
    {
        $stats = PosSale::query()
            ->leftJoin('payment_methods', 'pos_sales.payment_method_id', '=', 'payment_methods.id')
            ->where('pos_sales.shift_id', $shift->id)
            ->selectRaw(
                'COUNT(pos_sales.id) as sales_count, '.
                'COALESCE(SUM(pos_sales.grand_total), 0) as total_sales_amount, '.
                'COALESCE(SUM(CASE WHEN payment_methods.type = ? THEN pos_sales.grand_total ELSE 0 END), 0) as visa_sales, '.
                'COALESCE(SUM(CASE WHEN payment_methods.type = ? THEN pos_sales.grand_total ELSE 0 END), 0) as bank_transfer_sales',
                [
                    PaymentMethodType::VISA->value,
                    PaymentMethodType::BANK_TRANSFER->value,
                ]
            )
            ->toBase()
            ->first();

        return [
            'sales_count' => (int) ($stats->sales_count ?? 0),
            'total_sales_amount' => Decimal::fromNumeric($stats->total_sales_amount ?? 0, Decimal::MONEY_SCALE),
            'visa_sales' => Decimal::fromNumeric($stats->visa_sales ?? 0, Decimal::MONEY_SCALE),
            'bank_transfer_sales' => Decimal::fromNumeric($stats->bank_transfer_sales ?? 0, Decimal::MONEY_SCALE),
        ];
    }

    /**
     * Get full details and summary for a shift.
     *
     * @param  array<string, string>  $txTotals
     * @return array<string, mixed>
     */
    public function getShiftSummary(PosShift $shift, array $txTotals, string $expectedCash): array
    {
        $shift->load([
            'openedBy:id,name,email',
            'closedBy:id,name,email',
            'transactions.creator:id,name',
            'transactions.sale:id,invoice_number,grand_total',
            'transactions.return:id,return_number,refund_amount',
            'sales' => fn ($q) => $q->with([
                'customer:id,name,phone',
                'paymentMethod:id,name,type',
                'creator:id,name',
            ])->latest('id')->limit(50),
        ]);

        $salesStats = $this->aggregateShiftSales($shift);
        $openingBalance = Decimal::fromNumeric($shift->opening_balance, Decimal::MONEY_SCALE);
        $totalReturnsCount = $shift->returns()->count();

        return [
            'shift' => $shift,
            'is_open' => $shift->isOpen(),
            'opening_balance' => $openingBalance,
            'cash_sales' => $txTotals[PosShiftTransaction::TYPE_CASH_SALE],
            'cash_returns' => $txTotals[PosShiftTransaction::TYPE_CASH_RETURN],
            'expenses' => $txTotals[PosShiftTransaction::TYPE_EXPENSE],
            'withdrawals' => $txTotals[PosShiftTransaction::TYPE_WITHDRAWAL],
            'expected_cash' => $expectedCash,
            'actual_cash' => $shift->actual_cash !== null ? Decimal::fromNumeric($shift->actual_cash, Decimal::MONEY_SCALE) : null,
            'difference' => $shift->difference !== null ? Decimal::fromNumeric($shift->difference, Decimal::MONEY_SCALE) : null,
            'total_sales_amount' => $salesStats['total_sales_amount'],
            'visa_sales' => $salesStats['visa_sales'],
            'bank_transfer_sales' => $salesStats['bank_transfer_sales'],
            'sales_count' => $salesStats['sales_count'],
            'returns_count' => $totalReturnsCount,
        ];
    }
}
