<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Services\Accounting\AccountCodeGenerator;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\Accounting\DefaultChartOfAccountsRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChartOfAccountSeeder extends Seeder
{
    public function __construct(
        protected AccountCodeGenerator $codeGenerator,
    ) {}

    /**
     * Seed the simplified default Chart of Accounts, initialize missing Accounting Settings,
     * strip obsolete setting keys, and link unmapped Payment Methods idempotently.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $existingSetting = Setting::query()
                ->where('type', AccountingSettingRegistry::SETTING_TYPE)
                ->lockForUpdate()
                ->first();

            $existingData = is_array($existingSetting?->data) ? $existingSetting->data : [];
            $contactAccountIds = $this->getContactAccountIdSet();

            $settingKeyByChartKey = [];
            foreach (AccountingSettingRegistry::definitions() as $settingKey => $def) {
                if ($def['default_chart_key'] !== null) {
                    $settingKeyByChartKey[$def['default_chart_key']] = $settingKey;
                }
            }

            $seededIdsByKey = [];
            foreach (DefaultChartOfAccountsRegistry::accounts() as $entry) {
                $account = $this->findOrCreateDefaultAccount(
                    $entry,
                    $settingKeyByChartKey[$entry['key']] ?? null,
                    $existingData,
                    $contactAccountIds
                );
                $seededIdsByKey[$entry['key']] = (int) $account->id;
            }

            $this->syncAccountingSettings($existingSetting, $existingData, $seededIdsByKey, $contactAccountIds);
            $this->linkMissingPaymentMethodAccounts($seededIdsByKey);
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $existingSettingData
     * @param  array<int, true>  $contactAccountIds
     */
    private function findOrCreateDefaultAccount(
        array $entry,
        ?string $mappedSettingKey,
        array $existingSettingData,
        array $contactAccountIds
    ): ChartOfAccount {
        /** @var AccountType $type */
        $type = $entry['account_type'];
        /** @var AccountPurpose $purpose */
        $purpose = $entry['account_purpose'];

        // 1. Reuse account already configured in Accounting Settings (even if renamed/recoded).
        if ($mappedSettingKey !== null && is_numeric($existingSettingData[$mappedSettingKey] ?? null)) {
            $configuredId = (int) $existingSettingData[$mappedSettingKey];
            if ($configuredId > 0 && ! isset($contactAccountIds[$configuredId])) {
                $configured = ChartOfAccount::query()
                    ->where('id', $configuredId)
                    ->where('account_type', $type->value)
                    ->first();

                if ($configured) {
                    return $configured;
                }
            }
        }

        // 2. For cash/bank, reuse an existing PaymentMethod's linked account or purpose-classified Asset account.
        if ($purpose !== AccountPurpose::GENERAL) {
            $linkedPmAccount = ChartOfAccount::query()
                ->whereIn('id', PaymentMethod::query()->whereNotNull('chart_of_account_id')->select('chart_of_account_id'))
                ->where('account_type', AccountType::ASSET->value)
                ->where('account_purpose', $purpose->value)
                ->orderBy('id')
                ->first();

            if ($linkedPmAccount && ! isset($contactAccountIds[(int) $linkedPmAccount->id])) {
                return $linkedPmAccount;
            }

            $purposeMatched = ChartOfAccount::query()
                ->where('account_type', AccountType::ASSET->value)
                ->where('account_purpose', $purpose->value)
                ->orderBy('id')
                ->get();

            foreach ($purposeMatched as $candidate) {
                if (! isset($contactAccountIds[(int) $candidate->id])) {
                    return $candidate;
                }
            }
        }

        // 3. Match by canonical new code or legacy code (with matching type, non-contact).
        $codes = array_values(array_unique([$entry['code'], ...$entry['legacy_codes']]));
        $codeMatches = ChartOfAccount::query()
            ->whereIn('code', $codes)
            ->where('account_type', $type->value)
            ->orderBy('id')
            ->get();

        foreach ($codeMatches as $candidate) {
            if (! isset($contactAccountIds[(int) $candidate->id])) {
                return $candidate;
            }
        }

        // 4. Match by canonical name or legacy name (with matching type, non-contact).
        $names = array_values(array_unique([$entry['name'], ...$entry['legacy_names']]));
        $nameMatches = ChartOfAccount::query()
            ->whereIn('name', $names)
            ->where('account_type', $type->value)
            ->orderBy('id')
            ->get();

        foreach ($nameMatches as $candidate) {
            if (! isset($contactAccountIds[(int) $candidate->id])) {
                return $candidate;
            }
        }

        // 5. Create fresh default account without colliding on code.
        $code = $entry['code'];
        if (ChartOfAccount::query()->where('code', $code)->exists()) {
            $code = $this->codeGenerator->generateNextCode();
        }

        return ChartOfAccount::query()->create([
            'code' => $code,
            'name' => $entry['name'],
            'account_type' => $type->value,
            'account_purpose' => $purpose->value,
            'description' => $entry['description'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $existingData
     * @param  array<string, int>  $seededIdsByKey
     * @param  array<int, true>  $contactAccountIds
     */
    private function syncAccountingSettings(
        ?Setting $existingSetting,
        array $existingData,
        array $seededIdsByKey,
        array $contactAccountIds
    ): void {
        $payload = $existingData;
        foreach (AccountingSettingRegistry::obsoleteKeys() as $obsoleteKey) {
            unset($payload[$obsoleteKey]);
        }

        $candidateIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $payload),
            static fn (int $id): bool => $id > 0
        )));

        $existingAccounts = $candidateIds === []
            ? collect()
            : ChartOfAccount::query()
                ->whereIn('id', $candidateIds)
                ->get(['id', 'account_type', 'account_purpose'])
                ->keyBy('id');

        foreach (AccountingSettingRegistry::definitions() as $settingKey => $def) {
            $currentId = is_numeric($payload[$settingKey] ?? null) ? (int) $payload[$settingKey] : null;
            $currentAcc = $currentId !== null ? $existingAccounts->get($currentId) : null;

            $isValidExisting = $currentAcc !== null
                && ! isset($contactAccountIds[(int) $currentAcc->id])
                && $currentAcc->account_type === $def['account_type']
                && ($currentAcc->account_purpose ?? AccountPurpose::GENERAL) === AccountPurpose::GENERAL;

            if ($isValidExisting) {
                $payload[$settingKey] = (int) $currentAcc->id;
            } else {
                $chartKey = $def['default_chart_key'];
                $payload[$settingKey] = $chartKey !== null ? ($seededIdsByKey[$chartKey] ?? null) : null;
            }
        }

        if (! $existingSetting) {
            Setting::query()->create([
                'type' => AccountingSettingRegistry::SETTING_TYPE,
                'data' => $payload,
            ]);

            return;
        }

        $existingSetting->update(['data' => $payload]);
    }

    /**
     * @param  array<string, int>  $seededIdsByKey
     */
    private function linkMissingPaymentMethodAccounts(array $seededIdsByKey): void
    {
        $cashAccountId = $seededIdsByKey['cash'] ?? null;
        $bankAccountId = $seededIdsByKey['bank'] ?? null;

        if ($cashAccountId === null && $bankAccountId === null) {
            return;
        }

        $methods = PaymentMethod::query()
            ->whereNull('chart_of_account_id')
            ->lockForUpdate()
            ->get();

        foreach ($methods as $method) {
            $targetId = $method->isCash() ? $cashAccountId : $bankAccountId;
            if ($targetId !== null) {
                $method->update(['chart_of_account_id' => $targetId]);
            }
        }
    }

    /**
     * @return array<int, true>
     */
    private function getContactAccountIdSet(): array
    {
        $set = [];
        $contacts = Contact::withTrashed()
            ->where(function ($query): void {
                $query->whereNotNull('receivable_account_id')
                    ->orWhereNotNull('payable_account_id');
            })
            ->get(['receivable_account_id', 'payable_account_id']);

        foreach ($contacts as $contact) {
            if ($contact->receivable_account_id !== null) {
                $set[(int) $contact->receivable_account_id] = true;
            }
            if ($contact->payable_account_id !== null) {
                $set[(int) $contact->payable_account_id] = true;
            }
        }

        return $set;
    }
}
