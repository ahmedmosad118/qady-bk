<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();
        $this->seed(ChartOfAccountSeeder::class);
    }

    public function test_removed_keys_are_not_returned_and_remaining_settings_load_cleanly(): void
    {
        $res = $this->getJson('/api/settings/accounting');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', AccountingSettingRegistry::SETTING_TYPE);

        $data = $res->json('data.data');
        foreach (['cash', 'bank', 'accounts_receivable', 'accounts_payable'] as $removedKey) {
            $this->assertArrayNotHasKey($removedKey, $data);
        }

        $items = collect($res->json('data.items'))->keyBy('key');
        $this->assertSame([
            'inventory',
            'capital',
            'owner_current',
            'sales_revenue',
            'sales_returns',
            'cost_of_goods_sold',
            'operating_expenses',
        ], $items->keys()->all());

        $this->assertTrue($items->get('inventory')['required']);
        $this->assertFalse($items->get('owner_current')['required']);
        $this->assertNull($items->get('owner_current')['account_id']);
        $this->assertNotNull($items->get('inventory')['account_id']);

        // Approved Arabic labels
        app()->setLocale('ar');
        $arRes = $this->getJson('/api/settings/accounting')->assertOk();
        $arItems = collect($arRes->json('data.items'))->keyBy('key');
        $this->assertSame('حساب مبيعات الكتب', $arItems->get('sales_revenue')['label']);
        $this->assertSame('حساب مردودات المبيعات', $arItems->get('sales_returns')['label']);

        // Cash and bank accounts must be excluded from compatible inventory options
        $cashAccount = ChartOfAccount::query()->where('code', '1001')->firstOrFail();
        $bankAccount = ChartOfAccount::query()->where('code', '1002')->firstOrFail();
        $inventoryCompatibleIds = collect($arRes->json('data.compatible_accounts.inventory'))->pluck('id')->all();
        $this->assertNotContains($cashAccount->id, $inventoryCompatibleIds);
        $this->assertNotContains($bankAccount->id, $inventoryCompatibleIds);
    }

    public function test_unauthorized_user_cannot_view_or_update_accounting_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/settings/accounting')->assertForbidden();
        $this->putJson('/api/settings/accounting', ['settings' => ['inventory' => 1]])->assertForbidden();
    }

    public function test_can_update_setting_to_compatible_account_and_null_optional_key_preserving_unrelated_keys(): void
    {
        // Add an unrelated key inside settings.data to verify it is preserved
        $setting = Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->firstOrFail();
        $data = $setting->data;
        $data['custom_unrelated_flag'] = 'preserve_me';
        $data['cash'] = 999; // simulate legacy leftover
        $setting->update(['data' => $data]);

        $altInventory = ChartOfAccount::query()->create([
            'code' => '1090',
            'name' => 'مخزون فرع الإسكندرية',
            'account_type' => AccountType::ASSET->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);

        $ownerCurrent = ChartOfAccount::query()->create([
            'code' => '3002',
            'name' => 'جاري المالك',
            'account_type' => AccountType::EQUITY->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);

        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'inventory' => $altInventory->id,
                'owner_current' => $ownerCurrent->id,
            ],
        ])->assertOk()
            ->assertJsonPath('data.data.inventory', $altInventory->id)
            ->assertJsonPath('data.data.owner_current', $ownerCurrent->id);

        // Clear optional owner_current -> allowed
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'owner_current' => null,
            ],
        ])->assertOk()
            ->assertJsonPath('data.data.owner_current', null);

        $setting->refresh();
        $this->assertSame($altInventory->id, $setting->data['inventory']);
        $this->assertNull($setting->data['owner_current']);
        $this->assertSame('preserve_me', $setting->data['custom_unrelated_flag']);
        $this->assertArrayNotHasKey('cash', $setting->data);
    }

    public function test_rejects_obsolete_keys_wrong_types_contact_accounts_duplicates_and_null_required_keys(): void
    {
        $costOfGoods = ChartOfAccount::query()->where('code', '5001')->firstOrFail();
        $capital = ChartOfAccount::query()->where('code', '3001')->firstOrFail();

        // Obsolete keys rejected
        foreach (['cash', 'bank', 'accounts_receivable', 'accounts_payable', 'unknown_key'] as $invalidKey) {
            $this->putJson('/api/settings/accounting', [
                'settings' => [$invalidKey => $costOfGoods->id],
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(["settings.{$invalidKey}"]);
        }

        // Wrong AccountType -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['sales_revenue' => $costOfGoods->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.sales_revenue']);

        // Mandatory key cannot be nulled -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['inventory' => null],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.inventory']);

        // Duplicate account assignment (assigning capital account to owner_current too) -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['owner_current' => $capital->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.owner_current']);

        // Cash or bank purpose Asset account cannot be selected for inventory -> rejected
        $cashAccount = ChartOfAccount::query()->where('code', '1001')->firstOrFail();
        $bankAccount = ChartOfAccount::query()->where('code', '1002')->firstOrFail();

        $this->putJson('/api/settings/accounting', [
            'settings' => ['inventory' => $cashAccount->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.inventory']);

        $this->putJson('/api/settings/accounting', [
            'settings' => ['inventory' => $bankAccount->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.inventory']);
    }
}
