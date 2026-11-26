<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalType;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class JournalEntryContextService
{
    /**
     * Resolve and validate a journal of the expected type, open fiscal year, and matching open period for a date.
     *
     * @return array{journal_id: int, fiscal_year_id: int, accounting_period_id: int}
     */
    public function resolveContext(int $journalId, JournalType $expectedType, string $entryDate): array
    {
        $normalizedDate = Carbon::parse($entryDate)->toDateString();

        $journal = Journal::query()->find($journalId);

        if (! $journal || ! $journal->is_active || $journal->type !== $expectedType) {
            $errorKey = match ($expectedType) {
                JournalType::Purchase => 'accounting.errors.purchase_journal_missing',
                JournalType::Expense => 'accounting.errors.expense_journal_missing',
                default => 'accounting.errors.journal_type_mismatch',
            };

            throw ValidationException::withMessages([
                'journal_id' => [(string) __($errorKey)],
            ]);
        }

        $fiscalYear = FiscalYear::query()
            ->where('status', FiscalYearStatus::Open->value)
            ->whereDate('start_date', '<=', $normalizedDate)
            ->whereDate('end_date', '>=', $normalizedDate)
            ->first();

        if (! $fiscalYear) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => [(string) __('accounting.errors.no_open_fiscal_year_for_date')],
            ]);
        }

        $period = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->where('status', AccountingPeriodStatus::Open->value)
            ->whereDate('start_date', '<=', $normalizedDate)
            ->whereDate('end_date', '>=', $normalizedDate)
            ->first();

        if (! $period) {
            throw ValidationException::withMessages([
                'accounting_period_id' => [(string) __('accounting.errors.no_open_period_for_date')],
            ]);
        }

        return [
            'journal_id' => $journal->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
        ];
    }

    /**
     * Resolve and validate the selected Purchase journal, open fiscal year, and matching open period for a date.
     *
     * @return array{journal_id: int, fiscal_year_id: int, accounting_period_id: int}
     */
    public function resolvePurchaseContext(int $journalId, string $entryDate): array
    {
        return $this->resolveContext($journalId, JournalType::Purchase, $entryDate);
    }

    /**
     * Resolve and validate the selected Expense journal, open fiscal year, and matching open period for a date.
     *
     * @return array{journal_id: int, fiscal_year_id: int, accounting_period_id: int}
     */
    public function resolveExpenseContext(int $journalId, string $entryDate): array
    {
        return $this->resolveContext($journalId, JournalType::Expense, $entryDate);
    }
}
