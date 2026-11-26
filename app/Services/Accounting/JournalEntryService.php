<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\JournalType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Contact;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Support\DocumentSequenceGenerator;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
                'contact:id,name',
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
            'contact:id,name',
            'createdBy:id,name',
            'postedBy:id,name',
            'source',
            'lines' => static fn ($q) => $q->orderBy('line_number'),
            'lines.account:id,code,name,account_type_id,is_active',
        ]);

        if ($journalEntry->source instanceof Payment) {
            $journalEntry->source->loadMissing([
                'contact:id,name',
                'account:id,code,name',
                'paymentMethod:id,name',
                'journal:id,code,name',
                'allocations.allocatable',
            ]);
        } elseif ($journalEntry->source instanceof PurchaseInvoice) {
            $journalEntry->source->loadMissing([
                'supplier:id,name',
                'journal:id,code,name',
            ]);
        }

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
        $this->assertDraftStatusRequested($data);

        return $this->persistNewEntry($data, $user);
    }

    /**
     * Create a posted journal entry linked to an operational source model.
     *
     * @param  array<string, mixed>  $data
     */
    public function createFromSource(
        array $data,
        Model $source,
        ?User $user = null,
    ): JournalEntry {
        $sourceKey = $source->getKey();

        if (! $source->exists || $sourceKey === null || $sourceKey === '' || $sourceKey === 0) {
            throw ValidationException::withMessages([
                'source' => ['The source model must be persisted with a valid primary key.'],
            ]);
        }

        $sourceType = $source->getMorphClass();
        $existing = $this->findBySource($sourceType, $sourceKey);

        if ($existing !== null) {
            return $this->getDetails($existing);
        }

        try {
            return $this->persistNewEntry(
                data: $data,
                user: $user,
                status: JournalEntryStatus::Posted,
                source: $source,
            );
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findBySource($sourceType, $sourceKey, lock: true);

            if ($existing !== null) {
                return $this->getDetails($existing);
            }

            throw $exception;
        }
    }

    /**
     * Persist a new journal entry and its lines inside a single transaction.
     *
     * @param  array<string, mixed>  $data
     */
    private function persistNewEntry(
        array $data,
        ?User $user = null,
        JournalEntryStatus $status = JournalEntryStatus::Draft,
        ?Model $source = null,
    ): JournalEntry {
        return DB::transaction(function () use ($data, $user, $status, $source): JournalEntry {
            $entryDate = Carbon::parse((string) $data['entry_date'])->toDateString();
            $this->validateAccountingContext(
                (int) $data['journal_id'],
                (int) $data['fiscal_year_id'],
                (int) $data['accounting_period_id'],
                $entryDate,
            );

            $preparedLines = $this->validateAndPrepareLines($data['lines'] ?? []);
            $contactId = $this->resolveOptionalContactId($data['contact_id'] ?? null);
            $year = Carbon::parse($entryDate)->format('Y');
            $entryNumber = $this->sequenceGenerator->next(
                JournalEntry::class,
                'entry_number',
                "JE-{$year}-",
                5,
            );

            $isPosted = $status === JournalEntryStatus::Posted;

            $entry = JournalEntry::query()->create([
                'journal_id' => (int) $data['journal_id'],
                'fiscal_year_id' => (int) $data['fiscal_year_id'],
                'accounting_period_id' => (int) $data['accounting_period_id'],
                'contact_id' => $contactId,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'reference' => isset($data['reference']) && $data['reference'] !== '' ? (string) $data['reference'] : null,
                'description' => isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'status' => $status->value,
                'created_by' => $user?->id,
                'posted_by' => $isPosted ? $user?->id : null,
                'posted_at' => $isPosted ? now() : null,
            ]);

            JournalEntry::query()
                ->findOrFail($entry->id)
                ->lines()
                ->createMany($preparedLines);

            return $this->getDetails($entry);
        });
    }

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
            throw ValidationException::withMessages([
                'journal_id' => [(string) __('accounting.errors.purchase_journal_missing')],
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
     * Create a draft journal entry linked to a persisted source model (idempotent).
     *
     * @param  array<string, mixed>  $data
     */
    public function createDraftForSource(
        array $data,
        Model $source,
        ?User $user = null,
    ): JournalEntry {
        $sourceKey = $this->assertPersistedSource($source);
        $sourceType = $source->getMorphClass();
        $existing = $this->findBySource($sourceType, $sourceKey, lock: true);

        if ($existing !== null) {
            return $this->getDetails($existing);
        }

        try {
            return $this->persistNewEntry(
                data: $data,
                user: $user,
                status: JournalEntryStatus::Draft,
                source: $source,
            );
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = $this->findBySource($sourceType, $sourceKey, lock: true);

            if ($existing !== null) {
                return $this->getDetails($existing);
            }

            throw $exception;
        }
    }

    /**
     * Update the linked draft journal entry from its persisted source model.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraftForSource(Model $source, array $data): JournalEntry
    {
        return DB::transaction(function () use ($source, $data): JournalEntry {
            $sourceKey = $this->assertPersistedSource($source);
            $locked = $this->findBySource($source->getMorphClass(), $sourceKey, lock: true);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'journal_entry' => [(string) __('accounting.errors.journal_entry_missing_for_source')],
                ]);
            }

            $this->assertEntryBelongsToSource($locked, $source);

            if (! $locked->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('accounting.errors.journal_entry_posted_immutable')],
                ]);
            }

            $entryDate = Carbon::parse((string) $data['entry_date'])->toDateString();
            $this->validateAccountingContext(
                (int) $data['journal_id'],
                (int) $data['fiscal_year_id'],
                (int) $data['accounting_period_id'],
                $entryDate,
            );

            $preparedLines = $this->validateAndPrepareLines($data['lines'] ?? []);
            $contactId = array_key_exists('contact_id', $data)
                ? $this->resolveOptionalContactId($data['contact_id'])
                : $locked->contact_id;

            $locked->update([
                'journal_id' => (int) $data['journal_id'],
                'fiscal_year_id' => (int) $data['fiscal_year_id'],
                'accounting_period_id' => (int) $data['accounting_period_id'],
                'contact_id' => $contactId,
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
     * Post the linked draft journal entry from its persisted source model.
     */
    public function postForSource(Model $source, User $user): JournalEntry
    {
        return DB::transaction(function () use ($source, $user): JournalEntry {
            $sourceKey = $this->assertPersistedSource($source);
            $locked = $this->findBySource($source->getMorphClass(), $sourceKey, lock: true);

            if ($locked === null) {
                throw ValidationException::withMessages([
                    'journal_entry' => [(string) __('accounting.errors.journal_entry_missing_for_source')],
                ]);
            }

            $this->assertEntryBelongsToSource($locked, $source);

            return $this->postEntryInternal($locked, $user);
        });
    }

    /**
     * Delete the linked draft journal entry when its source document is cancelled or deleted.
     */
    public function deleteDraftForSource(Model $source): void
    {
        DB::transaction(function () use ($source): void {
            $sourceKey = $this->assertPersistedSource($source);
            $locked = $this->findBySource($source->getMorphClass(), $sourceKey, lock: true);

            if ($locked === null) {
                return;
            }

            $this->assertEntryBelongsToSource($locked, $source);

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
     * Update an existing draft journal entry and replace its lines atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(JournalEntry $journalEntry, array $data): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry, $data): JournalEntry {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($journalEntry->id);

            $this->assertNotPurchaseInvoiceSource($locked);

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
            $contactId = array_key_exists('contact_id', $data)
                ? $this->resolveOptionalContactId($data['contact_id'])
                : $locked->contact_id;

            $locked->update([
                'journal_id' => (int) $data['journal_id'],
                'fiscal_year_id' => (int) $data['fiscal_year_id'],
                'accounting_period_id' => (int) $data['accounting_period_id'],
                'contact_id' => $contactId,
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

            $this->assertNotPurchaseInvoiceSource($locked);

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
     * Post a draft journal entry atomically after revalidating its context and lines.
     */
    public function post(JournalEntry $journalEntry, ?User $user = null): JournalEntry
    {
        return DB::transaction(function () use ($journalEntry, $user): JournalEntry {
            /** @var JournalEntry $locked */
            $locked = JournalEntry::query()->lockForUpdate()->findOrFail($journalEntry->id);

            $this->assertNotPurchaseInvoiceSource($locked);

            return $this->postEntryInternal($locked, $user);
        });
    }

    private function postEntryInternal(JournalEntry $locked, ?User $user = null): JournalEntry
    {
        if (! $locked->isDraft()) {
            throw ValidationException::withMessages([
                'status' => [(string) __('accounting.errors.journal_entry_already_posted')],
            ]);
        }

        $this->validateAccountingContext(
            $locked->journal_id,
            $locked->fiscal_year_id,
            $locked->accounting_period_id,
            $locked->entry_date->toDateString(),
        );

        $rawLines = $locked->lines()
            ->orderBy('line_number')
            ->get(['account_id', 'line_number', 'label', 'debit', 'credit'])
            ->map(static fn (JournalEntryLine $line): array => [
                'account_id' => $line->account_id,
                'line_number' => $line->line_number,
                'label' => $line->label,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ])
            ->all();

        $this->validateAndPrepareLines($rawLines);

        $locked->update([
            'status' => JournalEntryStatus::Posted->value,
            'posted_by' => $user?->id,
            'posted_at' => now(),
        ]);

        return $this->getDetails($locked);
    }

    private function assertNotPurchaseInvoiceSource(JournalEntry $entry): void
    {
        if ($entry->source_type !== null && $entry->source_type !== '') {
            throw ValidationException::withMessages([
                'source' => [(string) __('accounting.errors.journal_entry_purchase_invoice_managed_only')],
            ]);
        }
    }

    private function assertPersistedSource(Model $source): mixed
    {
        $sourceKey = $source->getKey();

        if (! $source->exists || $sourceKey === null || $sourceKey === '' || $sourceKey === 0) {
            throw ValidationException::withMessages([
                'source' => ['The source model must be persisted with a valid primary key.'],
            ]);
        }

        return $sourceKey;
    }

    private function assertEntryBelongsToSource(JournalEntry $entry, Model $source): void
    {
        $expectedType = $source->getMorphClass();

        if ($entry->source_type !== $expectedType || (int) $entry->source_id !== (int) $source->getKey()) {
            throw ValidationException::withMessages([
                'source' => [(string) __('accounting.errors.journal_entry_missing_for_source')],
            ]);
        }
    }

    private function resolveOptionalContactId(mixed $rawContactId): ?int
    {
        if ($rawContactId === null || $rawContactId === '') {
            return null;
        }

        $validated = is_bool($rawContactId) ? false : filter_var($rawContactId, FILTER_VALIDATE_INT);
        if ($validated === false || (int) $validated <= 0) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('validation.exists', ['attribute' => 'contact_id'])],
            ]);
        }

        $contactId = (int) $validated;
        if (! Contact::query()->whereKey($contactId)->exists()) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('validation.exists', ['attribute' => 'contact_id'])],
            ]);
        }

        return $contactId;
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
            } elseif ($period->status !== AccountingPeriodStatus::Open) {
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

    protected function findBySource(string $sourceType, mixed $sourceId, bool $lock = false): ?JournalEntry
    {
        $query = JournalEntry::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isUniqueConstraintViolation(Throwable $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        if (! $exception instanceof QueryException) {
            return false;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = mb_strtolower($exception->getMessage());

        if ($sqlState === '23505' || $driverCode === 1062 || $driverCode === 2067) {
            return true;
        }

        return $sqlState === '23000'
            && (str_contains($message, 'unique constraint') || str_contains($message, 'duplicate entry'));
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
