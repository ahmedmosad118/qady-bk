<?php

declare(strict_types=1);

namespace App\Listeners\Payment;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentType;
use App\Events\Payment\PaymentCancelled;
use App\Events\Payment\PaymentCreated;
use App\Events\Payment\PaymentDeleted;
use App\Events\Payment\PaymentPosted;
use App\Events\Payment\PaymentUpdated;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Payment;
use App\Services\Accounting\AccountingSettingService;
use App\Services\Accounting\JournalEntryService;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

class SyncSupplierPaymentJournalEntry
{
    public function __construct(
        protected JournalEntryService $journalEntryService,
        protected AccountingSettingService $accountingSettingService,
    ) {}

    public function handleCreated(PaymentCreated $event): void
    {
        if (! $this->isSupportedPayment($event->payment)) {
            return;
        }

        $payload = $this->buildEntryPayload($event->payment);

        $this->journalEntryService->createDraftForSource(
            $payload,
            $event->payment,
            $event->user,
        );
    }

    public function handleUpdated(PaymentUpdated $event): void
    {
        if (! $this->isSupportedPayment($event->payment)) {
            return;
        }

        $payload = $this->buildEntryPayload($event->payment);

        $this->journalEntryService->updateDraftForSource(
            $event->payment,
            $payload,
        );
    }

    public function handlePosted(PaymentPosted $event): void
    {
        if (! $this->isSupportedPayment($event->payment)) {
            return;
        }

        $payload = $this->buildEntryPayload($event->payment);

        $this->journalEntryService->updateDraftForSource(
            $event->payment,
            $payload,
        );

        $this->journalEntryService->postForSource(
            $event->payment,
            $event->user,
        );
    }

    public function handleCancelled(PaymentCancelled $event): void
    {
        if (! $this->isSupportedPayment($event->payment)) {
            return;
        }

        $this->journalEntryService->deleteDraftForSource($event->payment);
    }

    public function handleDeleted(PaymentDeleted $event): void
    {
        if (! $this->isSupportedPayment($event->payment)) {
            return;
        }

        $this->journalEntryService->deleteDraftForSource($event->payment);
    }

    /**
     * Build the balanced Journal Entry payload for Send (Supplier), Receive (Customer), or Transfer payments.
     *
     * @return array<string, mixed>
     */
    public function buildEntryPayload(Payment $payment): array
    {
        $payment->loadMissing([
            'contact:id,name,payable_account_id,receivable_account_id',
            'account:id,code,name,account_type_id,is_active',
            'paymentMethod:id,name,type,account_id,is_active',
        ]);

        $treasuryAccount = $this->resolvePaymentMethodAccount($payment);

        $amount = Decimal::fromNumeric($payment->amount, Decimal::MONEY_SCALE);
        $zero = Decimal::fromNumeric(0, Decimal::MONEY_SCALE);
        if (Decimal::comp($amount, $zero, Decimal::MONEY_SCALE) <= 0) {
            throw ValidationException::withMessages([
                'amount' => [(string) __('payments.errors.amount_must_be_positive')],
            ]);
        }

        $journal = Journal::query()->find((int) $payment->journal_id);
        if (! $journal || ! $journal->is_active || ! in_array($journal->type, [JournalType::Cash, JournalType::Bank], true)) {
            throw ValidationException::withMessages([
                'journal_id' => [(string) __('payments.errors.journal_invalid')],
            ]);
        }

        $entryDate = $payment->payment_date->toDateString();
        $context = $this->journalEntryService->resolveContext($journal->id, $journal->type, $entryDate);

        $reference = trim((string) ($payment->reference ?? ''));
        $description = trim((string) ($payment->description ?? ''));

        if ($this->isSupplierPayment($payment)) {
            $payableAccount = $this->resolveSupplierPayableAccount($payment);

            return [
                'journal_id' => $context['journal_id'],
                'fiscal_year_id' => $context['fiscal_year_id'],
                'accounting_period_id' => $context['accounting_period_id'],
                'contact_id' => $payment->contact_id,
                'entry_date' => $entryDate,
                'reference' => $reference !== '' ? $reference : $payment->payment_number,
                'description' => $description !== ''
                    ? $description
                    : (string) __('payments.labels.supplier_payment_entry_description', ['number' => $payment->payment_number]),
                'lines' => [
                    [
                        'account_id' => $payableAccount->id,
                        'line_number' => 1,
                        'label' => $payment->contact?->name ?? $payment->payment_number,
                        'debit' => $amount,
                        'credit' => '0.00',
                    ],
                    [
                        'account_id' => $treasuryAccount->id,
                        'line_number' => 2,
                        'label' => $payment->paymentMethod?->name ?? $payment->payment_number,
                        'debit' => '0.00',
                        'credit' => $amount,
                    ],
                ],
            ];
        }

        if ($this->isCustomerReceipt($payment)) {
            $receivableAccount = $this->resolveCustomerReceivableAccount($payment);

            return [
                'journal_id' => $context['journal_id'],
                'fiscal_year_id' => $context['fiscal_year_id'],
                'accounting_period_id' => $context['accounting_period_id'],
                'contact_id' => $payment->contact_id,
                'entry_date' => $entryDate,
                'reference' => $reference !== '' ? $reference : $payment->payment_number,
                'description' => $description !== ''
                    ? $description
                    : (string) __('payments.labels.customer_receipt_entry_description', ['number' => $payment->payment_number]),
                'lines' => [
                    [
                        'account_id' => $treasuryAccount->id,
                        'line_number' => 1,
                        'label' => $payment->paymentMethod?->name ?? $payment->payment_number,
                        'debit' => $amount,
                        'credit' => '0.00',
                    ],
                    [
                        'account_id' => $receivableAccount->id,
                        'line_number' => 2,
                        'label' => $payment->contact?->name ?? $payment->payment_number,
                        'debit' => '0.00',
                        'credit' => $amount,
                    ],
                ],
            ];
        }

        $destinationAccount = $this->resolveTransferDestinationAccount($payment, $treasuryAccount);

        return [
            'journal_id' => $context['journal_id'],
            'fiscal_year_id' => $context['fiscal_year_id'],
            'accounting_period_id' => $context['accounting_period_id'],
            'contact_id' => null,
            'entry_date' => $entryDate,
            'reference' => $reference !== '' ? $reference : $payment->payment_number,
            'description' => $description !== ''
                ? $description
                : (string) __('payments.labels.transfer_entry_description', ['number' => $payment->payment_number]),
            'lines' => [
                [
                    'account_id' => $destinationAccount->id,
                    'line_number' => 1,
                    'label' => $destinationAccount->name,
                    'debit' => $amount,
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $treasuryAccount->id,
                    'line_number' => 2,
                    'label' => $payment->paymentMethod?->name ?? $payment->payment_number,
                    'debit' => '0.00',
                    'credit' => $amount,
                ],
            ],
        ];
    }

    private function isSupportedPayment(Payment $payment): bool
    {
        return $this->isSupplierPayment($payment)
            || $this->isCustomerReceipt($payment)
            || $this->isTransferPayment($payment);
    }

    private function isSupplierPayment(Payment $payment): bool
    {
        return $payment->payment_type === PaymentType::SEND
            && $payment->related_type === PaymentRelatedType::SUPPLIER;
    }

    private function isCustomerReceipt(Payment $payment): bool
    {
        return $payment->payment_type === PaymentType::RECEIVE
            && $payment->related_type === PaymentRelatedType::CUSTOMER;
    }

    private function isTransferPayment(Payment $payment): bool
    {
        return $payment->payment_type === PaymentType::TRANSFER
            && $payment->related_type === null;
    }

    private function resolveSupplierPayableAccount(Payment $payment): Account
    {
        $accountId = $payment->contact?->payable_account_id
            ?: $this->accountingSettingService->resolveAccountId('accounts_payable');

        if ($accountId === null) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.supplier_payable_account_missing')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find((int) $accountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Payable->value) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.supplier_payable_account_invalid')],
            ]);
        }

        return $account;
    }

    private function resolveCustomerReceivableAccount(Payment $payment): Account
    {
        $accountId = $payment->contact?->receivable_account_id
            ?: $this->accountingSettingService->resolveAccountId('accounts_receivable');

        if ($accountId === null) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.customer_receivable_account_missing')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find((int) $accountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::Receivable->value) {
            throw ValidationException::withMessages([
                'contact_id' => [(string) __('payments.errors.customer_receivable_account_invalid')],
            ]);
        }

        return $account;
    }

    private function resolveTransferDestinationAccount(Payment $payment, Account $sourceAccount): Account
    {
        $accountId = (int) ($payment->account_id ?? 0);
        if ($accountId <= 0) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.transfer_account_invalid')],
            ]);
        }

        if ($accountId === $sourceAccount->id) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.transfer_same_account')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find($accountId);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::BankCash->value) {
            throw ValidationException::withMessages([
                'account_id' => [(string) __('payments.errors.transfer_account_invalid')],
            ]);
        }

        return $account;
    }

    private function resolvePaymentMethodAccount(Payment $payment): Account
    {
        $paymentMethod = $payment->paymentMethod;
        if (! $paymentMethod || ! $paymentMethod->is_active || $paymentMethod->account_id === null) {
            throw ValidationException::withMessages([
                'payment_method_id' => [(string) __('payments.errors.payment_method_invalid')],
            ]);
        }

        $account = Account::query()
            ->with('accountType:id,code')
            ->lockForUpdate()
            ->find((int) $paymentMethod->account_id);

        if (! $account || ! $account->is_active || $account->accountType?->code !== AccountTypeCode::BankCash->value) {
            throw ValidationException::withMessages([
                'payment_method_id' => [(string) __('payments.errors.payment_method_account_invalid')],
            ]);
        }

        return $account;
    }
}
