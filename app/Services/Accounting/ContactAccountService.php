<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactAccountService
{
    public function __construct(
        protected AccountingSettingService $accountingSettingService,
        protected AccountCodeGenerator $codeGenerator,
    ) {}

    /**
     * Provision independent receivable and payable sub-accounts for a contact.
     * Must be invoked inside an active database transaction.
     *
     * @param  array{0: ChartOfAccount, 1: ChartOfAccount}|null  $controlAccounts
     */
    public function createAccountsForContact(Contact $contact, ?array $controlAccounts = null): void
    {
        [$receivableParent, $payableParent] = $controlAccounts ?? $this->resolveControlAccounts();

        $receivableAccount = $contact->receivable_account_id
            ? ChartOfAccount::query()->find($contact->receivable_account_id)
            : null;

        if (! $receivableAccount) {
            $receivableCode = $this->codeGenerator->generateNextCode($receivableParent->id);
            $receivableAccount = ChartOfAccount::query()->create([
                'parent_id' => $receivableParent->id,
                'code' => $receivableCode,
                'name' => $this->buildReceivableAccountName($contact),
                'account_type' => AccountType::ASSET->value,
                'description' => "حساب ذمم مدينة لجهة الاتصال: {$contact->name}",
            ]);
            $contact->receivable_account_id = $receivableAccount->id;
        }

        $payableAccount = $contact->payable_account_id
            ? ChartOfAccount::query()->find($contact->payable_account_id)
            : null;

        if (! $payableAccount) {
            $payableCode = $this->codeGenerator->generateNextCode($payableParent->id);
            $payableAccount = ChartOfAccount::query()->create([
                'parent_id' => $payableParent->id,
                'code' => $payableCode,
                'name' => $this->buildPayableAccountName($contact),
                'account_type' => AccountType::LIABILITY->value,
                'description' => "حساب ذمم دائنة لجهة الاتصال: {$contact->name}",
            ]);
            $contact->payable_account_id = $payableAccount->id;
        }

        if ($contact->receivable_account_id === $contact->payable_account_id) {
            throw ValidationException::withMessages([
                'contact' => [(string) __('accounting.errors.contact_control_account_invalid')],
            ]);
        }

        $contact->save();
        $contact->setRelation('receivableAccount', $receivableAccount);
        $contact->setRelation('payableAccount', $payableAccount);
    }

    /**
     * Safely backfill missing receivable/payable accounts for pre-existing contacts.
     * Idempotent: only creates whichever account is null, never duplicates or overwrites.
     *
     * @return array{processed: int, created_receivable: int, created_payable: int, skipped: int}
     */
    public function backfillMissingAccounts(): array
    {
        // Validate control accounts upfront before touching any contact row.
        $this->resolveControlAccounts();

        $stats = [
            'processed' => 0,
            'created_receivable' => 0,
            'created_payable' => 0,
            'skipped' => 0,
        ];

        Contact::withTrashed()
            ->where(function ($query): void {
                $query->whereNull('receivable_account_id')
                    ->orWhereNull('payable_account_id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($contacts) use (&$stats): void {
                $controlAccounts = $this->resolveControlAccounts();

                foreach ($contacts as $contact) {
                    DB::transaction(function () use ($contact, $controlAccounts, &$stats): void {
                        /** @var Contact|null $locked */
                        $locked = Contact::withTrashed()->lockForUpdate()->find($contact->id);
                        if (! $locked) {
                            return;
                        }

                        $hadReceivable = $locked->receivable_account_id !== null;
                        $hadPayable = $locked->payable_account_id !== null;

                        if ($hadReceivable && $hadPayable) {
                            $stats['skipped']++;

                            return;
                        }

                        $this->createAccountsForContact($locked, $controlAccounts);

                        $stats['processed']++;
                        if (! $hadReceivable && $locked->receivable_account_id !== null) {
                            $stats['created_receivable']++;
                        }
                        if (! $hadPayable && $locked->payable_account_id !== null) {
                            $stats['created_payable']++;
                        }
                    });
                }
            });

        return $stats;
    }

    /**
     * Resolve and lock the configured Accounts Receivable and Accounts Payable control accounts.
     *
     * @return array{0: ChartOfAccount, 1: ChartOfAccount}
     */
    public function resolveControlAccounts(): array
    {
        $receivableParentId = $this->accountingSettingService->resolveAccountId('accounts_receivable');
        $payableParentId = $this->accountingSettingService->resolveAccountId('accounts_payable');

        if (! $receivableParentId || ! $payableParentId) {
            throw ValidationException::withMessages([
                'accounting' => [(string) __('accounting.errors.contact_control_accounts_missing')],
            ]);
        }

        $parents = ChartOfAccount::query()
            ->whereIn('id', [$receivableParentId, $payableParentId])
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var ChartOfAccount|null $receivableParent */
        $receivableParent = $parents->get($receivableParentId);
        /** @var ChartOfAccount|null $payableParent */
        $payableParent = $parents->get($payableParentId);

        if (
            ! $receivableParent
            || ! $payableParent
            || $receivableParent->account_type !== AccountType::ASSET
            || $payableParent->account_type !== AccountType::LIABILITY
            || $receivableParent->id === $payableParent->id
        ) {
            throw ValidationException::withMessages([
                'accounting' => [(string) __('accounting.errors.contact_control_account_invalid')],
            ]);
        }

        return [$receivableParent, $payableParent];
    }

    private function buildReceivableAccountName(Contact $contact): string
    {
        return "{$contact->name} - عميل";
    }

    private function buildPayableAccountName(Contact $contact): string
    {
        return "{$contact->name} - مورد";
    }
}
