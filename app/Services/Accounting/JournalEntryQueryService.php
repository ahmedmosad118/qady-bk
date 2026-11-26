<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Support\Decimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class JournalEntryQueryService
{
    /** @var list<string> */
    private const ALLOWED_SORT_COLUMNS = [
        'entry_date',
        'entry_number',
        'status',
        'created_at',
        'id',
    ];

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
        } elseif ($journalEntry->source instanceof Expense) {
            $journalEntry->source->loadMissing([
                'contact:id,name',
                'journal:id,code,name',
                'items' => static fn ($q) => $q->orderBy('id'),
                'items.account:id,code,name',
                'items.category:id,name',
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
