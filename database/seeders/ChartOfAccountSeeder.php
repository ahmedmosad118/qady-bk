<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Services\Accounting\AccountCodeGenerator;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\Accounting\DefaultChartOfAccountsRegistry;
use App\Services\SettingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChartOfAccountSeeder extends Seeder
{
    public function __construct(
        protected AccountCodeGenerator $codeGenerator,
        protected SettingService $settingService,
    ) {}

    /**
     * Seed the default Chart of Accounts and initialize Accounting Settings.
     *
     * Idempotent: running this seeder repeatedly never duplicates accounts
     * and never overwrites user edits to account names, codes, or hierarchy.
     */
    public function run(): void
    {
        if ($this->isAlreadyInstalled()) {
            return;
        }

        DB::transaction(function (): void {
            $seededIdsByKey = [];

            foreach (DefaultChartOfAccountsRegistry::tree() as $rootNode) {
                $this->seedNode($rootNode, null, $seededIdsByKey);
            }

            $existingSetting = Setting::query()
                ->where('type', AccountingSettingRegistry::SETTING_TYPE)
                ->lockForUpdate()
                ->first();

            $existingData = is_array($existingSetting?->data) ? $existingSetting->data : [];

            $existingAccountIds = ChartOfAccount::query()
                ->whereIn('id', array_values(array_filter($existingData, static fn (mixed $v): bool => is_numeric($v))))
                ->pluck('id')
                ->all();
            $existingIdSet = array_flip($existingAccountIds);

            $settingsPayload = [];
            foreach (AccountingSettingRegistry::definitions() as $settingKey => $def) {
                $currentId = $existingData[$settingKey] ?? null;
                if (is_numeric($currentId) && isset($existingIdSet[(int) $currentId])) {
                    $settingsPayload[$settingKey] = (int) $currentId;
                } else {
                    $settingsPayload[$settingKey] = $seededIdsByKey[$def['default_chart_key']] ?? null;
                }
            }

            $this->settingService->updateByType(AccountingSettingRegistry::SETTING_TYPE, $settingsPayload);
        });
    }

    /**
     * Check if accounting settings are already initialized and pointing to existing accounts.
     */
    private function isAlreadyInstalled(): bool
    {
        $setting = Setting::query()
            ->where('type', AccountingSettingRegistry::SETTING_TYPE)
            ->first();

        if (! $setting || ! is_array($setting->data)) {
            return false;
        }

        $requiredIds = [];
        foreach (AccountingSettingRegistry::requiredKeys() as $key) {
            $value = $setting->data[$key] ?? null;
            if (! is_numeric($value) || (int) $value <= 0) {
                return false;
            }
            $requiredIds[] = (int) $value;
        }

        $uniqueIds = array_values(array_unique($requiredIds));
        $foundCount = ChartOfAccount::query()->whereIn('id', $uniqueIds)->count();

        return $foundCount === count($uniqueIds);
    }

    /**
     * Recursively ensure a default chart node and its children exist without overwriting edits.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, int>  $seededIdsByKey
     */
    private function seedNode(array $node, ?int $parentId, array &$seededIdsByKey): void
    {
        $typeValue = $node['account_type']->value;

        // Match existing account by canonical code (of matching type) or by (parent_id + name).
        $existing = ChartOfAccount::query()
            ->where('code', $node['code'])
            ->where('account_type', $typeValue)
            ->first();

        if (! $existing) {
            $existing = ChartOfAccount::query()
                ->where('parent_id', $parentId)
                ->where('name', $node['name'])
                ->where('account_type', $typeValue)
                ->first();
        }

        if (! $existing) {
            $code = $node['code'];
            if (ChartOfAccount::query()->where('code', $code)->exists()) {
                $code = $this->codeGenerator->generateNextCode($parentId);
            }

            $existing = ChartOfAccount::query()->create([
                'parent_id' => $parentId,
                'code' => $code,
                'name' => $node['name'],
                'account_type' => $typeValue,
                'description' => $node['description'] ?? null,
            ]);
        }

        $seededIdsByKey[$node['key']] = (int) $existing->id;

        foreach ($node['children'] ?? [] as $childNode) {
            $this->seedNode($childNode, (int) $existing->id, $seededIdsByKey);
        }
    }
}
