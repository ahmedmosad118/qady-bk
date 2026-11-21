<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\ContactService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
        $this->seed(ChartOfAccountSeeder::class);
    }

    public function test_can_read_default_accounting_settings_and_compatible_accounts(): void
    {
        $res = $this->getJson('/api/settings/accounting');

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', AccountingSettingRegistry::SETTING_TYPE);

        $items = collect($res->json('data.items'))->keyBy('key');
        $this->assertTrue($items->has('cash'));
        $this->assertTrue($items->has('accounts_receivable'));
        $this->assertTrue($items->has('accounts_payable'));
        $this->assertTrue($items->has('sales_revenue'));
        $this->assertNotNull($items->get('cash')['account_id']);
        $this->assertTrue($items->get('cash')['required']);
        $this->assertFalse($items->get('owner_current')['required']);
    }

    public function test_unauthorized_user_cannot_view_or_update_accounting_settings(): void
    {
        Gate::define('accounting.settings.view', fn () => false);
        Gate::define('accounting.settings.update', fn () => false);

        $this->getJson('/api/settings/accounting')->assertForbidden();
        $this->putJson('/api/settings/accounting', ['settings' => ['cash' => 1]])->assertForbidden();
    }

    public function test_can_update_setting_to_compatible_detail_account_and_null_optional_key(): void
    {
        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $altCash = ChartOfAccount::query()->create([
            'parent_id' => $currentAssets->id,
            'code' => '1.001.090',
            'name' => 'خزينة فرع الإسكندرية',
            'account_type' => AccountType::ASSET->value,
        ]);

        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'cash' => $altCash->id,
                'owner_current' => null,
            ],
        ])->assertOk()
            ->assertJsonPath('data.data.cash', $altCash->id)
            ->assertJsonPath('data.data.owner_current', null);

        $setting = Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->firstOrFail();
        $this->assertSame($altCash->id, $setting->data['cash']);
        $this->assertNull($setting->data['owner_current']);
    }

    public function test_rejects_group_account_mismatched_type_contact_account_and_mandatory_removal(): void
    {
        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $assetsRoot = ChartOfAccount::query()->where('code', '1')->firstOrFail();
        $costOfGoods = ChartOfAccount::query()->where('code', '5.001')->firstOrFail();

        // Group account on posting key -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['cash' => $currentAssets->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.cash']);

        // Non-contact group account on control key -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['accounts_receivable' => $assetsRoot->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.accounts_receivable']);

        // Wrong AccountType -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['sales_revenue' => $costOfGoods->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.sales_revenue']);

        // Mandatory key cannot be cleared/nulled -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['cash' => null],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.cash']);

        // Unknown key -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['non_existent_key' => $costOfGoods->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.non_existent_key']);

        // Contact sub-account cannot be assigned to a setting -> rejected
        $contact = app(ContactService::class)->createContact([
            'type' => 'individual',
            'name' => 'عميل مرفوض للإعدادات',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $this->putJson('/api/settings/accounting', [
            'settings' => ['cash' => $contact->receivable_account_id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.cash']);
    }

    public function test_updating_settings_still_succeeds_after_contacts_exist_and_does_not_alter_historical_contacts(): void
    {
        $contact = app(ContactService::class)->createContact([
            'type' => 'individual',
            'name' => 'عميل تاريخي',
            'is_customer' => true,
            'is_supplier' => true,
        ]);
        $originalRecId = $contact->receivable_account_id;
        $originalPayId = $contact->payable_account_id;

        // Create an alternate AR control account and switch setting
        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $newArControl = ChartOfAccount::query()->create([
            'parent_id' => $currentAssets->id,
            'code' => '1.001.095',
            'name' => 'عملاء جدد',
            'account_type' => AccountType::ASSET->value,
        ]);

        // Updating another key while old AR control already has contact children works cleanly
        $this->putJson('/api/settings/accounting', [
            'settings' => ['accounts_receivable' => $newArControl->id],
        ])->assertOk()
            ->assertJsonPath('data.data.accounts_receivable', $newArControl->id);

        // Existing contact accounts remain untouched
        $contact->refresh();
        $this->assertSame($originalRecId, $contact->receivable_account_id);
        $this->assertSame($originalPayId, $contact->payable_account_id);
    }
}
