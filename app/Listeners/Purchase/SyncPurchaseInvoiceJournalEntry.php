<?php

declare(strict_types=1);

namespace App\Listeners\Purchase;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\PurchaseExpensesTreatment;
use App\Events\Purchase\PurchaseInvoiceCancelled;
use App\Events\Purchase\PurchaseInvoiceCreated;
use App\Events\Purchase\PurchaseInvoiceDeleted;
use App\Events\Purchase\PurchaseInvoicePosted;
use App\Events\Purchase\PurchaseInvoiceUpdated;
use App\Models\Account;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceExpense;
use App\Models\PurchaseInvoiceItem;
use App\Services\Accounting\AccountingSettingService;
use App\Services\Accounting\JournalEntryService;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SyncPurchaseInvoiceJournalEntry
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected AccountingSettingService $accountingSettingService,
    ) {}

    public function handleCreated(PurchaseInvoiceCreated $event): void
    {
        $payload = $this->buildEntryPayload($event->invoice);

        $this->journalEntryService->createDraftForSource(
            $payload,
            $event->invoice,
            $event->user,
        );
    }

    public function handleUpdated(PurchaseInvoiceUpdated $event): void
    {
        $payload = $this->buildEntryPayload($event->invoice);

        $this->journalEntryService->updateDraftForSource(
            $event->invoice,
            $payload,
        );
    }

    public function handlePosted(PurchaseInvoicePosted $event): void
    {
        $payload = $this->buildEntryPayload($event->invoice);

        $this->journalEntryService->updateDraftForSource(
            $event->invoice,
            $payload,
        );

        $this->journalEntryService->postForSource(
            $event->invoice,
            $event->user,
        );
    }

    public function handleCancelled(PurchaseInvoiceCancelled $event): void
    {
        $this->journalEntryService->deleteDraftForSource($event->invoice);
    }

    public function handleDeleted(PurchaseInvoiceDeleted $event): void
    {
        $this->journalEntryService->deleteDraftForSource($event->invoice);
    }

    /**
     * Build the balanced Journal Entry payload for a Purchase Invoice with one debit line per item.
     *
     * @return array<string, mixed>
     */
    public function buildEntryPayload(PurchaseInvoice $invoice): array
    {
        $invoice->loadMissing([
            'items' => static fn ($q) => $q->orderBy('id'),
            'items.product:id,name,category_id',
            'items.product.category:id,name,stock_input_account_id',
            'supplier:id,name,payable_account_id',
        ]);

        $items = $invoice->items;
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => [(string) __('purchases.errors.items_required')],
            ]);
        }

        $categoryAccountIds = $items
            ->map(static fn (PurchaseInvoiceItem $item): ?int => $item->product?->category?->stock_input_account_id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categoryAccounts = $categoryAccountIds === []
            ? collect()
            : Account::query()
                ->with('accountType:id,code')
                ->whereIn('id', $categoryAccountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        $defaultStockInput = null;

        /** @var list<array{account_id: int, label: string, amount: string}> $resolvedItems */
        $resolvedItems = [];
        $netLinesTotal = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        foreach ($items as $item) {
            $account = $this->resolveItemStockInputAccount($item, $categoryAccounts, $defaultStockInput);
            $lineTotal = Decimal::fromNumeric($item->line_total, Decimal::MONEY_SCALE);
            $netLinesTotal = Decimal::add($netLinesTotal, $lineTotal, Decimal::MONEY_SCALE);

            $resolvedItems[] = [
                'account_id' => $account->id,
                'label' => $this->resolveItemLabel($item, $invoice),
                'amount' => $lineTotal,
            ];
        }

        $discountAmount = Decimal::fromNumeric($invoice->discount_amount ?? 0, Decimal::MONEY_SCALE);
        $resolvedItems = $this->allocateDiscountProportionally($resolvedItems, $discountAmount, $netLinesTotal);

        $netAfterDiscountTotal = Decimal::sub($netLinesTotal, $discountAmount, Decimal::MONEY_SCALE);
        $expensesTotal = Decimal::fromNumeric($invoice->additional_expenses_total ?? 0, Decimal::MONEY_SCALE);
        $treatment = $this->accountingSettingService->resolveExpensesTreatment();

        $separateExpenseLines = [];
        if (Decimal::comp($expensesTotal, '0.00', Decimal::MONEY_SCALE) > 0) {
            if ($treatment === PurchaseExpensesTreatment::LandedCost) {
                $resolvedItems = $this->allocateLandedCostProportionally(
                    $resolvedItems,
                    $expensesTotal,
                    $netAfterDiscountTotal,
                );
            } else {
                $separateExpenseLines = $this->buildSeparateExpenseLines($invoice, $expensesTotal);
            }
        }

        $lines = [];
        $lineNumber = 1;
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        foreach ($resolvedItems as $row) {
            if (Decimal::comp($row['amount'], $zero, Decimal::MONEY_SCALE) <= 0) {
                continue;
            }

            $lines[] = [
                'account_id' => $row['account_id'],
                'line_number' => $lineNumber++,
                'label' => $row['label'],
                'debit' => $row['amount'],
                'credit' => '0.00',
            ];
        }

        foreach ($separateExpenseLines as $expenseLine) {
            $expenseLine['line_number'] = $lineNumber++;
            $lines[] = $expenseLine;
        }

        $payableAccount = $this->resolveSupplierPayableAccount($invoice);
        $invoiceTotal = Decimal::fromNumeric($invoice->total, Decimal::MONEY_SCALE);

        $lines[] = [
            'account_id' => $payableAccount->id,
            'line_number' => $lineNumber,
            'label' => $invoice->supplier?->name ?? $invoice->invoice_number,
            'debit' => '0.00',
            'credit' => $invoiceTotal,
        ];

        $this->assertBalancedPayload($lines, $invoiceTotal);

        $entryDate = $invoice->invoice_date->toDateString();
        $context = $this->journalEntryService->resolvePurchaseContext((int) $invoice->journal_id, $entryDate);

        return [
            'journal_id' => $context['journal_id'],
            'fiscal_year_id' => $context['fiscal_year_id'],
            'accounting_period_id' => $context['accounting_period_id'],
            'entry_date' => $entryDate,
            'reference' => $invoice->invoice_number,
            'description' => (string) __('accounting.labels.purchase_invoice_entry_description', ['number' => $invoice->invoice_number]),
            'lines' => $lines,
        ];
    }

    /**
     * Allocate invoice-level discount proportionally across positive items without ever producing negative amounts.
     *
     * @param  list<array{account_id: int, label: string, amount: string}>  $items
     * @return list<array{account_id: int, label: string, amount: string}>
     */
    private function allocateDiscountProportionally(
        array $items,
        string $discountAmount,
        string $netLinesTotal,
    ): array {
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        if ($items === [] || Decimal::comp($discountAmount, $zero, Decimal::MONEY_SCALE) <= 0) {
            return $items;
        }

        if (Decimal::comp($discountAmount, $netLinesTotal, Decimal::MONEY_SCALE) > 0) {
            throw ValidationException::withMessages([
                'discount_value' => [(string) __('purchases.errors.discount_exceeds_subtotal')],
            ]);
        }

        $eligibleIndices = $this->positiveIndices($items);
        if ($eligibleIndices === []) {
            return $items;
        }

        $shares = [];
        $sumShares = $zero;

        foreach ($eligibleIndices as $idx) {
            $rawShare = Decimal::div(
                Decimal::mul($items[$idx]['amount'], $discountAmount, Decimal::CALCULATION_SCALE),
                $netLinesTotal,
                Decimal::CALCULATION_SCALE,
            );
            $share = Decimal::round($rawShare, Decimal::MONEY_SCALE);
            if (Decimal::comp($share, $items[$idx]['amount'], Decimal::MONEY_SCALE) > 0) {
                $share = $items[$idx]['amount'];
            }
            $shares[$idx] = $share;
            $sumShares = Decimal::add($sumShares, $share, Decimal::MONEY_SCALE);
        }

        $remainder = Decimal::sub($discountAmount, $sumShares, Decimal::MONEY_SCALE);
        if (Decimal::comp($remainder, $zero, Decimal::MONEY_SCALE) !== 0) {
            foreach (array_reverse($eligibleIndices) as $idx) {
                $comp = Decimal::comp($remainder, $zero, Decimal::MONEY_SCALE);
                if ($comp === 0) {
                    break;
                }

                if ($comp > 0) {
                    $capacity = Decimal::sub($items[$idx]['amount'], $shares[$idx], Decimal::MONEY_SCALE);
                    $delta = Decimal::comp($remainder, $capacity, Decimal::MONEY_SCALE) <= 0 ? $remainder : $capacity;
                    $shares[$idx] = Decimal::add($shares[$idx], $delta, Decimal::MONEY_SCALE);
                    $remainder = Decimal::sub($remainder, $delta, Decimal::MONEY_SCALE);
                } else {
                    $absRem = Decimal::sub($zero, $remainder, Decimal::MONEY_SCALE);
                    $capacity = $shares[$idx];
                    $delta = Decimal::comp($absRem, $capacity, Decimal::MONEY_SCALE) <= 0 ? $absRem : $capacity;
                    $shares[$idx] = Decimal::sub($shares[$idx], $delta, Decimal::MONEY_SCALE);
                    $remainder = Decimal::add($remainder, $delta, Decimal::MONEY_SCALE);
                }
            }
        }

        foreach ($eligibleIndices as $idx) {
            $items[$idx]['amount'] = Decimal::sub($items[$idx]['amount'], $shares[$idx], Decimal::MONEY_SCALE);
        }

        return $items;
    }

    /**
     * Allocate LANDED_COST additional expenses proportionally across eligible item lines.
     *
     * @param  list<array{account_id: int, label: string, amount: string}>  $items
     * @return list<array{account_id: int, label: string, amount: string}>
     */
    private function allocateLandedCostProportionally(
        array $items,
        string $expensesTotal,
        string $baseTotal,
    ): array {
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        if ($items === [] || Decimal::comp($expensesTotal, $zero, Decimal::MONEY_SCALE) <= 0) {
            return $items;
        }

        $basePositive = Decimal::comp($baseTotal, $zero, Decimal::MONEY_SCALE) > 0;
        $eligibleIndices = $basePositive ? $this->positiveIndices($items) : array_keys($items);
        if ($eligibleIndices === []) {
            return $items;
        }

        $count = count($eligibleIndices);
        $allocated = $zero;

        foreach ($eligibleIndices as $pos => $idx) {
            $isLast = ($pos === $count - 1);

            if ($isLast) {
                $share = Decimal::sub($expensesTotal, $allocated, Decimal::MONEY_SCALE);
            } elseif ($basePositive) {
                $rawShare = Decimal::div(
                    Decimal::mul($items[$idx]['amount'], $expensesTotal, Decimal::CALCULATION_SCALE),
                    $baseTotal,
                    Decimal::CALCULATION_SCALE,
                );
                $share = Decimal::round($rawShare, Decimal::MONEY_SCALE);
                $allocated = Decimal::add($allocated, $share, Decimal::MONEY_SCALE);
            } else {
                $rawShare = Decimal::div($expensesTotal, (string) $count, Decimal::CALCULATION_SCALE);
                $share = Decimal::round($rawShare, Decimal::MONEY_SCALE);
                $allocated = Decimal::add($allocated, $share, Decimal::MONEY_SCALE);
            }

            $items[$idx]['amount'] = Decimal::add($items[$idx]['amount'], $share, Decimal::MONEY_SCALE);
        }

        return $items;
    }

    /**
     * @param  list<array{account_id: int, label: string, amount: string}>  $items
     * @return list<int>
     */
    private function positiveIndices(array $items): array
    {
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $indices = [];

        foreach ($items as $idx => $item) {
            if (Decimal::comp($item['amount'], $zero, Decimal::MONEY_SCALE) > 0) {
                $indices[] = $idx;
            }
        }

        return $indices;
    }

    /**
     * @param  list<array{account_id: int, line_number: int, label: string, debit: string, credit: string}>  $lines
     */
    private function assertBalancedPayload(array $lines, string $invoiceTotal): void
    {
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        if (count($lines) < 2 || Decimal::comp($invoiceTotal, $zero, Decimal::MONEY_SCALE) <= 0) {
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
            || Decimal::comp($totalDebit, $invoiceTotal, Decimal::MONEY_SCALE) !== 0) {
            throw ValidationException::withMessages([
                'lines' => [(string) __('accounting.errors.journal_entry_unbalanced')],
            ]);
        }
    }

    private function resolveItemLabel(PurchaseInvoiceItem $item, PurchaseInvoice $invoice): string
    {
        $name = $item->product?->name ?? ($item->product_snapshot['name'] ?? null);

        return is_string($name) && trim($name) !== ''
            ? trim($name)
            : (string) $invoice->invoice_number;
    }

    /**
     * Resolve stock-input account strictly: Priority 1 category account, Priority 2 lazy default setting.
     *
     * @param  Collection<int, Account>  $categoryAccounts
     */
    private function resolveItemStockInputAccount(
        PurchaseInvoiceItem $item,
        Collection $categoryAccounts,
        ?Account &$defaultStockInput,
    ): Account {
        $category = $item->product?->category;
        if ($category !== null && $category->stock_input_account_id !== null) {
            /** @var Account|null $account */
            $account = $categoryAccounts->get((int) $category->stock_input_account_id);

            if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::CurrentAssets->value) {
                throw ValidationException::withMessages([
                    'stock_input_account_id' => [(string) __('accounting.errors.category_stock_input_account_invalid', [
                        'category' => $category->name,
                    ])],
                ]);
            }

            return $account;
        }

        if ($defaultStockInput === null) {
            $defaultStockInput = $this->resolveDefaultStockInputAccount();
        }

        return $defaultStockInput;
    }

    private function resolveDefaultStockInputAccount(): Account
    {
        $accountId = $this->accountingSettingService->resolveDefaultStockInputAccountId();

        if ($accountId === null) {
            throw ValidationException::withMessages([
                'default_stock_input_account_id' => [(string) __('accounting.errors.stock_input_account_missing')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find($accountId);

        if (! $account) {
            throw ValidationException::withMessages([
                'default_stock_input_account_id' => [(string) __('accounting.errors.stock_input_account_missing')],
            ]);
        }

        if (! $account->is_active || $account->accountType?->code !== AccountTypeCode::CurrentAssets->value) {
            throw ValidationException::withMessages([
                'default_stock_input_account_id' => [(string) __('accounting.errors.stock_input_account_invalid')],
            ]);
        }

        return $account;
    }

    /**
     * Build debit journal lines for SEPARATE_EXPENSE treatment, using each expense row's name as label.
     *
     * @return list<array{account_id: int, debit: string, credit: string, label: string}>
     */
    private function buildSeparateExpenseLines(PurchaseInvoice $invoice, string $expensesTotal): array
    {
        $expenseAccount = $this->resolveAdditionalExpensesAccount();
        $fallbackLabel = (string) __('accounting.labels.purchase_additional_expenses', [
            'number' => $invoice->invoice_number,
        ]);

        $invoice->loadMissing([
            'expenses' => static fn ($q) => $q->orderBy('id'),
        ]);

        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        $expenseLines = [];
        $summed = $zero;

        /** @var PurchaseInvoiceExpense $expense */
        foreach ($invoice->expenses as $expense) {
            $amount = Decimal::fromNumeric($expense->amount ?? 0, Decimal::MONEY_SCALE);
            if (Decimal::comp($amount, $zero, Decimal::MONEY_SCALE) <= 0) {
                continue;
            }

            $name = trim((string) ($expense->name ?? ''));
            $expenseLines[] = [
                'account_id' => $expenseAccount->id,
                'debit' => $amount,
                'credit' => '0.00',
                'label' => $name !== '' ? $name : $fallbackLabel,
            ];
            $summed = Decimal::add($summed, $amount, Decimal::MONEY_SCALE);
        }

        if ($expenseLines === [] || Decimal::comp($summed, $expensesTotal, Decimal::MONEY_SCALE) !== 0) {
            return [[
                'account_id' => $expenseAccount->id,
                'debit' => $expensesTotal,
                'credit' => '0.00',
                'label' => $fallbackLabel,
            ]];
        }

        return $expenseLines;
    }

    private function resolveAdditionalExpensesAccount(): Account
    {
        $accountId = $this->accountingSettingService->resolveAccountId('additional_expenses_account_id');
        if ($accountId === null) {
            throw ValidationException::withMessages([
                'additional_expenses_account_id' => [(string) __('accounting.errors.additional_expenses_account_required')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find($accountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Expenses->value) {
            throw ValidationException::withMessages([
                'additional_expenses_account_id' => [(string) __('accounting.errors.additional_expenses_account_invalid')],
            ]);
        }

        return $account;
    }

    private function resolveSupplierPayableAccount(PurchaseInvoice $invoice): Account
    {
        $supplier = $invoice->supplier;
        $accountId = $supplier?->payable_account_id ?: $this->accountingSettingService->resolveAccountId('accounts_payable');

        if ($accountId === null) {
            throw ValidationException::withMessages([
                'supplier_id' => [(string) __('accounting.errors.supplier_payable_account_missing')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find((int) $accountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Payable->value) {
            throw ValidationException::withMessages([
                'supplier_id' => [(string) __('accounting.errors.supplier_payable_account_invalid')],
            ]);
        }

        return $account;
    }
}
