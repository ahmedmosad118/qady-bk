<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContactAccountService
{
    public function __construct(
        protected ChartOfAccountService $chartOfAccountService,
    ) {}

    /**
     * Provision only the accounting accounts required by the contact's active roles
     * (customer => Asset receivable, supplier => Liability payable), preserving any
     * existing valid accounts even when a role is removed.
     *
     * Must be invoked inside an active database transaction.
     */
    public function syncAccountsForContact(Contact $contact): void
    {
        $receivableAccount = null;
        if ($contact->receivable_account_id !== null) {
            $receivableAccount = $this->validateExistingRoleAccount(
                (int) $contact->receivable_account_id,
                AccountType::ASSET,
                $contact->payable_account_id !== null ? (int) $contact->payable_account_id : null
            );
        } elseif ($contact->is_customer) {
            $receivableAccount = $this->createStandaloneContactAccount(
                $this->buildReceivableAccountName($contact),
                AccountType::ASSET,
                "حساب ذمم مدينة للعميل: {$contact->name}"
            );
            $contact->receivable_account_id = $receivableAccount->id;
        }

        $payableAccount = null;
        if ($contact->payable_account_id !== null) {
            $payableAccount = $this->validateExistingRoleAccount(
                (int) $contact->payable_account_id,
                AccountType::LIABILITY,
                $contact->receivable_account_id !== null ? (int) $contact->receivable_account_id : null
            );
        } elseif ($contact->is_supplier) {
            $payableAccount = $this->createStandaloneContactAccount(
                $this->buildPayableAccountName($contact),
                AccountType::LIABILITY,
                "حساب ذمم دائنة للمورد: {$contact->name}"
            );
            $contact->payable_account_id = $payableAccount->id;
        }

        if ($contact->receivable_account_id !== null && $contact->receivable_account_id === $contact->payable_account_id) {
            throw ValidationException::withMessages([
                'contact' => [(string) __('accounting.errors.contact_accounts_must_be_distinct')],
            ]);
        }

        if ($contact->isDirty(['receivable_account_id', 'payable_account_id'])) {
            $contact->save();
        }

        $contact->setRelation('receivableAccount', $receivableAccount);
        $contact->setRelation('payableAccount', $payableAccount);
    }

    /**
     * Safely backfill missing or invalid receivable/payable accounts for existing contacts
     * (including soft-deleted contacts) strictly according to their current roles.
     * Idempotent: never overwrites valid existing links and never creates unneeded accounts.
     *
     * @return array{processed: int, created_receivable: int, created_payable: int, skipped: int}
     */
    public function backfillMissingAccounts(): array
    {
        $stats = [
            'processed' => 0,
            'created_receivable' => 0,
            'created_payable' => 0,
            'skipped' => 0,
        ];

        $reservedAccountIds = $this->getNonContactReservedAccountIdSet();
        $reservedKeys = array_keys($reservedAccountIds);

        Contact::withTrashed()
            ->where(function ($query) use ($reservedKeys): void {
                $query->where(static function ($q): void {
                    $q->where('is_customer', true)->whereNull('receivable_account_id');
                })
                    ->orWhere(static function ($q): void {
                        $q->where('is_supplier', true)->whereNull('payable_account_id');
                    })
                    ->orWhere(static function ($q): void {
                        $q->whereNotNull('receivable_account_id')
                            ->whereNotNull('payable_account_id')
                            ->whereColumn('receivable_account_id', 'payable_account_id');
                    })
                    ->orWhere(static function ($q): void {
                        $q->whereNotNull('receivable_account_id')
                            ->whereDoesntHave('receivableAccount', static function ($aq): void {
                                $aq->where('account_type', AccountType::ASSET->value);
                            });
                    })
                    ->orWhere(static function ($q): void {
                        $q->whereNotNull('payable_account_id')
                            ->whereDoesntHave('payableAccount', static function ($aq): void {
                                $aq->where('account_type', AccountType::LIABILITY->value);
                            });
                    });

                if ($reservedKeys !== []) {
                    $query->orWhereIn('receivable_account_id', $reservedKeys)
                        ->orWhereIn('payable_account_id', $reservedKeys);
                }
            })
            ->orderBy('id')
            ->chunkById(100, function ($contacts) use (&$stats, $reservedAccountIds): void {
                foreach ($contacts as $contact) {
                    DB::transaction(function () use ($contact, &$stats, $reservedAccountIds): void {
                        /** @var Contact|null $locked */
                        $locked = Contact::withTrashed()->lockForUpdate()->find($contact->id);
                        if (! $locked) {
                            return;
                        }

                        $originalReceivableId = $locked->receivable_account_id !== null ? (int) $locked->receivable_account_id : null;
                        $originalPayableId = $locked->payable_account_id !== null ? (int) $locked->payable_account_id : null;

                        $validReceivable = $this->findValidExistingAccount(
                            $originalReceivableId,
                            AccountType::ASSET,
                            $locked->id,
                            $reservedAccountIds,
                            null
                        );

                        $validPayable = $this->findValidExistingAccount(
                            $originalPayableId,
                            AccountType::LIABILITY,
                            $locked->id,
                            $reservedAccountIds,
                            $validReceivable?->id
                        );

                        $changed = false;
                        $createdRec = false;
                        $createdPay = false;

                        if ($originalReceivableId !== null && $validReceivable === null) {
                            $locked->receivable_account_id = null;
                            $changed = true;
                        }

                        if ($originalPayableId !== null && $validPayable === null) {
                            $locked->payable_account_id = null;
                            $changed = true;
                        }

                        if ($locked->is_customer && $validReceivable === null) {
                            $validReceivable = $this->createStandaloneContactAccount(
                                $this->buildReceivableAccountName($locked),
                                AccountType::ASSET,
                                "حساب ذمم مدينة للعميل: {$locked->name}"
                            );
                            $locked->receivable_account_id = $validReceivable->id;
                            $createdRec = true;
                            $changed = true;
                        }

                        if ($locked->is_supplier && $validPayable === null) {
                            $validPayable = $this->createStandaloneContactAccount(
                                $this->buildPayableAccountName($locked),
                                AccountType::LIABILITY,
                                "حساب ذمم دائنة للمورد: {$locked->name}"
                            );
                            $locked->payable_account_id = $validPayable->id;
                            $createdPay = true;
                            $changed = true;
                        }

                        if (! $changed) {
                            $stats['skipped']++;

                            return;
                        }

                        $locked->save();
                        $locked->setRelation('receivableAccount', $validReceivable);
                        $locked->setRelation('payableAccount', $validPayable);

                        $stats['processed']++;
                        if ($createdRec) {
                            $stats['created_receivable']++;
                        }
                        if ($createdPay) {
                            $stats['created_payable']++;
                        }
                    });
                }
            });

        return $stats;
    }

    /**
     * Safely unlink and delete contact accounts that do not match the contact's current active roles
     * (e.g. payable account on a customer-only contact, or receivable account on a supplier-only contact),
     * provided the account is not referenced anywhere else.
     *
     * @return array{contacts_updated: int, pruned_receivable: int, pruned_payable: int}
     */
    public function pruneUnneededAccounts(): array
    {
        $stats = [
            'contacts_updated' => 0,
            'pruned_receivable' => 0,
            'pruned_payable' => 0,
        ];

        $reservedAccountIds = $this->getNonContactReservedAccountIdSet();

        Contact::withTrashed()
            ->where(function ($query): void {
                $query->where(static function ($q): void {
                    $q->where('is_customer', false)->whereNotNull('receivable_account_id');
                })->orWhere(static function ($q): void {
                    $q->where('is_supplier', false)->whereNotNull('payable_account_id');
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($contacts) use (&$stats, $reservedAccountIds): void {
                foreach ($contacts as $contact) {
                    DB::transaction(function () use ($contact, &$stats, $reservedAccountIds): void {
                        /** @var Contact|null $locked */
                        $locked = Contact::withTrashed()->lockForUpdate()->find($contact->id);
                        if (! $locked) {
                            return;
                        }

                        $toDeleteIds = [];
                        $updated = false;

                        if (! $locked->is_customer && $locked->receivable_account_id !== null) {
                            $recId = (int) $locked->receivable_account_id;
                            $locked->receivable_account_id = null;
                            $updated = true;
                            $stats['pruned_receivable']++;
                            if ($this->canSafelyDeleteContactAccount($recId, $locked->id, $locked->payable_account_id ? (int) $locked->payable_account_id : null, $reservedAccountIds)) {
                                $toDeleteIds[] = $recId;
                            }
                        }

                        if (! $locked->is_supplier && $locked->payable_account_id !== null) {
                            $payId = (int) $locked->payable_account_id;
                            $locked->payable_account_id = null;
                            $updated = true;
                            $stats['pruned_payable']++;
                            if ($this->canSafelyDeleteContactAccount($payId, $locked->id, $locked->receivable_account_id ? (int) $locked->receivable_account_id : null, $reservedAccountIds)) {
                                $toDeleteIds[] = $payId;
                            }
                        }

                        if (! $updated) {
                            return;
                        }

                        $locked->save();
                        $stats['contacts_updated']++;

                        if ($toDeleteIds !== []) {
                            ChartOfAccount::query()->whereIn('id', array_values(array_unique($toDeleteIds)))->delete();
                        }
                    });
                }
            });

        return $stats;
    }

    /**
     * @param  array<int, true>  $reservedAccountIds
     */
    private function canSafelyDeleteContactAccount(
        int $accountId,
        int $contactId,
        ?int $otherSideAccountId,
        array $reservedAccountIds
    ): bool {
        if ($otherSideAccountId !== null && $accountId === $otherSideAccountId) {
            return false;
        }

        if (isset($reservedAccountIds[$accountId])) {
            return false;
        }

        return ! Contact::withTrashed()
            ->where('id', '!=', $contactId)
            ->where(function ($query) use ($accountId): void {
                $query->where('receivable_account_id', $accountId)
                    ->orWhere('payable_account_id', $accountId);
            })
            ->exists();
    }

    private function validateExistingRoleAccount(
        int $accountId,
        AccountType $expectedType,
        ?int $otherSideAccountId
    ): ChartOfAccount {
        if ($otherSideAccountId !== null && $accountId === $otherSideAccountId) {
            throw ValidationException::withMessages([
                'contact' => [(string) __('accounting.errors.contact_accounts_must_be_distinct')],
            ]);
        }

        /** @var ChartOfAccount|null $existing */
        $existing = ChartOfAccount::query()->lockForUpdate()->find($accountId);
        if (! $existing || $existing->account_type !== $expectedType) {
            throw ValidationException::withMessages([
                'contact' => [(string) __('accounting.errors.contact_account_type_invalid', [
                    'type' => $expectedType->label(),
                ])],
            ]);
        }

        return $existing;
    }

    /**
     * @param  array<int, true>  $reservedAccountIds
     */
    private function findValidExistingAccount(
        ?int $accountId,
        AccountType $expectedType,
        int $contactId,
        array $reservedAccountIds,
        ?int $otherSideAccountId
    ): ?ChartOfAccount {
        if ($accountId === null || $accountId <= 0) {
            return null;
        }

        if ($otherSideAccountId !== null && $accountId === $otherSideAccountId) {
            return null;
        }

        if (isset($reservedAccountIds[$accountId])) {
            return null;
        }

        /** @var ChartOfAccount|null $account */
        $account = ChartOfAccount::query()->lockForUpdate()->find($accountId);
        if (! $account || $account->account_type !== $expectedType) {
            return null;
        }

        $usedByAnotherContact = Contact::withTrashed()
            ->where('id', '!=', $contactId)
            ->where(function ($query) use ($accountId): void {
                $query->where('receivable_account_id', $accountId)
                    ->orWhere('payable_account_id', $accountId);
            })
            ->exists();

        if ($usedByAnotherContact) {
            return null;
        }

        return $account;
    }

    private function createStandaloneContactAccount(
        string $name,
        AccountType $accountType,
        string $description
    ): ChartOfAccount {
        return $this->chartOfAccountService->create([
            'name' => $name,
            'account_type' => $accountType->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
            'description' => $description,
        ]);
    }

    /**
     * Collect account IDs currently reserved by accounting settings or payment methods.
     *
     * @return array<int, true>
     */
    private function getNonContactReservedAccountIdSet(): array
    {
        $set = [];

        $setting = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if ($setting && is_array($setting->data)) {
            foreach (AccountingSettingRegistry::keys() as $key) {
                $id = $setting->data[$key] ?? null;
                if (is_numeric($id) && (int) $id > 0) {
                    $set[(int) $id] = true;
                }
            }
        }

        $pmIds = PaymentMethod::query()
            ->whereNotNull('chart_of_account_id')
            ->pluck('chart_of_account_id');

        foreach ($pmIds as $id) {
            $set[(int) $id] = true;
        }

        return $set;
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
