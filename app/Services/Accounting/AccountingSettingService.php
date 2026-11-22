<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountPurpose;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingSettingService
{
    public function __construct(
        protected SettingService $settingService,
    ) {}

    /**
     * Retrieve the accounting settings record without exposing obsolete keys
     * or overwriting existing user selections.
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

        $defaults = $this->resolveDefaultAccountIds();
        if ($defaults !== []) {
            return $this->settingService->getByType(AccountingSettingRegistry::SETTING_TYPE, $defaults);
        }

        return new Setting([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [],
        ]);
    }

    /**
     * Return structured settings entries with hydrated account info and metadata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStructuredSettings(): array
    {
        $setting = $this->getSettings();
        $data = is_array($setting->data) ? $setting->data : [];

        $accountIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $data),
            static fn (int $id): bool => $id > 0
        )));

        /** @var Collection<int, ChartOfAccount> $accounts */
        $accounts = $accountIds === []
            ? new Collection
            : ChartOfAccount::query()
                ->whereIn('id', $accountIds)
                ->get(['id', 'code', 'name', 'account_type', 'account_purpose'])
                ->keyBy('id');

        $result = [];
        foreach (AccountingSettingRegistry::definitions() as $key => $def) {
            $rawId = $data[$key] ?? null;
            $accountId = is_numeric($rawId) && (int) $rawId > 0 ? (int) $rawId : null;
            /** @var ChartOfAccount|null $account */
            $account = $accountId !== null ? $accounts->get($accountId) : null;
            $purpose = $account?->account_purpose ?? AccountPurpose::GENERAL;

            $result[] = [
                'key' => $key,
                'label' => AccountingSettingRegistry::label($key),
                'description' => AccountingSettingRegistry::description($key),
                'account_type' => $def['account_type']->value,
                'account_type_label' => $def['account_type']->label(),
                'required' => $def['required'],
                'account_id' => $account?->id,
                'account' => $account ? [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type->value,
                    'account_type_label' => $account->account_type->label(),
                    'account_purpose' => $purpose->value,
                    'account_purpose_label' => $purpose->label(),
                ] : null,
            ];
        }

        return $result;
    }

    /**
     * Return compatible selectable accounts per accounting setting key in constant queries.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getCompatibleAccountsByKey(): array
    {
        $accounts = ChartOfAccount::query()
            ->where('account_purpose', AccountPurpose::GENERAL->value)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'account_purpose']);

        $grouped = [];
        foreach (AccountingSettingRegistry::definitions() as $key => $def) {
            $compatible = [];
            foreach ($accounts as $account) {
                if ($account->account_type !== $def['account_type']) {
                    continue;
                }

                $compatible[] = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type->value,
                    'account_purpose' => AccountPurpose::GENERAL->value,
                ];
            }

            $grouped[$key] = $compatible;
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
     * Validate and atomically update accounting settings while stripping obsolete keys
     * and preserving unrelated setting entries.
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

            $existingData = $this->stripObsoleteKeys(
                is_array($existingSetting?->data) ? $existingSetting->data : []
            );
            $definitions = AccountingSettingRegistry::definitions();
            $errors = [];

            foreach (array_keys($input) as $key) {
                if (! isset($definitions[$key])) {
                    $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_key_invalid', ['key' => $key])];
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $candidateData = $existingData;
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
     * Check whether an account is currently referenced by any accounting setting.
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
     * @param  array<string, array<string, mixed>>  $definitions
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

        /** @var Collection<int, ChartOfAccount> $accounts */
        $accounts = $settingAccountIds === []
            ? new Collection
            : ChartOfAccount::query()
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
            /** @var ChartOfAccount|null $account */
            $account = $accounts->get($accountId);

            if (! $account) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_not_found', ['label' => $label])];
            } elseif ($account->account_type !== $def['account_type']) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_type_mismatch', [
                    'label' => $label,
                    'type' => $def['account_type']->label(),
                ])];
            } elseif (($account->account_purpose ?? AccountPurpose::GENERAL) !== AccountPurpose::GENERAL) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_cannot_be_cash_or_bank', ['label' => $label])];
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

    /**
     * Resolve default setting IDs from existing chart rows when settings row does not exist yet.
     *
     * @return array<string, ?int>
     */
    private function resolveDefaultAccountIds(): array
    {
        $definitionsByKey = [];
        foreach (DefaultChartOfAccountsRegistry::accounts() as $entry) {
            $definitionsByKey[$entry['key']] = $entry;
        }

        $codes = [];
        foreach (AccountingSettingRegistry::definitions() as $def) {
            $chartKey = $def['default_chart_key'];
            if ($chartKey !== null && isset($definitionsByKey[$chartKey])) {
                $codes[] = $definitionsByKey[$chartKey]['code'];
            }
        }

        if ($codes === []) {
            return [];
        }

        $accountsByCode = ChartOfAccount::query()
            ->whereIn('code', $codes)
            ->get(['id', 'code', 'account_type'])
            ->keyBy('code');

        $resolved = [];
        foreach (AccountingSettingRegistry::definitions() as $settingKey => $def) {
            $chartKey = $def['default_chart_key'];
            if ($chartKey === null) {
                $resolved[$settingKey] = null;

                continue;
            }

            $entry = $definitionsByKey[$chartKey] ?? null;
            if (! $entry) {
                continue;
            }

            $acc = $accountsByCode->get($entry['code']);
            if ($acc && $acc->account_type === $def['account_type']) {
                $resolved[$settingKey] = $acc->id;
            }
        }

        foreach (AccountingSettingRegistry::requiredKeys() as $reqKey) {
            if (! isset($resolved[$reqKey])) {
                return [];
            }
        }

        return $resolved;
    }
}
