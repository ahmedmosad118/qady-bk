<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use App\Models\ChartOfAccount;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountCodeGenerator;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\Accounting\ChartOfAccountService;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChartOfAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();
    }

    public function test_unauthenticated_user_cannot_access_chart_of_accounts(): void
    {
        auth()->forgetGuards();

        $this->getJson('/api/accounting/accounts')->assertUnauthorized();
        $this->postJson('/api/accounting/accounts', [])->assertUnauthorized();
    }

    public function test_unauthorized_user_receives_forbidden(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/accounting/accounts')->assertForbidden();
        $this->postJson('/api/accounting/accounts', [
            'name' => 'Test',
            'account_type' => AccountType::ASSET->value,
        ])->assertForbidden();
    }

    public function test_schema_has_no_parent_id_and_has_account_purpose(): void
    {
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'parent_id'));
        $this->assertTrue(Schema::hasColumn('chart_of_accounts', 'account_purpose'));
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'system_key'));
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'normal_balance'));
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'account_level'));
    }

    public function test_default_seeder_is_idempotent_simplified_and_preserves_customizations(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        // Simplified bookstore chart: exactly 8 required accounts, no corporate bloat or control accounts
        $this->assertSame(8, ChartOfAccount::query()->count());
        $this->assertDatabaseMissing('chart_of_accounts', ['name' => 'العملاء (الذمم المدينة)']);
        $this->assertDatabaseMissing('chart_of_accounts', ['name' => 'الموردون (الذمم الدائنة)']);
        $this->assertDatabaseMissing('chart_of_accounts', ['name' => 'الرواتب والأجور']);

        $cash = ChartOfAccount::query()->where('code', '1001')->firstOrFail();
        $this->assertSame('الصندوق الرئيسي', $cash->name);
        $this->assertSame(AccountPurpose::CASH, $cash->account_purpose);

        $salesReturns = ChartOfAccount::query()->where('code', '4002')->firstOrFail();
        $this->assertSame('مردودات المبيعات', $salesReturns->name);

        $bank = ChartOfAccount::query()->where('code', '1002')->firstOrFail();
        $this->assertSame(AccountPurpose::BANK, $bank->account_purpose);

        // Customize both code and name of a setting-linked account and cash account
        $this->putJson("/api/accounting/accounts/{$cash->id}", [
            'name' => 'الخزينة الرئيسية المعدلة',
            'code' => '1099',
        ])->assertOk();

        $inventory = ChartOfAccount::query()->where('code', '1003')->firstOrFail();
        $this->putJson("/api/accounting/accounts/{$inventory->id}", [
            'name' => 'مخزون الكتب المعدل',
            'code' => '1098',
        ])->assertOk();

        // Re-run seeders -> no duplicates, customizations preserved, links intact
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame(8, ChartOfAccount::query()->count());
        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $cash->id,
            'name' => 'الخزينة الرئيسية المعدلة',
            'code' => '1099',
        ]);
        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $inventory->id,
            'name' => 'مخزون الكتب المعدل',
            'code' => '1098',
        ]);

        $setting = Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->firstOrFail();
        $this->assertSame($inventory->id, $setting->data['inventory']);
    }

    public function test_can_create_accounts_across_all_types_with_auto_global_code_and_all_are_postable(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Max numeric code in default chart is 5002 -> next auto code is 5003
        $created1 = $this->postJson('/api/accounting/accounts', [
            'name' => 'مصروفات الشحن',
            'account_type' => AccountType::EXPENSE->value,
            'description' => 'مصاريف توصيل الطلبات',
        ])->assertCreated()
            ->assertJsonPath('data.code', '5003')
            ->assertJsonPath('data.account_type', AccountType::EXPENSE->value)
            ->assertJsonPath('data.account_purpose', AccountPurpose::GENERAL->value)
            ->json('data');

        $created2 = $this->postJson('/api/accounting/accounts', [
            'name' => 'صندوق فرع المعادي',
            'account_type' => AccountType::ASSET->value,
            'account_purpose' => AccountPurpose::CASH->value,
        ])->assertCreated()
            ->assertJsonPath('data.code', '5004')
            ->assertJsonPath('data.account_purpose', AccountPurpose::CASH->value)
            ->json('data');

        $this->getJson("/api/accounting/accounts/{$created1['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_postable', true)
            ->assertJsonPath('data.can_delete', true);

        $this->getJson("/api/accounting/accounts/{$created2['id']}")
            ->assertOk()
            ->assertJsonPath('data.is_postable', true)
            ->assertJsonPath('data.account_purpose', AccountPurpose::CASH->value);
    }

    public function test_manual_code_editing_and_duplicate_code_rejection(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $created = $this->postJson('/api/accounting/accounts', [
            'code' => '7500',
            'name' => 'مصروفات الطباعة',
            'account_type' => AccountType::EXPENSE->value,
        ])->assertCreated()
            ->assertJsonPath('data.code', '7500')
            ->json('data');

        // Duplicate code on create -> 422
        $this->postJson('/api/accounting/accounts', [
            'code' => '7500',
            'name' => 'مصروف مكرر',
            'account_type' => AccountType::EXPENSE->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        // Manual code edit succeeds when unique
        $this->putJson("/api/accounting/accounts/{$created['id']}", [
            'code' => '7501',
        ])->assertOk()
            ->assertJsonPath('data.code', '7501');

        // Duplicate code on update -> 422
        $this->putJson("/api/accounting/accounts/{$created['id']}", [
            'code' => '5001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_concurrent_code_generation_safety_recovers_on_candidate_collision(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Bind a mock generator that tries an already-taken code first, then yields a fresh one
        $generator = $this->createMock(AccountCodeGenerator::class);
        $generator->expects($this->exactly(2))
            ->method('generateNextCode')
            ->willReturnOnConsecutiveCalls('5002', '5003');

        $this->app->instance(AccountCodeGenerator::class, $generator);

        $service = $this->app->make(ChartOfAccountService::class);
        $account = $service->create([
            'name' => 'حساب متزامن',
            'account_type' => AccountType::EXPENSE->value,
        ]);

        $this->assertSame('5003', $account->code);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $account->id, 'code' => '5003']);
    }

    public function test_rejects_cash_or_bank_purpose_on_non_asset_accounts(): void
    {
        $this->postJson('/api/accounting/accounts', [
            'name' => 'مصروف نقدي غير صالح',
            'account_type' => AccountType::EXPENSE->value,
            'account_purpose' => AccountPurpose::CASH->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_purpose']);

        $liability = ChartOfAccount::query()->create([
            'code' => '2001',
            'name' => 'التزام اختباري',
            'account_type' => AccountType::LIABILITY->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);

        $this->putJson("/api/accounting/accounts/{$liability->id}", [
            'account_purpose' => AccountPurpose::BANK->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_purpose']);
    }

    public function test_deletion_and_type_guards_protect_settings_and_payment_methods(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        // Linked to accounting settings
        $inventory = ChartOfAccount::query()->where('code', '1003')->firstOrFail();
        $this->deleteJson("/api/accounting/accounts/{$inventory->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        $this->putJson("/api/accounting/accounts/{$inventory->id}", [
            'account_type' => AccountType::EXPENSE->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type']);

        $this->putJson("/api/accounting/accounts/{$inventory->id}", [
            'account_purpose' => AccountPurpose::CASH->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_purpose']);

        // Linked to payment method
        $cash = ChartOfAccount::query()->where('code', '1001')->firstOrFail();
        $pm = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $this->assertSame($cash->id, $pm->chart_of_account_id);

        $this->deleteJson("/api/accounting/accounts/{$cash->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        $this->putJson("/api/accounting/accounts/{$cash->id}", [
            'account_purpose' => AccountPurpose::BANK->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_purpose']);

        // Unused account can be deleted
        $unused = ChartOfAccount::query()->create([
            'code' => '5999',
            'name' => 'حساب مؤقت للحذف',
            'account_type' => AccountType::EXPENSE->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);

        $this->deleteJson("/api/accounting/accounts/{$unused->id}")->assertOk();
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $unused->id]);
    }

    public function test_paginated_listing_filters_by_type_and_search_with_constant_query_count(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Warm up request pipeline
        $this->getJson('/api/accounting/accounts')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/accounting/accounts?per_page=50')->assertOk();
        $baseQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 1; $i <= 25; $i++) {
            ChartOfAccount::query()->create([
                'code' => (string) (6000 + $i),
                'name' => "مصروف إضافي {$i}",
                'account_type' => AccountType::EXPENSE->value,
                'account_purpose' => AccountPurpose::GENERAL->value,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->getJson('/api/accounting/accounts?account_type=expense&search=مصروف إضافي&per_page=10')->assertOk();
        $largerQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baseQueryCount, $largerQueryCount);
        $this->assertLessThanOrEqual(6, $largerQueryCount);
        $res->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(10, 'data');

        $stats = $res->json('stats');
        $this->assertSame(33, $stats['total']);
        $this->assertArrayNotHasKey('groups', $stats);
        $this->assertArrayNotHasKey('postable', $stats);
    }
}
