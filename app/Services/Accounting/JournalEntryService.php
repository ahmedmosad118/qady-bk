<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Support\DocumentSequenceGenerator;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalEntryService
{
    /** @var list<string> */
    private const ALLOWED_SORT_COLUMNS = [
        'entry_date',
        'entry_number',
        'status',
        'created_at',
        'id',
    ];

    public function __construct(
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Paginate journal entries with server-side filters, safe sorting, and line totals.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $perPage = min(max($perPage, 1), 100);

        $query = JournalEntry::query()
            ->with([
                'journal:id,code,name,type',
                'fiscalYear:id,code,name',
                'accountingPeriod:id,fiscal_year_id,name,period_number',
                'createdBy:id,name',
                'postedBy:id,name',
            ])
            ->withSum('lines as total_debit', 'debit')
            ->withSum('lines as total_credit', 'credit');

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        $paginated = $query->paginate($perPage);

        $paginated->getCollection()->transform(function (JournalEntry $entry): JournalEntry {
            return $this->attachTotals(
                $entry,
                Decimal::fromNumeric($entry->getAttribute('total_debit') ?? 0, Decimal::MONEY_SCALE),
                Decimal::fromNumeric($entry->getAttribute('total_credit') ?? 0, Decimal::MONEY_SCALE),
            );
        });

        return $paginated;
    }

    /**
     * Load full journal entry details including lines, accounts, and totals.
     */
    public function getDetails(JournalEntry $journalEntry): JournalEntry
    {
        $journalEntry->load([
            'journal:id,code,name,type',
            'fiscalYear:id,code,name,start_date,end_date,status',
            'accountingPeriod:id,fiscal_year_id,name,period_number,start_date,end_date,status',
            'createdBy:id,name',
            'postedBy:id,name',
            'lines' => static fn ($q) => $q->orderBy('line_number'),
            'lines.account:id,code,name,account_type_id,is_active',
        ]);

        $totalDebit = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $totalCredit = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        foreach ($journalEntry->lines as $line) {
            $totalDebit = Decimal::add($totalDebit, $line->debit, Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $line->credit, Decimal::MONEY_SCALE);
        }

        return $this->attachTotals($journalEntry, $totalDebit, $totalCredit);
    }

    /**
     * Create a new draft journal entry and its lines inside a single transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $user = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $user): JournalEntry {
            $this->assertDraftStatusRequested($data);

            $entryDate = Carbon::parse((string) $data['entry_date'])->toDateString();
            $this->validateAccountingContext(
                (int) $data['journal_id'],
                (int) $data['fiscal_year_id'],
                (int) $data['accounting_period_id'],
                $entryDate,
            );

            $preparedLines = $this->validateAndPrepareLines($data['lines'] ?? []);
            $year = Carbon::parse($entryDate)->format('Y');
            $entryNumber = $this->sequenceGenerator->next(
                JournalEntry::class,
                'entry_number',
                "JE-{$year}-",
                5,
            );

            $entry = JournalEntry::query()->create([
                'journal_id' => (int) $data['journal_id'],
                'fiscal_year_id' => (int) $data['fiscal_year_id'],
                'accounting_period_id' => (int) $data['accounting_period_id'],
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'reference' => isset($data['reference']) && $data['reference'] !== '' ? (string) $data['reference'] : null,
                'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
                'status' => JournalEntryStatus::Draft->value,
                'created_by' => $user?->id,
                'posted_by' => null,
                'posted_at' => null,
            ]);

            JournalEntry::query()
                ->findOrFail($entry->id)
                ->lines()
                ->createMany($preparedLines);

            return $this->getDetails($entry);
        });
    }

    /**
     * Update an existing draft journal entry and replace its lines atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(JournalEntry $journalEntry, array $data): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry, $data): JournalEntry {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($journalEntry->id);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('accounting.errors.journal_entry_posted_immutable')],
                ]);
            }

            $this->assertDraftStatusRequested($data);

            $entryDate = Carbon::parse((string) $data['entry_date'])->toDateString();
            $this->validateAccountingContext(
                (int) $data['journal_id'],
                (int) $data['fiscal_year_id'],
                (int) $data['accounting_period_id'],
                $entryDate,
            );

            $preparedLines = $this->validateAndPrepareLines($data['lines'] ?? []);

            $locked->update([
                'journal_id' => (int) $data['journal_id'],
                'fiscal_year_id' => (int) $data['fiscal_year_id'],
                'accounting_period_id' => (int) $data['accounting_period_id'],
                'entry_date' => $entryDate,
                'reference' => isset($data['reference']) && $data['reference'] !== '' ? (string) $data['reference'] : null,
                'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            ]);

            $locked->lines()->delete();

            JournalEntry::query()
                ->findOrFail($locked->id)
                ->lines()
                ->createMany($preparedLines);

            return $this->getDetails($locked);
        });
    }

    /**
     * Delete a draft journal entry and its lines inside a locked transaction.
     */
    public function delete(JournalEntry $journalEntry): void
    {
        DB::transaction(function () use ($journalEntry): void {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($journalEntry->id);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('accounting.errors.journal_entry_posted_cannot_delete')],
                ]);
            }

            $locked->lines()->delete();
            $locked->delete();
        });
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($search): void {
                $q->where('entry_number', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('lines', static function (Builder $lineQuery) use ($search): void {
                        $lineQuery->where('label', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['entry_number'])) {
            $entryNumber = trim((string) $filters['entry_number']);
            $query->where('entry_number', 'like', "%{$entryNumber}%");
        }

        if (! empty($filters['label'])) {
            $label = trim((string) $filters['label']);
            $query->where(static function (Builder $q) use ($label): void {
                $q->where('description', 'like', "%{$label}%")
                    ->orWhereHas('lines', static function (Builder $lineQuery) use ($label): void {
                        $lineQuery->where('label', 'like', "%{$label}%");
                    });
            });
        }

        if (! empty($filters['reference'])) {
            $reference = trim((string) $filters['reference']);
            $query->where('reference', 'like', "%{$reference}%");
        }

        if (! empty($filters['entry_date'])) {
            $query->whereDate('entry_date', (string) $filters['entry_date']);
        }

        if (! empty($filters['entry_date_from'])) {
            $query->whereDate('entry_date', '>=', (string) $filters['entry_date_from']);
        }

        if (! empty($filters['entry_date_to'])) {
            $query->whereDate('entry_date', '<=', (string) $filters['entry_date_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['journal_id'])) {
            $query->where('journal_id', (int) $filters['journal_id']);
        }

        if (! empty($filters['fiscal_year_id'])) {
            $query->where('fiscal_year_id', (int) $filters['fiscal_year_id']);
        }

        if (! empty($filters['accounting_period_id'])) {
            $query->where('accounting_period_id', (int) $filters['accounting_period_id']);
        }
    }

    /**
     * @param  Builder<JournalEntry>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = (string) ($filters['sort_by'] ?? '');
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $query->orderBy($sortBy, $sortOrder);
            if ($sortBy !== 'entry_number') {
                $query->orderBy('entry_number', $sortOrder);
            }
            $query->orderBy('id', $sortOrder);

            return;
        }

        $query->orderByDesc('entry_date')
            ->orderByDesc('entry_number')
            ->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertDraftStatusRequested(array $data): void
    {
        if (isset($data['status']) && (string) $data['status'] !== JournalEntryStatus::Draft->value) {
            throw ValidationException::withMessages([
                'status' => [(string) __('accounting.errors.journal_entry_status_must_be_draft')],
            ]);
        }
    }

    private function validateAccountingContext(
        int $journalId,
        int $fiscalYearId,
        int $accountingPeriodId,
        string $entryDate,
    ): void {
        $errors = [];

        $journal = Journal::query()->find($journalId);
        if ($journal === null || ! $journal->is_active) {
            $errors['journal_id'] = [(string) __('accounting.errors.journal_entry_journal_inactive')];
        }

        $fiscalYear = FiscalYear::query()->find($fiscalYearId);
        if ($fiscalYear === null || $fiscalYear->status !== FiscalYearStatus::Open) {
            $errors['fiscal_year_id'] = [(string) __('accounting.errors.journal_entry_fiscal_year_not_open')];
        } elseif ($entryDate < $fiscalYear->start_date->toDateString() || $entryDate > $fiscalYear->end_date->toDateString()) {
            $errors['entry_date'] = [(string) __('accounting.errors.journal_entry_date_outside_fiscal_year')];
        }

        $period = AccountingPeriod::query()->find($accountingPeriodId);
        if ($period === null) {
            $errors['accounting_period_id'] = [(string) __('accounting.errors.journal_entry_period_fiscal_year_mismatch')];
        } else {
            if ($period->fiscal_year_id !== $fiscalYearId) {
                $errors['accounting_period_id'] = [(string) __('accounting.errors.journal_entry_period_fiscal_year_mismatch')];
            } elseif ($period->status === AccountingPeriodStatus::Closed) {
                $errors['accounting_period_id'] = [(string) __('accounting.errors.journal_entry_period_closed')];
            } elseif ($entryDate < $period->start_date->toDateString() || $entryDate > $period->end_date->toDateString()) {
                $errors['entry_date'] = [(string) __('accounting.errors.journal_entry_date_outside_period')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Validate lines and normalize debit/credit amounts using BCMath.
     *
     * @param  array<int, mixed>  $rawLines
     * @return list<array{account_id: int, line_number: int, label: ?string, debit: string, credit: string}>
     */
    private function validateAndPrepareLines(array $rawLines): array
    {
        if (count($rawLines) < 2) {
            throw ValidationException::withMessages([
                'lines' => [(string) __('accounting.errors.journal_entry_min_lines')],
            ]);
        }

        $accountIds = array_values(array_unique(array_filter(array_map(
            static function (mixed $line): int {
                if (! is_array($line)) {
                    return 0;
                }

                $rawId = $line['account_id'] ?? null;
                $validId = is_bool($rawId) ? false : filter_var($rawId, FILTER_VALIDATE_INT);

                return ($validId !== false && $validId > 0) ? (int) $validId : 0;
            },
            $rawLines,
        ))));

        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'is_active'])
            ->keyBy('id');

        $errors = [];
        $prepared = [];
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $totalDebit = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $totalCredit = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        foreach (array_values($rawLines) as $index => $line) {
            if (! is_array($line)) {
                $errors["lines.{$index}"] = [(string) __('accounting.errors.journal_entry_line_invalid')];

                continue;
            }

            $rawAccountId = $line['account_id'] ?? null;
            $validatedAccountId = is_bool($rawAccountId) ? false : filter_var($rawAccountId, FILTER_VALIDATE_INT);

            if ($validatedAccountId === false || $validatedAccountId <= 0) {
                $errors["lines.{$index}.account_id"] = [(string) __('accounting.errors.journal_entry_account_not_found')];
                $accountId = 0;
            } else {
                $accountId = (int) $validatedAccountId;
                $account = $accounts->get($accountId);

                if ($account === null) {
                    $errors["lines.{$index}.account_id"] = [(string) __('accounting.errors.journal_entry_account_not_found')];
                } elseif (! $account->is_active) {
                    $errors["lines.{$index}.account_id"] = [(string) __('accounting.errors.journal_entry_account_inactive')];
                }
            }

            $debit = Decimal::fromNumeric($line['debit'] ?? 0, Decimal::MONEY_SCALE);
            $credit = Decimal::fromNumeric($line['credit'] ?? 0, Decimal::MONEY_SCALE);

            $debitNegative = Decimal::comp($debit, $zero, Decimal::MONEY_SCALE) < 0;
            $creditNegative = Decimal::comp($credit, $zero, Decimal::MONEY_SCALE) < 0;

            if ($debitNegative) {
                $errors["lines.{$index}.debit"] = [(string) __('accounting.errors.journal_entry_line_negative')];
            }

            if ($creditNegative) {
                $errors["lines.{$index}.credit"] = [(string) __('accounting.errors.journal_entry_line_negative')];
            }

            if (! $debitNegative && ! $creditNegative) {
                $debitPositive = Decimal::comp($debit, $zero, Decimal::MONEY_SCALE) > 0;
                $creditPositive = Decimal::comp($credit, $zero, Decimal::MONEY_SCALE) > 0;

                if ($debitPositive && $creditPositive) {
                    $errors["lines.{$index}.debit"] = [(string) __('accounting.errors.journal_entry_line_both_positive')];
                    $errors["lines.{$index}.credit"] = [(string) __('accounting.errors.journal_entry_line_both_positive')];
                } elseif (! $debitPositive && ! $creditPositive) {
                    $errors["lines.{$index}.debit"] = [(string) __('accounting.errors.journal_entry_line_both_zero')];
                    $errors["lines.{$index}.credit"] = [(string) __('accounting.errors.journal_entry_line_both_zero')];
                }
            }

            $lineNumber = isset($line['line_number']) && (int) $line['line_number'] > 0
                ? (int) $line['line_number']
                : ($index + 1);

            $label = isset($line['label']) && trim((string) $line['label']) !== ''
                ? trim((string) $line['label'])
                : null;

            $totalDebit = Decimal::add($totalDebit, $debit, Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $credit, Decimal::MONEY_SCALE);

            $prepared[] = [
                'account_id' => $accountId,
                'line_number' => $lineNumber,
                'label' => $label,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        if ($errors === [] && Decimal::comp($totalDebit, $totalCredit, Decimal::MONEY_SCALE) !== 0) {
            $errors['lines'] = [(string) __('accounting.errors.journal_entry_unbalanced')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $prepared;
    }

    /**
     * @param  list<array{account_id: int, line_number: int, label: ?string, debit: string, credit: string}>  $lines
     */
    private function attachTotals(JournalEntry $entry, string $totalDebit, string $totalCredit): JournalEntry
    {
        $difference = Decimal::sub($totalDebit, $totalCredit, Decimal::MONEY_SCALE);
        $isBalanced = Decimal::comp($totalDebit, $totalCredit, Decimal::MONEY_SCALE) === 0;

        $entry->setAttribute('total_debit', $totalDebit);
        $entry->setAttribute('total_credit', $totalCredit);
        $entry->setAttribute('difference', $difference);
        $entry->setAttribute('is_balanced', $isBalanced);
        $entry->syncOriginal();

        return $entry;
    }
}
