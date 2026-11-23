<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Repositories\AccountingPeriodRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
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
     * Create a new accounting period within a fiscal year without overlapping dates.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): AccountingPeriod
    {
        return DB::transaction(function () use ($data, $userId): AccountingPeriod {
            $fiscalYear = $this->lockOpenFiscalYear((int) $data['fiscal_year_id']);
            $periodNumber = (int) $data['period_number'];
            $startDate = (string) $data['start_date'];
            $endDate = (string) $data['end_date'];

            $this->assertValidDates($fiscalYear, $startDate, $endDate);
            $this->assertUniquePeriodNumber($fiscalYear->id, $periodNumber);
            $this->assertNoOverlap($fiscalYear->id, $startDate, $endDate);

            $status = isset($data['status'])
                ? AccountingPeriodStatus::from((string) $data['status'])
                : AccountingPeriodStatus::Open;

            return $this->repository->create([
                'fiscal_year_id' => $fiscalYear->id,
                'name' => trim((string) $data['name']),
                'period_number' => $periodNumber,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status->value,
                ...$this->resolveClosureFields($status, null, $userId),
            ])->load(['fiscalYear:id,code,name,start_date,end_date,status', 'closedBy:id,name']);
        });
    }

    /**
     * Update an existing accounting period while enforcing fiscal year bounds and non-overlap.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AccountingPeriod $period, array $data, ?int $userId = null): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $data, $userId): AccountingPeriod {
            /** @var AccountingPeriod $locked */
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $fiscalYear = $this->lockOpenFiscalYear((int) ($data['fiscal_year_id'] ?? $locked->fiscal_year_id));

            $periodNumber = (int) ($data['period_number'] ?? $locked->period_number);
            $startDate = (string) ($data['start_date'] ?? $locked->start_date->toDateString());
            $endDate = (string) ($data['end_date'] ?? $locked->end_date->toDateString());
            $status = isset($data['status'])
                ? AccountingPeriodStatus::from((string) $data['status'])
                : $locked->status;

            $this->assertValidDates($fiscalYear, $startDate, $endDate);
            $this->assertUniquePeriodNumber($fiscalYear->id, $periodNumber, $locked->id);
            $this->assertNoOverlap($fiscalYear->id, $startDate, $endDate, $locked->id);

            return $this->repository->update($locked, [
                'fiscal_year_id' => $fiscalYear->id,
                'name' => trim((string) ($data['name'] ?? $locked->name)),
                'period_number' => $periodNumber,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => $status->value,
                ...$this->resolveClosureFields($status, $locked, $userId),
            ])->load(['fiscalYear:id,code,name,start_date,end_date,status', 'closedBy:id,name']);
        });
    }

    public function delete(AccountingPeriod $period): bool
    {
        return DB::transaction(function () use ($period): bool {
            /** @var AccountingPeriod $locked */
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $this->lockOpenFiscalYear($locked->fiscal_year_id);

            if ($locked->status === AccountingPeriodStatus::Closed) {
                throw ValidationException::withMessages([
                    'accounting_period' => [(string) __('accounting.errors.period_closed_cannot_delete')],
                ]);
            }

            return $this->repository->delete($locked);
        });
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

    /**
     * @return array{closed_at: mixed, closed_by: ?int}
     */
    private function resolveClosureFields(AccountingPeriodStatus $targetStatus, ?AccountingPeriod $existing, ?int $userId): array
    {
        if ($targetStatus === AccountingPeriodStatus::Open) {
            return ['closed_at' => null, 'closed_by' => null];
        }

        if ($existing && $existing->status === AccountingPeriodStatus::Closed) {
            return ['closed_at' => $existing->closed_at, 'closed_by' => $existing->closed_by];
        }

        return ['closed_at' => now(), 'closed_by' => $userId];
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
}
