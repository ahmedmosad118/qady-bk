<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Repositories\FiscalYearRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FiscalYearService
{
    private const FISCAL_YEAR_LOCK_NAME = 'qady_accounting_fiscal_year_write';

    private const LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        protected FiscalYearRepository $repository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->paginate($filters, $perPage);
    }

    /**
     * Create a new fiscal year while serializing concurrent overlap checks via a MySQL named lock.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FiscalYear
    {
        return $this->withSerializedFiscalYearLock(function () use ($data): FiscalYear {
            return DB::transaction(function () use ($data): FiscalYear {
                $code = trim((string) $data['code']);
                $startDate = (string) $data['start_date'];
                $endDate = (string) $data['end_date'];

                $this->assertValidDateRange($startDate, $endDate);
                $this->assertUniqueCode($code);
                $this->assertNoOverlap($startDate, $endDate);

                $status = isset($data['status'])
                    ? FiscalYearStatus::from((string) $data['status'])
                    : FiscalYearStatus::Draft;

                if ($status === FiscalYearStatus::Closed) {
                    throw ValidationException::withMessages([
                        'status' => [(string) __('accounting.errors.fiscal_year_close_requires_periods')],
                    ]);
                }

                try {
                    return $this->repository->create([
                        'code' => $code,
                        'name' => trim((string) $data['name']),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => $status->value,
                    ])->loadCount('accountingPeriods');
                } catch (QueryException $e) {
                    if ($this->isUniqueConstraintException($e)) {
                        throw ValidationException::withMessages([
                            'code' => [(string) __('accounting.errors.fiscal_year_code_duplicate')],
                        ]);
                    }

                    throw $e;
                }
            });
        });
    }

    /**
     * Update an existing fiscal year with concurrency-safe overlap and period-closing guards.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FiscalYear $fiscalYear, array $data): FiscalYear
    {
        return $this->withSerializedFiscalYearLock(function () use ($fiscalYear, $data): FiscalYear {
            return DB::transaction(function () use ($fiscalYear, $data): FiscalYear {
                /** @var FiscalYear $locked */
                $locked = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->id);

                if ($locked->status === FiscalYearStatus::Closed) {
                    throw ValidationException::withMessages([
                        'status' => [(string) __('accounting.errors.fiscal_year_closed_immutable')],
                    ]);
                }

                $code = trim((string) ($data['code'] ?? $locked->code));
                $startDate = (string) ($data['start_date'] ?? $locked->start_date->toDateString());
                $endDate = (string) ($data['end_date'] ?? $locked->end_date->toDateString());
                $status = isset($data['status'])
                    ? FiscalYearStatus::from((string) $data['status'])
                    : $locked->status;

                $this->assertValidDateRange($startDate, $endDate);

                if ($code !== $locked->code) {
                    $this->assertUniqueCode($code, $locked->id);
                }

                $this->assertNoOverlap($startDate, $endDate, $locked->id);
                $this->assertPeriodsFitWithinRange($locked->id, $startDate, $endDate);

                if ($status === FiscalYearStatus::Closed) {
                    $this->assertCanClose($locked->id);
                }

                try {
                    return $this->repository->update($locked, [
                        'code' => $code,
                        'name' => trim((string) ($data['name'] ?? $locked->name)),
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => $status->value,
                    ])->loadCount('accountingPeriods');
                } catch (QueryException $e) {
                    if ($this->isUniqueConstraintException($e)) {
                        throw ValidationException::withMessages([
                            'code' => [(string) __('accounting.errors.fiscal_year_code_duplicate')],
                        ]);
                    }

                    throw $e;
                }
            });
        });
    }

    /**
     * Safely delete an unused fiscal year with no accounting periods.
     */
    public function delete(FiscalYear $fiscalYear): bool
    {
        return DB::transaction(function () use ($fiscalYear): bool {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->lockForUpdate()->findOrFail($fiscalYear->id);

            if ($locked->status === FiscalYearStatus::Closed) {
                throw ValidationException::withMessages([
                    'fiscal_year' => [(string) __('accounting.errors.fiscal_year_closed_immutable')],
                ]);
            }

            if (AccountingPeriod::query()->where('fiscal_year_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'fiscal_year' => [(string) __('accounting.errors.fiscal_year_has_periods')],
                ]);
            }

            try {
                return $this->repository->delete($locked);
            } catch (QueryException $e) {
                if ($this->isForeignKeyConstraintException($e)) {
                    throw ValidationException::withMessages([
                        'fiscal_year' => [(string) __('accounting.errors.fiscal_year_has_periods')],
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Acquire a MySQL named lock before overlap checks and release it reliably in a finally block.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withSerializedFiscalYearLock(callable $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            return $callback();
        }

        $row = $connection->selectOne(
            'SELECT GET_LOCK(?, ?) AS lock_acquired',
            [self::FISCAL_YEAR_LOCK_NAME, self::LOCK_TIMEOUT_SECONDS]
        );

        if ((int) ($row->lock_acquired ?? 0) !== 1) {
            throw new RuntimeException((string) __('accounting.errors.fiscal_year_lock_timeout'));
        }

        try {
            return $callback();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS lock_released', [self::FISCAL_YEAR_LOCK_NAME]);
        }
    }

    private function assertUniqueCode(string $code, ?int $ignoreId = null): void
    {
        $exists = FiscalYear::query()
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => [(string) __('accounting.errors.fiscal_year_code_duplicate')],
            ]);
        }
    }

    private function assertValidDateRange(string $startDate, string $endDate): void
    {
        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'end_date' => [(string) __('accounting.errors.fiscal_year_invalid_date_range')],
            ]);
        }
    }

    private function assertNoOverlap(string $startDate, string $endDate, ?int $ignoreId = null): void
    {
        $exists = FiscalYear::query()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'start_date' => [(string) __('accounting.errors.fiscal_year_overlap')],
            ]);
        }
    }

    private function assertPeriodsFitWithinRange(int $fiscalYearId, string $startDate, string $endDate): void
    {
        $outside = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where(static function (Builder $q) use ($startDate, $endDate): void {
                $q->whereDate('start_date', '<', $startDate)
                    ->orWhereDate('end_date', '>', $endDate);
            })
            ->lockForUpdate()
            ->exists();

        if ($outside) {
            throw ValidationException::withMessages([
                'start_date' => [(string) __('accounting.errors.fiscal_year_shrink_excludes_periods')],
            ]);
        }
    }

    private function assertCanClose(int $fiscalYearId): void
    {
        $hasPeriods = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->lockForUpdate()
            ->exists();

        if (! $hasPeriods) {
            throw ValidationException::withMessages([
                'status' => [(string) __('accounting.errors.fiscal_year_close_requires_periods')],
            ]);
        }

        $hasOpen = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('status', '!=', AccountingPeriodStatus::Closed->value)
            ->lockForUpdate()
            ->exists();

        if ($hasOpen) {
            throw ValidationException::withMessages([
                'status' => [(string) __('accounting.errors.fiscal_year_close_requires_closed_periods')],
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
