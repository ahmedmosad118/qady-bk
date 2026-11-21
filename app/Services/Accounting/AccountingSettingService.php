<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\Contact;
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
     * Retrieve the accounting settings record without overwriting existing data.
     */
    public function getSettings(): Setting
    {
        $existing = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if ($existing) {
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
                ->withCount('children')
                ->get(['id', 'parent_id', 'code', 'name', 'account_type'])
                ->keyBy('id');

        $result = [];
        foreach (AccountingSettingRegistry::definitions() as $key => $def) {
            $rawId = $data[$key] ?? null;
            $accountId = is_numeric($rawId) && (int) $rawId > 0 ? (int) $rawId : null;
            /** @var ChartOfAccount|null $account */
            $account = $accountId !== null ? $accounts->get($accountId) : null;

            $result[] = [
                'key' => $key,
                'label' => AccountingSettingRegistry::label($key),
                'description' => AccountingSettingRegistry::description($key),
                'account_type' => $def['account_type']->value,
                'account_type_label' => $def['account_type']->label(),
                'required' => $def['required'],
                'is_control_account' => $def['is_control_account'],
                'account_id' => $account?->id,
                'account' => $account ? [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type->value,
                    'account_type_label' => $account->account_type->label(),
                    'is_group' => $account->isGroup(),
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
            ->withCount('children')
            ->orderBy('code')
            ->get(['id', 'parent_id', 'code', 'name', 'account_type']);

        $contactAccountSet = $this->getContactAccountIdSet();

        // Pre-compute parent IDs that have at least one non-contact child (for control account filtering).
        $parentsWithNonContactChildren = [];
        foreach ($accounts as $acc) {
            if ($acc->parent_id !== null && ! isset($contactAccountSet[$acc->id])) {
                $parentsWithNonContactChildren[(int) $acc->parent_id] = true;
            }
        }

        $grouped = [];
        foreach (AccountingSettingRegistry::definitions() as $key => $def) {
            $compatible = [];
            foreach ($accounts as $account) {
                if (
                    $account->account_type !== $def['account_type']
                    || isset($contactAccountSet[$account->id])
                    || (! $def['is_control_account'] && $account->isGroup())
                    || ($def['is_control_account'] && isset($parentsWithNonContactChildren[$account->id]))
                ) {
                    continue;
                }

                $compatible[] = [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'account_type' => $account->account_type->value,
                    'is_group' => $account->isGroup(),
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
     * Validate and atomically update accounting settings.
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

            $existingData = is_array($existingSetting?->data) ? $existingSetting->data : [];
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

            return $this->settingService->updateByType(AccountingSettingRegistry::SETTING_TYPE, $candidateData);
        });
    }

    /**
     * Check whether an account is currently referenced by any non-control (detail-only) accounting setting.
     */
    public function isAccountUsedInPostingSetting(int $accountId): bool
    {
        return $this->isAccountInSettingKeys($accountId, AccountingSettingRegistry::postingKeys());
    }

    /**
     * Check whether an account is currently referenced by any accounting setting (posting or control).
     */
    public function isAccountLinkedToAnySetting(int $accountId): bool
    {
        return $this->isAccountInSettingKeys($accountId, AccountingSettingRegistry::keys());
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function isAccountInSettingKeys(int $accountId, array $keys): bool
    {
        $setting = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if (! $setting || ! is_array($setting->data)) {
            return false;
        }

        foreach ($keys as $key) {
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
        $accountIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $candidateData),
            static fn (int $id): bool => $id > 0
        )));

        /** @var Collection<int, ChartOfAccount> $accounts */
        $accounts = $accountIds === []
            ? new Collection
            : ChartOfAccount::query()
                ->whereIn('id', $accountIds)
                ->withCount('children')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

        $contactAccountSet = $accountIds === [] ? [] : $this->getContactAccountIdSet($accountIds);
        $errors = [];

        foreach ($definitions as $key => $def) {
            $accountId = $candidateData[$key] ?? null;
            if ($accountId === null) {
                continue;
            }

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
            } elseif (isset($contactAccountSet[$account->id])) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_cannot_be_contact_account', ['label' => $label])];
            } elseif (! $def['is_control_account'] && $account->isGroup()) {
                $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_account_must_be_detail', ['label' => $label])];
            } elseif ($def['is_control_account'] && $account->isGroup()) {
                $this->assertValidControlAccountChildren($account, $key, $label, $errors);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, int>|null  $filterIds
     * @return array<int, true>
     */
    private function getContactAccountIdSet(?array $filterIds = null): array
    {
        $query = Contact::withTrashed()->where(function ($q) use ($filterIds): void {
            if ($filterIds !== null) {
                $q->whereIn('receivable_account_id', $filterIds)
                    ->orWhereIn('payable_account_id', $filterIds);
            } else {
                $q->whereNotNull('receivable_account_id')
                    ->orWhereNotNull('payable_account_id');
            }
        });

        $set = [];
        foreach ($query->get(['receivable_account_id', 'payable_account_id']) as $c) {
            if ($c->receivable_account_id) {
                $set[(int) $c->receivable_account_id] = true;
            }
            if ($c->payable_account_id) {
                $set[(int) $c->payable_account_id] = true;
            }
        }

        return $set;
    }

    /**
     * Ensure a control account only contains contact sub-accounts of the expected side.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function assertValidControlAccountChildren(ChartOfAccount $account, string $key, string $label, array &$errors): void
    {
        $childIds = ChartOfAccount::query()
            ->where('parent_id', $account->id)
            ->pluck('id')
            ->all();

        if ($childIds === []) {
            return;
        }

        $contactColumn = $key === 'accounts_receivable' ? 'receivable_account_id' : 'payable_account_id';

        $matchedCount = Contact::withTrashed()
            ->whereIn($contactColumn, $childIds)
            ->count();

        if ($matchedCount !== count($childIds)) {
            $errors["settings.{$key}"] = [(string) __('accounting.errors.setting_control_account_invalid', ['label' => $label])];
        }
    }

    /**
     * Resolve default setting IDs from existing chart rows when settings row does not exist yet.
     *
     * @return array<string, int>
     */
    private function resolveDefaultAccountIds(): array
    {
        $nodesByKey = [];
        $walk = static function (array $nodes) use (&$walk, &$nodesByKey): void {
            foreach ($nodes as $node) {
                $nodesByKey[$node['key']] = $node;
                if (! empty($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk(DefaultChartOfAccountsRegistry::tree());

        $codes = [];
        foreach (AccountingSettingRegistry::definitions() as $def) {
            $chartKey = $def['default_chart_key'];
            if (isset($nodesByKey[$chartKey])) {
                $codes[] = $nodesByKey[$chartKey]['code'];
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
            $node = $nodesByKey[$def['default_chart_key']] ?? null;
            if (! $node) {
                continue;
            }
            $acc = $accountsByCode->get($node['code']);
            if ($acc && $acc->account_type === $def['account_type']) {
                $resolved[$settingKey] = $acc->id;
            }
        }

        // Only auto-initialize if every required key resolved cleanly.
        foreach (AccountingSettingRegistry::requiredKeys() as $reqKey) {
            if (! isset($resolved[$reqKey])) {
                return [];
            }
        }

        return $resolved;
    }
}
