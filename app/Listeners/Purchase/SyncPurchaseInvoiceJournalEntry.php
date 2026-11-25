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

        $this->journalEntryService->createDraftForPurchaseInvoice(
            $payload,
            $event->invoice,
            $event->user,
        );
    }

    public function handleUpdated(PurchaseInvoiceUpdated $event): void
    {
        $payload = $this->buildEntryPayload($event->invoice);

        $this->journalEntryService->updateDraftForPurchaseInvoice(
            $event->invoice,
            $payload,
        );
    }

    public function handlePosted(PurchaseInvoicePosted $event): void
    {
        // Re-validate accounting mappings and context before posting the linked journal entry.
        $this->buildEntryPayload($event->invoice);

        $this->journalEntryService->postForPurchaseInvoice(
            $event->invoice,
            $event->user,
        );
    }

    public function handleCancelled(PurchaseInvoiceCancelled $event): void
    {
        $this->journalEntryService->deleteDraftForPurchaseInvoice($event->invoice);
    }

    public function handleDeleted(PurchaseInvoiceDeleted $event): void
    {
        $this->journalEntryService->deleteDraftForPurchaseInvoice($event->invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntryPayload(PurchaseInvoice $invoice): array
    {
        if (! $invoice->relationLoaded('items')) {
            $invoice->load([
                'items' => static fn ($q) => $q->orderBy('id'),
                'items.product:id,name,category_id',
                'items.product.category:id,name,stock_input_account_id',
            ]);
        }

        $invoice->loadMissing([
            'supplier:id,name,payable_account_id',
        ]);

        $items = $invoice->items;
        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => [(string) __('purchases.errors.items_required')],
            ]);
        }

        $defaultStockInput = $this->resolveDefaultStockInputAccount();
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

        /** @var list<array{account_id: int, net_amount: string}> $resolvedItems */
        $resolvedItems = [];
        $netLinesTotal = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        foreach ($items as $item) {
            $account = $this->resolveItemStockInputAccount($item, $categoryAccounts, $defaultStockInput);
            $lineTotal = Decimal::fromNumeric($item->line_total, Decimal::MONEY_SCALE);
            $netLinesTotal = Decimal::add($netLinesTotal, $lineTotal, Decimal::MONEY_SCALE);

            $resolvedItems[] = [
                'account_id' => $account->id,
                'net_amount' => $lineTotal,
            ];
        }

        $discountAmount = Decimal::fromNumeric($invoice->discount_amount ?? 0, Decimal::MONEY_SCALE);
        $resolvedItems = $this->allocateProportionally($resolvedItems, $discountAmount, $netLinesTotal, subtract: true);

        $netAfterDiscountTotal = Decimal::sub($netLinesTotal, $discountAmount, Decimal::MONEY_SCALE);
        $expensesTotal = Decimal::fromNumeric($invoice->additional_expenses_total ?? 0, Decimal::MONEY_SCALE);
        $treatment = $this->accountingSettingService->resolveExpensesTreatment();

        $separateExpenseLine = null;
        if (Decimal::comp($expensesTotal, '0.00', Decimal::MONEY_SCALE) > 0) {
            if ($treatment === PurchaseExpensesTreatment::LandedCost) {
                $resolvedItems = $this->allocateProportionally(
                    $resolvedItems,
                    $expensesTotal,
                    $netAfterDiscountTotal,
                    subtract: false,
                );
            } else {
                $expenseAccount = $this->resolveAdditionalExpensesAccount();
                $separateExpenseLine = [
                    'account_id' => $expenseAccount->id,
                    'debit' => $expensesTotal,
                    'credit' => '0.00',
                    'label' => (string) __('accounting.labels.purchase_additional_expenses', ['number' => $invoice->invoice_number]),
                ];
            }
        }

        $groupedStockInput = [];
        foreach ($resolvedItems as $row) {
            $accId = $row['account_id'];
            $groupedStockInput[$accId] = Decimal::add(
                $groupedStockInput[$accId] ?? '0.00',
                $row['net_amount'],
                Decimal::MONEY_SCALE,
            );
        }

        $lines = [];
        $lineNumber = 1;
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);

        foreach ($groupedStockInput as $accountId => $amount) {
            if (Decimal::comp($amount, $zero, Decimal::MONEY_SCALE) <= 0) {
                continue;
            }

            $lines[] = [
                'account_id' => (int) $accountId,
                'line_number' => $lineNumber++,
                'label' => (string) __('accounting.labels.purchase_stock_input_line', ['number' => $invoice->invoice_number]),
                'debit' => $amount,
                'credit' => '0.00',
            ];
        }

        if ($separateExpenseLine !== null) {
            $separateExpenseLine['line_number'] = $lineNumber++;
            $lines[] = $separateExpenseLine;
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
     * Allocate a total amount proportionally across items using BCMath with remainder absorption on the last item.
     *
     * @param  list<array{account_id: int, net_amount: string}>  $items
     * @return list<array{account_id: int, net_amount: string}>
     */
    private function allocateProportionally(
        array $items,
        string $amountToAllocate,
        string $baseTotal,
        bool $subtract,
    ): array {
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        if ($items === [] || Decimal::comp($amountToAllocate, $zero, Decimal::MONEY_SCALE) <= 0) {
            return $items;
        }

        $count = count($items);
        $allocated = $zero;
        $basePositive = Decimal::comp($baseTotal, $zero, Decimal::MONEY_SCALE) > 0;

        foreach ($items as $idx => $item) {
            $isLast = ($idx === $count - 1);

            if ($isLast) {
                $share = Decimal::sub($amountToAllocate, $allocated, Decimal::MONEY_SCALE);
            } elseif ($basePositive) {
                $rawShare = Decimal::div(
                    Decimal::mul($item['net_amount'], $amountToAllocate, Decimal::CALCULATION_SCALE),
                    $baseTotal,
                    Decimal::CALCULATION_SCALE,
                );
                $share = Decimal::round($rawShare, Decimal::MONEY_SCALE);
                $allocated = Decimal::add($allocated, $share, Decimal::MONEY_SCALE);
            } else {
                $share = $zero;
            }

            $items[$idx]['net_amount'] = $subtract
                ? Decimal::sub($item['net_amount'], $share, Decimal::MONEY_SCALE)
                : Decimal::add($item['net_amount'], $share, Decimal::MONEY_SCALE);
        }

        return $items;
    }

    private function resolveDefaultStockInputAccount(): Account
    {
        $account = Account::query()
            ->with('accountType:id,code')
            ->where('system_key', 'stock_input')
            ->lockForUpdate()
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                'stock_input_account_id' => [(string) __('accounting.errors.stock_input_account_missing')],
            ]);
        }

        if (! $account->is_active || $account->accountType?->code !== AccountTypeCode::CurrentAssets->value) {
            throw ValidationException::withMessages([
                'stock_input_account_id' => [(string) __('accounting.errors.stock_input_account_invalid')],
            ]);
        }

        return $account;
    }

    /**
     * @param  Collection<int, Account>  $categoryAccounts
     */
    private function resolveItemStockInputAccount(
        PurchaseInvoiceItem $item,
        $categoryAccounts,
        Account $defaultStockInput,
    ): Account {
        $category = $item->product?->category;
        if ($category === null || $category->stock_input_account_id === null) {
            return $defaultStockInput;
        }

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
