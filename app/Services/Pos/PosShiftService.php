<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\User;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosShiftService
{
    public function __construct(
        protected PosNumberService $numberService,
    ) {}

    /**
     * Open a new shift.
     */
    public function openShift(User $user, float $openingBalance = 0.0, ?string $notes = null): PosShift
    {
        return DB::transaction(function () use ($user, $openingBalance, $notes): PosShift {
            $shiftNumber = $this->numberService->generateShiftNumber();

            $opening = number_format(max(0.0, $openingBalance), 2, '.', '');

            /** @var PosShift $shift */
            $shift = PosShift::query()->create([
                'shift_number' => $shiftNumber,
                'opening_balance' => $opening,
                'expected_cash' => $opening,
                'actual_cash' => null,
                'difference' => null,
                'opened_by' => $user->id,
                'opened_at' => Carbon::now(),
                'closed_by' => null,
                'closed_at' => null,
                'notes' => $notes,
            ]);

            return $shift->load(['openedBy']);
        });
    }

    /**
     * Close an active shift.
     * Takes snapshot of expected cash, actual cash, and difference.
     */
    public function closeShift(PosShift $shift, User $user, float $actualCash, ?string $notes = null): PosShift
    {
        return DB::transaction(function () use ($shift, $user, $actualCash, $notes): PosShift {
            /** @var PosShift $locked */
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'shift' => ['الوردية مغلقة بالفعل ولا يمكن إغلاقها مرة أخرى أو تعديلها.'],
                ]);
            }

            // Calculate expected cash from actual transactions
            $expectedCash = $this->calculateExpectedCash($locked);
            $actual = number_format($actualCash, 2, '.', '');
            $difference = bcsub($actual, $expectedCash, 2);

            $locked->update([
                'expected_cash' => $expectedCash,
                'actual_cash' => $actual,
                'difference' => $difference,
                'closed_by' => $user->id,
                'closed_at' => Carbon::now(),
                'notes' => $notes ?? $locked->notes,
            ]);

            return $locked->fresh(['openedBy', 'closedBy', 'transactions']);
        });
    }

    /**
     * Record cash expense in an open shift.
     */
    public function recordExpense(
        PosShift $shift,
        User $user,
        float $amount,
        string $name,
        ?string $description = null
    ): PosShiftTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['مبلغ المصروف يجب أن يكون أكبر من الصفر.'],
            ]);
        }

        return DB::transaction(function () use ($shift, $user, $amount, $name, $description): PosShiftTransaction {
            /** @var PosShift $locked */
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'shift' => ['لا يمكن تسجيل حركات مالية على وردية مغلقة.'],
                ]);
            }

            $formattedAmount = number_format($amount, 2, '.', '');

            $tx = new PosShiftTransaction();
            $tx->shift_id = $locked->id;
            $tx->type = PosShiftTransaction::TYPE_EXPENSE;
            $tx->amount = $formattedAmount;
            $tx->pos_sale_id = null;
            $tx->pos_return_id = null;
            $tx->name = trim($name);
            $tx->description = $description;
            $tx->created_by = $user->id;
            $tx->created_at = Carbon::now();
            $tx->save();

            // Refresh expected cash
            $locked->update([
                'expected_cash' => $this->calculateExpectedCash($locked),
            ]);

            return $tx->load(['creator']);
        });
    }

    /**
     * Record cash withdrawal from an open shift.
     */
    public function recordWithdrawal(
        PosShift $shift,
        User $user,
        float $amount,
        string $name,
        ?string $description = null
    ): PosShiftTransaction {
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['مبلغ السحب يجب أن يكون أكبر من الصفر.'],
            ]);
        }

        return DB::transaction(function () use ($shift, $user, $amount, $name, $description): PosShiftTransaction {
            /** @var PosShift $locked */
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'shift' => ['لا يمكن تسجيل حركات مالية على وردية مغلقة.'],
                ]);
            }

            $formattedAmount = number_format($amount, 2, '.', '');

            $tx = new PosShiftTransaction();
            $tx->shift_id = $locked->id;
            $tx->type = PosShiftTransaction::TYPE_WITHDRAWAL;
            $tx->amount = $formattedAmount;
            $tx->pos_sale_id = null;
            $tx->pos_return_id = null;
            $tx->name = trim($name);
            $tx->description = $description;
            $tx->created_by = $user->id;
            $tx->created_at = Carbon::now();
            $tx->save();

            // Refresh expected cash
            $locked->update([
                'expected_cash' => $this->calculateExpectedCash($locked),
            ]);

            return $tx->load(['creator']);
        });
    }

    /**
     * Calculate Expected Cash for a shift in a single grouped query:
     * Expected Cash = Opening Balance + Cash Sales - Cash Returns - Expenses - Withdrawals
     */
    public function calculateExpectedCash(PosShift $shift): string
    {
        $totals = $this->sumTransactionsByType($shift);

        return $this->computeExpectedCash((string) $shift->opening_balance, $totals);
    }

    /**
     * Aggregate shift transaction amounts by type in a single SQL GROUP BY query.
     *
     * @return array{cash_sale: string, cash_return: string, expense: string, withdrawal: string}
     */
    protected function sumTransactionsByType(PosShift $shift): array
    {
        $grouped = $shift->transactions()
            ->selectRaw('type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            PosShiftTransaction::TYPE_CASH_SALE => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_CASH_SALE] ?? 0),
            PosShiftTransaction::TYPE_CASH_RETURN => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_CASH_RETURN] ?? 0),
            PosShiftTransaction::TYPE_EXPENSE => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_EXPENSE] ?? 0),
            PosShiftTransaction::TYPE_WITHDRAWAL => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_WITHDRAWAL] ?? 0),
        ];
    }

    /**
     * @param array{cash_sale: string, cash_return: string, expense: string, withdrawal: string} $totals
     */
    protected function computeExpectedCash(string $openingBalance, array $totals): string
    {
        $subtotal = bcadd($openingBalance, $totals[PosShiftTransaction::TYPE_CASH_SALE], Decimal::MONEY_SCALE);
        $subtotal = bcsub($subtotal, $totals[PosShiftTransaction::TYPE_CASH_RETURN], Decimal::MONEY_SCALE);
        $subtotal = bcsub($subtotal, $totals[PosShiftTransaction::TYPE_EXPENSE], Decimal::MONEY_SCALE);

        return bcsub($subtotal, $totals[PosShiftTransaction::TYPE_WITHDRAWAL], Decimal::MONEY_SCALE);
    }

    /**
     * Get list of open shifts.
     *
     * @return Collection<int, PosShift>
     */
    public function listOpenShifts(): Collection
    {
        return PosShift::query()
            ->open()
            ->with(['openedBy'])
            ->orderByDesc('opened_at')
            ->get();
    }

    /**
     * Paginate POS shifts with filters.
     *
     * @param array<string, mixed> $filters
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
     * Get full details and summary for a shift.
     *
     * @return array<string, mixed>
     */
    public function getShiftSummary(PosShift $shift): array
    {
        $shift->load([
            'openedBy:id,name,email',
            'closedBy:id,name,email',
            'transactions.creator:id,name',
            'transactions.sale:id,invoice_number,grand_total',
            'transactions.return:id,return_number,refund_amount',
            'sales.customer:id,name,phone',
            'sales.paymentMethod:id,name,type',
            'sales.creator:id,name',
        ]);

        $txTotals = $this->sumTransactionsByType($shift);

        $expectedCash = $shift->isClosed()
            ? (string) $shift->expected_cash
            : $this->computeExpectedCash((string) $shift->opening_balance, $txTotals);

        $cashSales = $txTotals[PosShiftTransaction::TYPE_CASH_SALE];
        $cashReturns = $txTotals[PosShiftTransaction::TYPE_CASH_RETURN];
        $expenses = $txTotals[PosShiftTransaction::TYPE_EXPENSE];
        $withdrawals = $txTotals[PosShiftTransaction::TYPE_WITHDRAWAL];

        // Non-cash sales totals
        $visaTotal = '0.00';
        $bankTransferTotal = '0.00';

        foreach ($shift->sales as $sale) {
            match ($sale->paymentMethod?->type) {
                PaymentMethodType::VISA => $visaTotal = bcadd($visaTotal, (string) $sale->grand_total, 2),
                PaymentMethodType::BANK_TRANSFER => $bankTransferTotal = bcadd($bankTransferTotal, (string) $sale->grand_total, 2),
                default => null,
            };
        }

        $totalSalesSum = (string) $shift->sales->sum('grand_total');
        $totalSalesCount = $shift->sales->count();
        $totalReturnsCount = $shift->returns()->count();

        return [
            'shift' => $shift,
            'is_open' => $shift->isOpen(),
            'opening_balance' => (string) $shift->opening_balance,
            'cash_sales' => $cashSales,
            'cash_returns' => $cashReturns,
            'expenses' => $expenses,
            'withdrawals' => $withdrawals,
            'expected_cash' => $expectedCash,
            'actual_cash' => $shift->actual_cash ? (string) $shift->actual_cash : null,
            'difference' => $shift->difference ? (string) $shift->difference : null,
            'total_sales_amount' => $totalSalesSum,
            'visa_sales' => $visaTotal,
            'bank_transfer_sales' => $bankTransferTotal,
            'sales_count' => $totalSalesCount,
            'returns_count' => $totalReturnsCount,
        ];
    }
}
