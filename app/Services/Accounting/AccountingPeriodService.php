<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Repositories\AccountingPeriodRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountingPeriodService
{
    private const LOCK_RETRY_ATTEMPTS = 3;

    public function __construct(
        protected AccountingPeriodRepository $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    /**
     * Create an open accounting period within an open/draft fiscal year.
     * Lock ordering: FiscalYear first, then AccountingPeriod checks.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AccountingPeriod
    {
        return DB::transaction(function () use ($data): AccountingPeriod {
            if (isset($data['status']) && (string) $data['status'] !== AccountingPeriodStatus::Open->value) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('accounting.errors.period_closing_not_allowed_in_crud')],
                ]);
            }

            $fiscalYear = $this->lockOpenFiscalYear((int) $data['fiscal_year_id']);
            $periodNumber = (int) $data['period_number'];
            $startDate = (string) $data['start_date'];
            $endDate = (string) $data['end_date'];

            $this->assertValidDates($fiscalYear, $startDate, $endDate);
            $this->assertUniquePeriodNumber($fiscalYear->id, $periodNumber);
            $this->assertNoOverlap($fiscalYear->id, $startDate, $endDate);

            try {
                return $this->repository->create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'name' => trim((string) $data['name']),
                    'period_number' => $periodNumber,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => AccountingPeriodStatus::Open->value,
                    'closed_at' => null,
                    'closed_by' => null,
                ])->load(['fiscalYear:id,code,name,start_date,end_date,status', 'closedBy:id,name']);
            } catch (QueryException $e) {
                if ($this->isUniqueConstraintException($e)) {
                    throw ValidationException::withMessages([
                        'period_number' => [(string) __('accounting.errors.period_number_duplicate')],
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Update an open accounting period. Closed periods and status transitions to closed are rejected.
     * Lock ordering: FiscalYear(s) in ascending ID order first, then AccountingPeriod,
     * with automatic retry if fiscal_year_id changed concurrently before lock acquisition.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AccountingPeriod $period, array $data): AccountingPeriod
    {
        if (isset($data['status']) && (string) $data['status'] !== AccountingPeriodStatus::Open->value) {
            throw ValidationException::withMessages([
                'status' => [(string) __('accounting.errors.period_closing_not_allowed_in_crud')],
            ]);
        }

        for ($attempt = 1; $attempt <= self::LOCK_RETRY_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($period, $data): AccountingPeriod {
                    $observedFiscalYearId = (int) AccountingPeriod::query()
                        ->whereKey($period->id)
                        ->value('fiscal_year_id');

                    if ($observedFiscalYearId <= 0) {
                        AccountingPeriod::query()->findOrFail($period->id);
                    }

                    $targetFiscalYearId = (int) ($data['fiscal_year_id'] ?? $observedFiscalYearId);

                    // Lock FiscalYear(s) in deterministic ascending ID order before locking AccountingPeriod.
                    $fiscalYearsToLock = array_values(array_unique([$observedFiscalYearId, $targetFiscalYearId]));
                    sort($fiscalYearsToLock);

                    $targetFiscalYear = null;
                    foreach ($fiscalYearsToLock as $fyId) {
                        $lockedFy = $this->lockOpenFiscalYear($fyId);
                        if ($fyId === $targetFiscalYearId) {
                            $targetFiscalYear = $lockedFy;
                        }
                    }

                    /** @var AccountingPeriod $locked */
                    $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

                    // Recheck fiscal_year_id after locking AccountingPeriod to guard against concurrent moves.
                    if ($locked->fiscal_year_id !== $observedFiscalYearId) {
                        throw new RuntimeException('RETRY_FISCAL_YEAR_LOCK_ORDER');
                    }

                    if ($locked->status === AccountingPeriodStatus::Closed) {
                        throw ValidationException::withMessages([
                            'status' => [(string) __('accounting.errors.period_closed_immutable')],
                        ]);
                    }

                    /** @var FiscalYear $targetFiscalYear */
                    $periodNumber = (int) ($data['period_number'] ?? $locked->period_number);
                    $startDate = (string) ($data['start_date'] ?? $locked->start_date->toDateString());
                    $endDate = (string) ($data['end_date'] ?? $locked->end_date->toDateString());

                    $this->assertValidDates($targetFiscalYear, $startDate, $endDate);
                    $this->assertUniquePeriodNumber($targetFiscalYear->id, $periodNumber, $locked->id);
                    $this->assertNoOverlap($targetFiscalYear->id, $startDate, $endDate, $locked->id);

                    try {
                        return $this->repository->update($locked, [
                            'fiscal_year_id' => $targetFiscalYear->id,
                            'name' => trim((string) ($data['name'] ?? $locked->name)),
                            'period_number' => $periodNumber,
                            'start_date' => $startDate,
                            'end_date' => $endDate,
                            'status' => AccountingPeriodStatus::Open->value,
                        ])->load(['fiscalYear:id,code,name,start_date,end_date,status', 'closedBy:id,name']);
                    } catch (QueryException $e) {
                        if ($this->isUniqueConstraintException($e)) {
                            throw ValidationException::withMessages([
                                'period_number' => [(string) __('accounting.errors.period_number_duplicate')],
                            ]);
                        }

                        throw $e;
                    }
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'RETRY_FISCAL_YEAR_LOCK_ORDER' || $attempt === self::LOCK_RETRY_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Failed to acquire consistent fiscal year lock.');
    }

    /**
     * Safely delete an unused open accounting period.
     * Lock ordering: FiscalYear first, then AccountingPeriod with fiscal_year_id recheck.
     */
    public function delete(AccountingPeriod $period): bool
    {
        for ($attempt = 1; $attempt <= self::LOCK_RETRY_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(function () use ($period): bool {
                    $observedFiscalYearId = (int) AccountingPeriod::query()
                        ->whereKey($period->id)
                        ->value('fiscal_year_id');

                    if ($observedFiscalYearId <= 0) {
                        AccountingPeriod::query()->findOrFail($period->id);
                    }

                    $this->lockOpenFiscalYear($observedFiscalYearId);

                    /** @var AccountingPeriod $locked */
                    $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);

                    // Recheck fiscal_year_id after locking AccountingPeriod.
                    if ($locked->fiscal_year_id !== $observedFiscalYearId) {
                        throw new RuntimeException('RETRY_FISCAL_YEAR_LOCK_ORDER');
                    }

                    if ($locked->status === AccountingPeriodStatus::Closed) {
                        throw ValidationException::withMessages([
                            'accounting_period' => [(string) __('accounting.errors.period_closed_cannot_delete')],
                        ]);
                    }

                    try {
                        return $this->repository->delete($locked);
                    } catch (QueryException $e) {
                        if ($this->isForeignKeyConstraintException($e)) {
                            throw ValidationException::withMessages([
                                'accounting_period' => [(string) __('accounting.errors.period_in_use')],
                            ]);
                        }

                        throw $e;
                    }
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== 'RETRY_FISCAL_YEAR_LOCK_ORDER' || $attempt === self::LOCK_RETRY_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException('Failed to acquire consistent fiscal year lock.');
    }

    private function lockOpenFiscalYear(int $fiscalYearId): FiscalYear
    {
        /** @var FiscalYear $fiscalYear */
        $fiscalYear = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYearId);

        if ($fiscalYear->status === FiscalYearStatus::Closed) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => [(string) __('accounting.errors.fiscal_year_closed_for_periods')],
            ]);
        }

        return $fiscalYear;
    }

    private function assertValidDates(FiscalYear $fiscalYear, string $startDate, string $endDate): void
    {
        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'end_date' => [(string) __('accounting.errors.period_invalid_date_range')],
            ]);
        }

        if ($startDate < $fiscalYear->start_date->toDateString() || $endDate > $fiscalYear->end_date->toDateString()) {
            throw ValidationException::withMessages([
                'start_date' => [(string) __('accounting.errors.period_outside_fiscal_year')],
            ]);
        }
    }

    private function assertUniquePeriodNumber(int $fiscalYearId, int $periodNumber, ?int $ignoreId = null): void
    {
        $exists = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('period_number', $periodNumber)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'period_number' => [(string) __('accounting.errors.period_number_duplicate')],
            ]);
        }
    }

    private function assertNoOverlap(int $fiscalYearId, string $startDate, string $endDate, ?int $ignoreId = null): void
    {
        $exists = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_date' => [(string) __('accounting.errors.period_overlap')],
            ]);
        }
    }

    private function isUniqueConstraintException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = mb_strtolower($e->getMessage());

        return $driverCode === 1062
            || ($sqlState === '23000' && (str_contains($message, 'unique') || str_contains($message, 'duplicate')));
    }

    private function isForeignKeyConstraintException(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1451 || str_contains(mb_strtolower($e->getMessage()), 'foreign key constraint');
    }
}
