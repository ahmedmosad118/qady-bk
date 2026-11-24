<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountTypeCode;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountingSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionsTableSeeder::class,
            AccountingSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'accounting.settings.view',
            'accounting.settings.update',
        ]);
    }

    public function test_only_receivable_and_payable_appear_in_items_with_compatible_active_accounts(): void
    {
        $receivableType = AccountType::query()->where('code', AccountTypeCode::Receivable->value)->firstOrFail();
        $payableType = AccountType::query()->where('code', AccountTypeCode::Payable->value)->firstOrFail();

        $inactiveReceivable = Account::query()->create([
            'account_type_id' => $receivableType->id,
            'code' => '1299',
            'name' => 'عملاء معطل',
            'is_active' => false,
        ]);

        $inactivePayable = Account::query()->create([
            'account_type_id' => $payableType->id,
            'code' => '2199',
            'name' => 'موردون معطل',
            'is_active' => false,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson('/api/settings/accounting')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', AccountingSettingRegistry::SETTING_TYPE);

        $items = collect($res->json('data.items'))->keyBy('key');
        $this->assertSame(['accounts_receivable', 'accounts_payable'], $items->keys()->all());

        foreach (['inventory', 'capital', 'owner_current', 'sales_revenue', 'sales_returns', 'cost_of_goods_sold', 'operating_expenses'] as $legacyKey) {
            $this->assertFalse($items->has($legacyKey));
            $this->assertArrayNotHasKey($legacyKey, $res->json('data.compatible_accounts'));
        }

        $this->assertFalse($items->get('accounts_receivable')['required']);
        $this->assertFalse($items->get('accounts_payable')['required']);

        $activeReceivable = Account::query()->where('code', '1201')->firstOrFail();
        $activePayable = Account::query()->where('code', '2101')->firstOrFail();

        $recCompatibleIds = collect($res->json('data.compatible_accounts.accounts_receivable'))->pluck('id')->all();
        $this->assertSame([$activeReceivable->id], $recCompatibleIds);
        $this->assertNotContains($inactiveReceivable->id, $recCompatibleIds);

        $payCompatibleIds = collect($res->json('data.compatible_accounts.accounts_payable'))->pluck('id')->all();
        $this->assertSame([$activePayable->id], $payCompatibleIds);
        $this->assertNotContains($inactivePayable->id, $payCompatibleIds);
    }

    public function test_preserves_legacy_seven_keys_and_unrelated_json_keys_when_updating(): void
    {
        $receivable = Account::query()->where('code', '1201')->firstOrFail();
        $payable = Account::query()->where('code', '2101')->firstOrFail();

        Setting::query()->create([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [
                'inventory' => 10,
                'capital' => 20,
                'owner_current' => null,
                'sales_revenue' => 30,
                'sales_returns' => 40,
                'cost_of_goods_sold' => 50,
                'operating_expenses' => 60,
                'custom_preserved_key' => 'keep_me',
                'cash' => 999,
                'bank' => 888,
            ],
        ]);

        // Update only accounts_receivable
        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => [
                    'accounts_receivable' => $receivable->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.data.accounts_receivable', $receivable->id)
            ->assertJsonPath('data.data.inventory', 10)
            ->assertJsonPath('data.data.capital', 20)
            ->assertJsonPath('data.data.sales_revenue', 30)
            ->assertJsonPath('data.data.custom_preserved_key', 'keep_me');

        // Update only accounts_payable; accounts_receivable & legacy keys must remain intact
        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => [
                    'accounts_payable' => $payable->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.data.accounts_receivable', $receivable->id)
            ->assertJsonPath('data.data.accounts_payable', $payable->id)
            ->assertJsonPath('data.data.inventory', 10)
            ->assertJsonPath('data.data.operating_expenses', 60)
            ->assertJsonPath('data.data.custom_preserved_key', 'keep_me');

        $freshData = Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->firstOrFail()->data;
        $this->assertArrayNotHasKey('cash', $freshData);
        $this->assertArrayNotHasKey('bank', $freshData);

        // Clear both back to null
        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => [
                    'accounts_receivable' => null,
                    'accounts_payable' => null,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.data.accounts_receivable', null)
            ->assertJsonPath('data.data.accounts_payable', null)
            ->assertJsonPath('data.data.inventory', 10);
    }

    public function test_rejects_invalid_inactive_or_wrong_type_accounts(): void
    {
        $receivableType = AccountType::query()->where('code', AccountTypeCode::Receivable->value)->firstOrFail();
        $inactiveReceivable = Account::query()->create([
            'account_type_id' => $receivableType->id,
            'code' => '1299',
            'name' => 'عملاء معطل',
            'is_active' => false,
        ]);

        $activeReceivable = Account::query()->where('code', '1201')->firstOrFail();
        $activePayable = Account::query()->where('code', '2101')->firstOrFail();

        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => ['accounts_receivable' => 999999],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.accounts_receivable']);

        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => ['accounts_receivable' => $inactiveReceivable->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.accounts_receivable']);

        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => ['accounts_receivable' => $activePayable->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.accounts_receivable']);

        $this->actingAs($this->user)
            ->putJson('/api/settings/accounting', [
                'settings' => ['accounts_payable' => $activeReceivable->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.accounts_payable']);
    }

    public function test_unauthorized_user_cannot_view_or_update_accounting_settings(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/settings/accounting')->assertForbidden();
        $this->putJson('/api/settings/accounting', ['settings' => ['accounts_receivable' => null]])->assertForbidden();
    }
}
