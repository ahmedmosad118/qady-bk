<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\PosShiftTransactionType;
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
        protected PosShiftQueryService $queryService,
    ) {}

    /**
     * Open a new shift for the user.
     * Strictly enforces one open shift per user at any time.
     */
    public function openShift(User $user, float|int|string $openingBalance = '0.00', ?string $notes = null): PosShift
    {
        return DB::transaction(function () use ($user, $openingBalance, $notes): PosShift {
            $existingOpen = PosShift::query()
                ->where('opened_by', $user->id)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($existingOpen !== null) {
                throw ValidationException::withMessages([
                    'shift' => [__('pos.errors.user_already_has_open_shift', ['number' => $existingOpen->shift_number])],
                ]);
            }

            $opening = Decimal::max(
                '0.00',
                Decimal::fromNumeric($openingBalance, Decimal::MONEY_SCALE),
                Decimal::MONEY_SCALE
            );

            /** @var PosShift $shift */
            $shift = PosShift::query()->create([
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

            return $shift->setRelation('openedBy', $user);
        });
    }

    /**
     * Ensure the shift belongs to the acting user.
     */
    protected function assertShiftBelongsToUser(PosShift $shift, User $user): void
    {
        if ((int) $shift->opened_by !== (int) $user->id) {
            throw ValidationException::withMessages([
                'shift' => [__('pos.errors.shift_belongs_to_another_user')],
            ]);
        }
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

            $this->assertShiftBelongsToUser($locked, $user);

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

            return $locked->setRelation('closedBy', $user);
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
        return $this->recordCashTransaction(
            $shift,
            $user,
            PosShiftTransactionType::EXPENSE,
            $amount,
            $name,
            $description,
            'مبلغ المصروف يجب أن يكون أكبر من الصفر.'
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
        return $this->recordCashTransaction(
            $shift,
            $user,
            PosShiftTransactionType::WITHDRAWAL,
            $amount,
            $name,
            $description,
            'مبلغ السحب يجب أن يكون أكبر من الصفر.'
        );
    }

    /**
     * Record a manual cash outflow transaction (expense or withdrawal) on an open shift.
     */
    protected function recordCashTransaction(
        PosShift $shift,
        User $user,
        PosShiftTransactionType $type,
        float|int|string $amount,
        string $name,
        ?string $description,
        string $invalidAmountMessage
    ): PosShiftTransaction {
        $parsedAmount = Decimal::parse($amount);
        $formattedAmount = $parsedAmount !== null ? Decimal::round($parsedAmount, Decimal::MONEY_SCALE) : null;

        if ($formattedAmount === null || ! Decimal::isPositive($formattedAmount, Decimal::MONEY_SCALE)) {
            throw ValidationException::withMessages([
                'amount' => [$invalidAmountMessage],
            ]);
        }

        return DB::transaction(function () use ($shift, $user, $type, $formattedAmount, $name, $description): PosShiftTransaction {
            /** @var PosShift $locked */
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            $this->assertShiftBelongsToUser($locked, $user);

            if ($locked->isClosed()) {
                throw ValidationException::withMessages([
                    'shift' => [__('Financial transactions cannot be recorded on a closed shift')],
                ]);
            }

            $tx = new PosShiftTransaction;
            $tx->shift_id = $locked->id;
            $tx->type = $type->value;
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

            return $tx->setRelation('creator', $user);
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
     * @param  array{cash_sale: string, cash_return: string, expense: string, withdrawal: string}  $totals
     */
    protected function computeExpectedCash(string $openingBalance, array $totals): string
    {
        $opening = Decimal::fromNumeric($openingBalance, Decimal::MONEY_SCALE);
        $subtotal = Decimal::add($opening, $totals[PosShiftTransaction::TYPE_CASH_SALE], Decimal::MONEY_SCALE);
        $subtotal = Decimal::sub($subtotal, $totals[PosShiftTransaction::TYPE_CASH_RETURN], Decimal::MONEY_SCALE);
        $subtotal = Decimal::sub($subtotal, $totals[PosShiftTransaction::TYPE_EXPENSE], Decimal::MONEY_SCALE);

        return Decimal::sub($subtotal, $totals[PosShiftTransaction::TYPE_WITHDRAWAL], Decimal::MONEY_SCALE);
    }

    /**
     * Get list of open shifts (optionally scoped to a specific user).
     *
     * @return Collection<int, PosShift>
     */
    public function listOpenShifts(?User $user = null): Collection
    {
        return $this->queryService->listOpenShifts($user);
    }

    /**
     * Paginate POS shifts with filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateShifts(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->queryService->paginateShifts($filters, $perPage);
    }

    /**
     * Paginate cash transactions belonging to a shift.
     */
    public function paginateTransactions(PosShift $shift, int $perPage = 50): LengthAwarePaginator
    {
        return $this->queryService->paginateTransactions($shift, $perPage);
    }

    /**
     * Paginate POS sales belonging to a shift.
     */
    public function paginateSales(PosShift $shift, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginateSales($shift, $perPage);
    }

    /**
     * Get full details and summary for a shift.
     *
     * @return array<string, mixed>
     */
    public function getShiftSummary(PosShift $shift): array
    {
        $txTotals = $this->sumTransactionsByType($shift);
        $openingBalance = Decimal::fromNumeric($shift->opening_balance, Decimal::MONEY_SCALE);
        $expectedCash = $shift->isClosed()
            ? Decimal::fromNumeric($shift->expected_cash, Decimal::MONEY_SCALE)
            : $this->computeExpectedCash($openingBalance, $txTotals);

        return $this->queryService->getShiftSummary($shift, $txTotals, $expectedCash);
    }
}
