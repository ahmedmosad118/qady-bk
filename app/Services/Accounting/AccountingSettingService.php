<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\Accounting\PurchaseExpensesTreatment;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingSettingService
{
    /**
     * Retrieve the accounting settings record without exposing obsolete keys
     * or overwriting existing JSON entries.
     */
    public function getSettings(): Setting
    {
        $existing = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if ($existing) {
            $existing->data = $this->stripObsoleteKeys(is_array($existing->data) ? $existing->data : []);

            return $existing;
        }

        return new Setting([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [],
        ]);
    }

    /**
     * Return structured settings entries for active keys only with hydrated account info.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStructuredSettings(): array
    {
        $setting = $this->getSettings();
        $data = is_array($setting->data) ? $setting->data : [];

        $accountIds = [];
        foreach (AccountingSettingRegistry::keys() as $key) {
            $val = $data[$key] ?? null;
            if (is_numeric($val) && (int) $val > 0) {
                $accountIds[] = (int) $val;
            }
        }
        $accountIds = array_values(array_unique($accountIds));

        /** @var Collection<int, Account> $accounts */
        $accounts = $accountIds === []
            ? new Collection
            : Account::query()
                ->with('accountType:id,code,name')
                ->whereIn('id', $accountIds)
                ->get(['id', 'account_type_id', 'code', 'name', 'is_active'])
                ->keyBy('id');

        $result = [];
        foreach (AccountingSettingRegistry::definitions() as $key => $def) {
            $rawId = $data[$key] ?? null;
            $accountId = is_numeric($rawId) && (int) $rawId > 0 ? (int) $rawId : null;
            /** @var Account|null $account */
            $account = $accountId !== null ? $accounts->get($accountId) : null;
            $typeCode = $account?->accountType?->code;

            $result[] = [
                'key' => $key,
                'label' => AccountingSettingRegistry::label($key),
                'description' => AccountingSettingRegistry::description($key),
                'account_type' => $def['account_type']->value,
                'account_type_label' => AccountingSettingRegistry::accountTypeLabel($def['account_type']),
                'required' => $def['required'],
                'account_id' => $account?->id,
                'account' => $account ? [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $typeCode,
                    'account_type_label' => $account->accountType?->name ?? '',
                    'is_active' => (bool) $account->is_active,
                ] : null,
            ];
        }

        return $result;
    }

    /**
     * Return compatible active accounts per active accounting setting key in a single query.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getCompatibleAccountsByKey(): array
    {
        $typeCodes = array_values(array_unique(array_map(
            static fn (array $def): string => $def['account_type']->value,
            AccountingSettingRegistry::definitions()
        )));

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static function ($query) use ($typeCodes): void {
                $query->whereIn('code', $typeCodes);
            })
            ->with('accountType:id,code,name')
            ->orderBy('code')
            ->get(['id', 'account_type_id', 'code', 'name', 'is_active']);

        $byTypeCode = [];
        foreach ($accounts as $account) {
            $code = $account->accountType?->code;
            if ($code === null) {
                continue;
            }

            $byTypeCode[$code][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $code,
            ];
        }

        $grouped = [];
        foreach (AccountingSettingRegistry::definitions() as $key => $def) {
            $grouped[$key] = $byTypeCode[$def['account_type']->value] ?? [];
        }

        return $grouped;
    }

    /**
     * Resolve a single configured account ID for the given setting key.
     */
    public function resolveAccountId(string $key): ?int
    {
        $setting = $this->getSettings();
        $value = $setting->data[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Resolve the configured default Purchase journal ID (initial value for new purchase invoices).
     */
    public function resolveDefaultPurchaseJournalId(): ?int
    {
        $setting = $this->getSettings();
        $value = $setting->data['default_purchase_journal_id'] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Return active Purchase journals for Settings selection.
     *
     * @return array<int, array{id: int, code: string, name: string}>
     */
    public function getPurchaseJournals(): array
    {
        return Journal::query()
            ->where('type', JournalType::Purchase->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(static fn (Journal $journal): array => [
                'id' => $journal->id,
                'code' => $journal->code,
                'name' => $journal->name,
            ])
            ->all();
    }

    /**
     * Resolve the configured treatment for Purchase Invoice additional expenses.
     */
    public function resolveExpensesTreatment(): PurchaseExpensesTreatment
    {
        $setting = $this->getSettings();
        $raw = $setting->data['expenses_treatment'] ?? null;

        if (is_string($raw)) {
            $parsed = PurchaseExpensesTreatment::tryFrom($raw);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return PurchaseExpensesTreatment::LandedCost;
    }

    /**
     * Validate and atomically update active accounting settings while preserving
     * all existing JSON entries (including legacy keys and unrelated settings).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateSettings(array $input): Setting
    {
        return DB::transaction(function () use ($input): Setting {
            $existingSetting = Setting::query()
                ->where('type', AccountingSettingRegistry::SETTING_TYPE)
                ->lockForUpdate()
                ->first();

            $existingData = $existingSetting
                ? $this->stripObsoleteKeys(is_array($existingSetting->data) ? $existingSetting->data : [])
                : [];
            $definitions = AccountingSettingRegistry::definitions();
            $errors = [];

            foreach (array_keys($input) as $key) {
                if (! isset($definitions[$key]) && ! in_array($key, ['expenses_treatment', 'default_purchase_journal_id'], true)) {
                    $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_key_invalid', ['key' => $key])];
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $candidateData = $existingData;
            if (array_key_exists('expenses_treatment', $input)) {
                $rawTreatment = $input['expenses_treatment'];
                $treatment = is_string($rawTreatment)
                    ? PurchaseExpensesTreatment::tryFrom($rawTreatment)
                    : null;

                if ($rawTreatment !== null && $rawTreatment !== '' && $treatment === null) {
                    $errors['settings.expenses_treatment'] = [(string) __('accounting.errors.expenses_treatment_invalid')];
                } else {
                    $candidateData['expenses_treatment'] = ($treatment ?? PurchaseExpensesTreatment::LandedCost)->value;
                }
            } elseif (! isset($candidateData['expenses_treatment'])) {
                $candidateData['expenses_treatment'] = PurchaseExpensesTreatment::LandedCost->value;
            }

            if (array_key_exists('default_purchase_journal_id', $input)) {
                $rawJournalId = $input['default_purchase_journal_id'];
                if ($rawJournalId === null || $rawJournalId === '') {
                    $candidateData['default_purchase_journal_id'] = null;
                } else {
                    $journalId = (int) $rawJournalId;
                    $journal = Journal::query()->lockForUpdate()->find($journalId);

                    if (! $journal || ! $journal->is_active || $journal->type !== JournalType::Purchase) {
                        $errors['settings.default_purchase_journal_id'] = [
                            (string) __('accounting.errors.default_purchase_journal_invalid'),
                        ];
                    } else {
                        $candidateData['default_purchase_journal_id'] = $journal->id;
                    }
                }
            }

            foreach ($definitions as $key => $def) {
                if (array_key_exists($key, $input)) {
                    $raw = $input[$key];
                    $candidateData[$key] = is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
                }

                if ($def['required'] && empty($candidateData[$key])) {
                    $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_required_missing', [
                        'label' => AccountingSettingRegistry::label($key),
                    ])];
                }
            }

            $effectiveTreatment = PurchaseExpensesTreatment::tryFrom((string) ($candidateData['expenses_treatment'] ?? ''))
                ?? PurchaseExpensesTreatment::LandedCost;

            if ($effectiveTreatment === PurchaseExpensesTreatment::SeparateExpense
                && empty($candidateData['additional_expenses_account_id'])) {
                $errors['settings.additional_expenses_account_id'] = [
                    (string) __('accounting.errors.additional_expenses_account_required'),
                ];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->validateCandidateAccounts($candidateData, $definitions);

            if (! $existingSetting) {
                return Setting::query()->create([
                    'type' => AccountingSettingRegistry::SETTING_TYPE,
                    'data' => $candidateData,
                ]);
            }

            $existingSetting->update(['data' => $candidateData]);

            return $existingSetting->fresh();
        });
    }

    /**
     * Check whether an account is currently referenced by any active accounting setting.
     */
    public function isAccountLinkedToAnySetting(int $accountId): bool
    {
        $setting = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if (! $setting || ! is_array($setting->data)) {
            return false;
        }

        foreach (AccountingSettingRegistry::keys() as $key) {
            if ((int) ($setting->data[$key] ?? 0) === $accountId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $candidateData
     * @param  array<string, array{key: string, account_type: AccountTypeCode, required: bool}>  $definitions
     */
    private function validateCandidateAccounts(array $candidateData, array $definitions): void
    {
        $settingAccountIds = [];
        foreach (array_keys($definitions) as $key) {
            $val = $candidateData[$key] ?? null;
            if (is_numeric($val) && (int) $val > 0) {
                $settingAccountIds[] = (int) $val;
            }
        }
        $settingAccountIds = array_values(array_unique($settingAccountIds));

        /** @var Collection<int, Account> $accounts */
        $accounts = $settingAccountIds === []
            ? new Collection
            : Account::query()
                ->with('accountType:id,code,name')
                ->whereIn('id', $settingAccountIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        $assignedAccounts = [];
        $errors = [];

        foreach ($definitions as $key => $def) {
            $rawId = $candidateData[$key] ?? null;
            if ($rawId === null) {
                continue;
            }

            $accountId = (int) $rawId;
            $label = AccountingSettingRegistry::label($key);
            /** @var Account|null $account */
            $account = $accounts->get($accountId);

            if (! $account) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_not_found', ['label' => $label])];
            } elseif (! $account->is_active) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_inactive', ['label' => $label])];
            } elseif ($account->accountType?->code !== $def['account_type']->value) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_type_mismatch', [
                    'label' => $label,
                    'type' => AccountingSettingRegistry::accountTypeLabel($def['account_type']),
                ])];
            } elseif (isset($assignedAccounts[$account->id])) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_duplicate', ['label' => $label])];
            } else {
                $assignedAccounts[$account->id] = $key;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripObsoleteKeys(array $data): array
    {
        foreach (AccountingSettingRegistry::obsoleteKeys() as $obsoleteKey) {
            unset($data[$obsoleteKey]);
        }

        return $data;
    }
}
