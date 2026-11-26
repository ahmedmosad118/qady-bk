<?php

declare(strict_types=1);

namespace App\Listeners\Expense;

use App\Enums\Accounting\AccountTypeCode;
use App\Events\Expense\ExpenseCreated;
use App\Events\Expense\ExpenseUpdated;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Services\Accounting\AccountingSettingService;
use App\Services\Accounting\JournalEntryService;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SyncExpenseJournalEntry
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected AccountingSettingService $accountingSettingService,
    ) {}

    public function handleCreated(ExpenseCreated $event): void
    {
        $payload = $this->buildEntryPayload($event->expense);

        $this->journalEntryService->createDraftForSource(
            $payload,
            $event->expense,
            $event->user,
        );
    }

    public function handleUpdated(ExpenseUpdated $event): void
    {
        $payload = $this->buildEntryPayload($event->expense);

        $this->journalEntryService->updateDraftForSource(
            $event->expense,
            $payload,
        );
    }

    /**
     * Build the balanced Journal Entry payload for an Expense with one debit line per item.
     *
     * @return array<string, mixed>
     */
    public function buildEntryPayload(Expense $expense): array
    {
        $expense->loadMissing([
            'items' => static fn ($q) => $q->orderBy('id'),
            'items.category:id,name',
            'items.account:id,code,name',
            'contact:id,name,payable_account_id',
        ]);

        $items = $expense->items;
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => [__('expenses.errors.at_least_one_item')],
            ]);
        }

        $accountIds = $items
            ->pluck('account_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, Account> $accounts */
        $accounts = $accountIds === []
            ? collect()
            : Account::query()
                ->with('accountType:id,code')
                ->whereIn('id', $accountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        $lines = [];
        $lineNumber = 1;
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        /** @var ExpenseItem $item */
        foreach ($items as $idx => $item) {
            /** @var Account|null $account */
            $account = $accounts->get((int) $item->account_id);

            if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Expenses->value) {
                throw ValidationException::withMessages([
                    "items.{$idx}.account_id" => ['Selected account must be an active expense account.'],
                ]);
            }

            $amount = Decimal::fromNumeric($item->amount, Decimal::MONEY_SCALE);
            if (Decimal::comp($amount, $zero, Decimal::MONEY_SCALE) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$idx}.amount" => [__('expenses.errors.item_amount_positive')],
                ]);
            }

            $lines[] = [
                'account_id' => $account->id,
                'line_number' => $lineNumber++,
                'label' => $this->resolveItemLabel($item, $expense),
                'debit' => $amount,
                'credit' => '0.00',
            ];
        }

        $payableAccount = $this->resolvePayableAccount($expense);
        $expenseTotal = Decimal::fromNumeric($expense->total, Decimal::MONEY_SCALE);

        $lines[] = [
            'account_id' => $payableAccount->id,
            'line_number' => $lineNumber,
            'label' => $expense->contact?->name ?? $expense->expense_number,
            'debit' => '0.00',
            'credit' => $expenseTotal,
        ];

        $this->assertBalancedPayload($lines, $expenseTotal);

        $entryDate = $expense->expense_date->toDateString();
        $context = $this->journalEntryService->resolveExpenseContext((int) $expense->journal_id, $entryDate);

        return [
            'journal_id' => $context['journal_id'],
            'fiscal_year_id' => $context['fiscal_year_id'],
            'accounting_period_id' => $context['accounting_period_id'],
            'contact_id' => $expense->contact_id,
            'entry_date' => $entryDate,
            'reference' => $expense->expense_number,
            'description' => (string) __('accounting.labels.expense_entry_description', ['number' => $expense->expense_number]),
            'lines' => $lines,
        ];
    }

    private function resolveItemLabel(ExpenseItem $item, Expense $expense): string
    {
        $description = trim((string) ($item->description ?? ''));
        if ($description !== '') {
            return $description;
        }

        $categoryName = trim((string) ($item->category?->name ?? ''));
        if ($categoryName !== '') {
            return $categoryName;
        }

        return (string) $expense->expense_number;
    }

    private function resolvePayableAccount(Expense $expense): Account
    {
        $supplierAccountId = $expense->contact?->payable_account_id;

        if ($supplierAccountId !== null) {
            $account = Account::query()
                ->with('accountType:id,code')
                ->lockForUpdate()
                ->find((int) $supplierAccountId);

            if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Payable->value) {
                throw ValidationException::withMessages([
                    'contact_id' => [(string) __('accounting.errors.supplier_payable_account_invalid')],
                ]);
            }

            return $account;
        }

        $defaultAccountId = $this->accountingSettingService->resolveAccountId('accounts_payable');
        if ($defaultAccountId === null) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('accounting.errors.supplier_payable_account_missing')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find($defaultAccountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Payable->value) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('accounting.errors.supplier_payable_account_invalid')],
            ]);
        }

        return $account;
    }

    /**
     * @param  list<array{account_id: int, line_number: int, label: string, debit: string, credit: string}>  $lines
     */
    private function assertBalancedPayload(array $lines, string $expenseTotal): void
    {
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        if (count($lines) < 2 || Decimal::comp($expenseTotal, $zero, Decimal::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'lines' => [(string) __('accounting.errors.journal_entry_unbalanced')],
            ]);
        }

        $totalDebit = $zero;
        $totalCredit = $zero;

        foreach ($lines as $line) {
            $totalDebit = Decimal::add($totalDebit, $line['debit'], Decimal::MONEY_SCALE);
            $totalCredit = Decimal::add($totalCredit, $line['credit'], Decimal::MONEY_SCALE);
        }

        if (Decimal::comp($totalDebit, $totalCredit, Decimal::MONEY_SCALE) !== 0
            || Decimal::comp($totalDebit, $expenseTotal, Decimal::MONEY_SCALE) !== 0) {
            throw ValidationException::withMessages([
                'lines' => [(string) __('accounting.errors.journal_entry_unbalanced')],
            ]);
        }
    }
}
