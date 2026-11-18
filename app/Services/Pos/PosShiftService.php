<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PaymentMethodType;
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
    public function openShift(User $user, float|int|string $openingBalance = '0.00', ?string $notes = null): PosShift
    {
        return DB::transaction(function () use ($user, $openingBalance, $notes): PosShift {
            $shiftNumber = $this->numberService->generateShiftNumber();

            $opening = Decimal::max(
                '0.00',
                Decimal::fromNumeric($openingBalance, Decimal::MONEY_SCALE),
                Decimal::MONEY_SCALE
            );

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
    public function closeShift(PosShift $shift, User $user, float|int|string $actualCash, ?string $notes = null): PosShift
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
            $actual = Decimal::fromNumeric($actualCash, Decimal::MONEY_SCALE);
            $difference = Decimal::sub($actual, $expectedCash, Decimal::MONEY_SCALE);

            $locked->update([
                'expected_cash' => $expectedCash,
                'actual_cash' => $actual,
                'difference' => $difference,
                'closed_by' => $user->id,
                'closed_at' => Carbon::now(),
                'notes' => $notes ?? $locked->notes,
            ]);

            return $locked->fresh(['openedBy:id,name,email', 'closedBy:id,name,email']);
        });
    }

    /**
     * Record cash expense in an open shift.
     */
    public function recordExpense(
        PosShift $shift,
        User $user,
        float|int|string $amount,
        string $name,
        ?string $description = null
    ): PosShiftTransaction {
        return $this->recordCashOutflow(
            $shift,
            $user,
            $amount,
            PosShiftTransaction::TYPE_EXPENSE,
            'Expense amount must be greater than zero.',
            $name,
            $description
        );
    }

    /**
     * Record cash withdrawal from an open shift.
     */
    public function recordWithdrawal(
        PosShift $shift,
        User $user,
        float|int|string $amount,
        string $name,
        ?string $description = null
    ): PosShiftTransaction {
        return $this->recordCashOutflow(
            $shift,
            $user,
            $amount,
            PosShiftTransaction::TYPE_WITHDRAWAL,
            'Withdrawal amount must be greater than zero.',
            $name,
            $description
        );
    }

    /**
     * Shared atomic handler for recording cash outflows (expenses and withdrawals) on an open shift.
     */
    protected function recordCashOutflow(
        PosShift $shift,
        User $user,
        float|int|string $amount,
        string $type,
        string $invalidAmountMessage,
        string $name,
        ?string $description = null
    ): PosShiftTransaction {
        $parsedAmount = Decimal::parse($amount);
        $formattedAmount = $parsedAmount !== null ? Decimal::round($parsedAmount, Decimal::MONEY_SCALE) : null;

        if ($formattedAmount === null || ! Decimal::isPositive($formattedAmount, Decimal::MONEY_SCALE)) {
            throw ValidationException::withMessages([
                'amount' => [$invalidAmountMessage],
            ]);
        }

        return DB::transaction(function () use ($shift, $user, $formattedAmount, $type, $name, $description): PosShiftTransaction {
            /** @var PosShift $locked */
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'shift' => [__('Financial transactions cannot be recorded on a closed shift')],
                ]);
            }

            $tx = new PosShiftTransaction();
            $tx->shift_id = $locked->id;
            $tx->type = $type;
            $tx->amount = $formattedAmount;
            $tx->pos_sale_id = null;
            $tx->pos_return_id = null;
            $tx->name = trim($name);
            $tx->description = $description;
            $tx->created_by = $user->id;
            $tx->created_at = Carbon::now();
            $tx->save();

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
     * Summarize shift transaction amounts and total count in a single SQL GROUP BY query.
     *
     * @return array{
     *     totals: array{cash_sale: string, cash_return: string, expense: string, withdrawal: string},
     *     count: int
     * }
     */
    protected function summarizeTransactions(PosShift $shift): array
    {
        $rows = $shift->transactions()
            ->selectRaw('type, COUNT(*) as tx_count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('type')
            ->toBase()
            ->get();

        $grouped = [];
        $totalCount = 0;
        foreach ($rows as $row) {
            $grouped[$row->type] = $row->total;
            $totalCount += (int) $row->tx_count;
        }

        return [
            'totals' => [
                PosShiftTransaction::TYPE_CASH_SALE   => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_CASH_SALE] ?? 0),
                PosShiftTransaction::TYPE_CASH_RETURN => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_CASH_RETURN] ?? 0),
                PosShiftTransaction::TYPE_EXPENSE     => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_EXPENSE] ?? 0),
                PosShiftTransaction::TYPE_WITHDRAWAL  => Decimal::fromNumeric($grouped[PosShiftTransaction::TYPE_WITHDRAWAL] ?? 0),
            ],
            'count' => $totalCount,
        ];
    }

    /**
     * Aggregate shift transaction amounts by type in a single SQL GROUP BY query.
     *
     * @return array{cash_sale: string, cash_return: string, expense: string, withdrawal: string}
     */
    protected function sumTransactionsByType(PosShift $shift): array
    {
        return $this->summarizeTransactions($shift)['totals'];
    }

    /**
     * @param array{cash_sale: string, cash_return: string, expense: string, withdrawal: string} $totals
     */
    protected function computeExpectedCash(string $openingBalance, array $totals): string
    {
        $opening  = Decimal::fromNumeric($openingBalance, Decimal::MONEY_SCALE);
        $subtotal = Decimal::add($opening, $totals[PosShiftTransaction::TYPE_CASH_SALE], Decimal::MONEY_SCALE);
        $subtotal = Decimal::sub($subtotal, $totals[PosShiftTransaction::TYPE_CASH_RETURN], Decimal::MONEY_SCALE);
        $subtotal = Decimal::sub($subtotal, $totals[PosShiftTransaction::TYPE_EXPENSE], Decimal::MONEY_SCALE);

        return Decimal::sub($subtotal, $totals[PosShiftTransaction::TYPE_WITHDRAWAL], Decimal::MONEY_SCALE);
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
     * Aggregate shift sales count and totals by payment method type in a single SQL query.
     *
     * @return array{sales_count: int, total_sales_amount: string, visa_sales: string, bank_transfer_sales: string}
     */
    protected function aggregateShiftSales(PosShift $shift): array
    {
        $stats = PosSale::query()
            ->leftJoin('payment_methods', 'pos_sales.payment_method_id', '=', 'payment_methods.id')
            ->where('pos_sales.shift_id', $shift->id)
            ->selectRaw(
                'COUNT(pos_sales.id) as sales_count, ' .
                'COALESCE(SUM(pos_sales.grand_total), 0) as total_sales_amount, ' .
                'COALESCE(SUM(CASE WHEN payment_methods.type = ? THEN pos_sales.grand_total ELSE 0 END), 0) as visa_sales, ' .
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
     * Paginate financial transactions belonging to a shift.
     */
    public function paginateShiftTransactions(PosShift $shift, int $perPage = 25): LengthAwarePaginator
    {
        return $shift->transactions()
            ->with([
                'creator:id,name',
                'sale:id,invoice_number,grand_total',
                'return:id,return_number,total_returned',
            ])
            ->orderBy('id', 'asc')
            ->paginate($perPage);
    }

    /**
     * Paginate POS sales belonging to a shift.
     */
    public function paginateShiftSales(PosShift $shift, int $perPage = 20): LengthAwarePaginator
    {
        return $shift->sales()
            ->with(['customer:id,name,phone', 'creator:id,name', 'paymentMethod:id,name,type'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Get SQL-aggregated summary for a shift without hydrating unbounded sales/transactions collections.
     *
     * @return array<string, mixed>
     */
    public function getShiftSummary(PosShift $shift): array
    {
        $shift->loadMissing([
            'openedBy:id,name,email',
            'closedBy:id,name,email',
        ]);

        $txSummary = $this->summarizeTransactions($shift);
        $txTotals = $txSummary['totals'];
        $salesStats = $this->aggregateShiftSales($shift);

        $openingBalance = Decimal::fromNumeric($shift->opening_balance, Decimal::MONEY_SCALE);
        $expectedCash = $shift->isClosed()
            ? Decimal::fromNumeric($shift->expected_cash, Decimal::MONEY_SCALE)
            : $this->computeExpectedCash($openingBalance, $txTotals);

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
            'transactions_count' => $txSummary['count'],
        ];
    }
}
