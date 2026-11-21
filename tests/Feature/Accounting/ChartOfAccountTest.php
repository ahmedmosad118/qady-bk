<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\ContactService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class ChartOfAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_unauthenticated_user_cannot_access_chart_of_accounts(): void
    {
        auth()->forgetGuards();

        $this->getJson('/api/accounting/accounts/tree')->assertUnauthorized();
        $this->getJson('/api/accounting/accounts')->assertUnauthorized();
        $this->postJson('/api/accounting/accounts', [])->assertUnauthorized();
    }

    public function test_unauthorized_user_receives_forbidden(): void
    {
        Gate::define('accounting.accounts.view', fn () => false);
        Gate::define('accounting.accounts.create', fn () => false);

        $this->getJson('/api/accounting/accounts/tree')->assertForbidden();
        $this->postJson('/api/accounting/accounts', [
            'name' => 'Test',
            'account_type' => AccountType::ASSET->value,
        ])->assertForbidden();
    }

    public function test_default_seeder_is_idempotent_and_preserves_user_customizations(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $initialCount = ChartOfAccount::query()->count();
        $this->assertGreaterThanOrEqual(30, $initialCount);

        // Customize both a setting-linked account (name + code) and a non-setting account (name + code)
        $cash = ChartOfAccount::query()->where('code', '1.001.001')->firstOrFail();
        $this->putJson("/api/accounting/accounts/{$cash->id}", [
            'name' => 'الخزينة الرئيسية المعدلة',
            'code' => '1.001.099',
        ])->assertOk();

        $marketing = ChartOfAccount::query()->where('code', '5.006')->firstOrFail();
        $this->putJson("/api/accounting/accounts/{$marketing->id}", [
            'name' => 'حملات التسويق الرقمي',
            'code' => '5.099',
        ])->assertOk();

        // Re-run seeder
        $this->seed(ChartOfAccountSeeder::class);

        $this->assertSame($initialCount, ChartOfAccount::query()->count());
        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $cash->id,
            'name' => 'الخزينة الرئيسية المعدلة',
            'code' => '1.001.099',
        ]);
        $this->assertDatabaseHas('chart_of_accounts', [
            'id' => $marketing->id,
            'name' => 'حملات التسويق الرقمي',
            'code' => '5.099',
        ]);

        // Accounting setting still points to the same cash account ID
        $setting = Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->firstOrFail();
        $this->assertSame($cash->id, $setting->data['cash']);
    }

    public function test_can_create_root_and_unlimited_depth_child_accounts_with_auto_codes(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Create new root account -> auto code '6'
        $rootRes = $this->postJson('/api/accounting/accounts', [
            'name' => 'حسابات نظامية',
            'account_type' => AccountType::ASSET->value,
            'description' => 'حساب رئيسي إضافي',
        ]);

        $rootRes->assertCreated()
            ->assertJsonPath('data.code', '6')
            ->assertJsonPath('data.account_type', AccountType::ASSET->value);

        $rootId = (int) $rootRes->json('data.id');

        // Level 2 child -> '6.001'
        $child1 = $this->postJson('/api/accounting/accounts', [
            'parent_id' => $rootId,
            'name' => 'فرع مستوى ثانٍ',
        ])->assertCreated()
            ->assertJsonPath('data.code', '6.001')
            ->assertJsonPath('data.account_type', AccountType::ASSET->value)
            ->json('data');

        // Level 3 child -> '6.001.001'
        $child2 = $this->postJson('/api/accounting/accounts', [
            'parent_id' => $child1['id'],
            'name' => 'فرع مستوى ثالث',
        ])->assertCreated()
            ->assertJsonPath('data.code', '6.001.001')
            ->json('data');

        // Level 4 child -> '6.001.001.001'
        $child3 = $this->postJson('/api/accounting/accounts', [
            'parent_id' => $child2['id'],
            'name' => 'فرع مستوى رابع',
        ])->assertCreated()
            ->assertJsonPath('data.code', '6.001.001.001')
            ->json('data');

        $this->getJson("/api/accounting/accounts/{$child3['id']}")
            ->assertOk()
            ->assertJsonPath('data.level', 4)
            ->assertJsonPath('data.is_postable', true)
            ->assertJsonPath('data.is_group', false);
    }

    public function test_manual_code_and_duplicate_code_prevention(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $expenses = ChartOfAccount::query()->where('code', '5')->firstOrFail();

        $created = $this->postJson('/api/accounting/accounts', [
            'parent_id' => $expenses->id,
            'code' => '5.500',
            'name' => 'مصروفات الطباعة',
        ])->assertCreated()
            ->assertJsonPath('data.code', '5.500')
            ->json('data');

        // Duplicate code on create
        $this->postJson('/api/accounting/accounts', [
            'parent_id' => $expenses->id,
            'code' => '5.500',
            'name' => 'مصروف مكرر',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        // Duplicate code on update
        $this->putJson("/api/accounting/accounts/{$created['id']}", [
            'code' => '5.001',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_updating_parent_code_cascades_to_descendants_without_breaking_links(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $cash = ChartOfAccount::query()->where('code', '1.001.001')->firstOrFail();

        $this->putJson("/api/accounting/accounts/{$currentAssets->id}", [
            'code' => '1.010',
        ])->assertOk()
            ->assertJsonPath('data.code', '1.010');

        $cash->refresh();
        $this->assertSame('1.010.001', $cash->code);

        // Settings link remains intact by primary ID
        $setting = Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->firstOrFail();
        $this->assertSame($cash->id, $setting->data['cash']);
    }

    public function test_prevents_circular_references_and_self_parent(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $assets = ChartOfAccount::query()->where('code', '1')->firstOrFail();
        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $otherCurrent = ChartOfAccount::query()->where('code', '1.001.007')->firstOrFail();

        // Self-parent
        $this->putJson("/api/accounting/accounts/{$currentAssets->id}", [
            'parent_id' => $currentAssets->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        // Descendant as parent (Assets -> Current Assets -> Other Current Assets; setting Assets parent = Other Current Assets)
        $this->putJson("/api/accounting/accounts/{$assets->id}", [
            'parent_id' => $otherCurrent->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_dynamic_group_vs_postable_nature_and_posting_guard(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $otherCurrent = ChartOfAccount::query()->where('code', '1.001.007')->firstOrFail();

        // Initially leaf -> postable
        $this->assertFalse($otherCurrent->isGroup());
        $this->assertTrue($otherCurrent->isPostable());
        $otherCurrent->assertPostable(); // Does not throw

        // Add child -> automatically becomes group account
        $child = $this->postJson('/api/accounting/accounts', [
            'parent_id' => $otherCurrent->id,
            'name' => 'أصل متداول فرعي',
        ])->assertCreated()->json('data');

        $otherCurrent->refresh();
        $this->assertTrue($otherCurrent->isGroup());
        $this->assertFalse($otherCurrent->isPostable());

        // Posting guard blocks group account
        try {
            $otherCurrent->assertPostable();
            $this->fail('Expected LogicException when asserting postable on group account.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        // Delete the only child -> parent returns to postable leaf account
        $this->deleteJson("/api/accounting/accounts/{$child['id']}")->assertOk();

        $otherCurrent->refresh();
        $this->assertFalse($otherCurrent->isGroup());
        $this->assertTrue($otherCurrent->isPostable());
    }

    public function test_prevents_converting_account_used_in_detail_position_into_group(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Cash is referenced by mandatory posting setting 'cash'
        $cash = ChartOfAccount::query()->where('code', '1.001.001')->firstOrFail();
        $this->postJson('/api/accounting/accounts', [
            'parent_id' => $cash->id,
            'name' => 'فرع غير مسموح تحت الصندوق',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        // Contact receivable account is detail-only
        $contact = app(ContactService::class)->createContact([
            'type' => 'individual',
            'name' => 'عميل اختباري',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $this->postJson('/api/accounting/accounts', [
            'parent_id' => $contact->receivable_account_id,
            'name' => 'فرع غير مسموح تحت حساب عميل',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        // Moving an existing leaf account under Cash is also blocked
        $otherCurrent = ChartOfAccount::query()->where('code', '1.001.007')->firstOrFail();
        $this->putJson("/api/accounting/accounts/{$otherCurrent->id}", [
            'parent_id' => $cash->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_enforces_account_type_consistency_with_parent_and_children(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $otherCurrent = ChartOfAccount::query()->where('code', '1.001.007')->firstOrFail();
        $liabilities = ChartOfAccount::query()->where('code', '2')->firstOrFail();

        // Creating child with mismatched type under Asset parent -> rejected
        $this->postJson('/api/accounting/accounts', [
            'parent_id' => $currentAssets->id,
            'name' => 'مصروف خاطئ تحت الأصول',
            'account_type' => AccountType::EXPENSE->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type']);

        // Changing child type to diverge from parent -> rejected
        $this->putJson("/api/accounting/accounts/{$otherCurrent->id}", [
            'account_type' => AccountType::LIABILITY->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type']);

        // Moving Asset child under Liability parent without changing type -> rejected
        $this->putJson("/api/accounting/accounts/{$otherCurrent->id}", [
            'parent_id' => $liabilities->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type']);

        // Changing type of a group account that has children -> rejected
        $this->putJson("/api/accounting/accounts/{$currentAssets->id}", [
            'account_type' => AccountType::EXPENSE->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type']);
    }

    public function test_deletion_guards_block_children_settings_and_contacts(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Has children
        $currentAssets = ChartOfAccount::query()->where('code', '1.001')->firstOrFail();
        $this->deleteJson("/api/accounting/accounts/{$currentAssets->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        // Linked to accounting settings
        $cash = ChartOfAccount::query()->where('code', '1.001.001')->firstOrFail();
        $this->deleteJson("/api/accounting/accounts/{$cash->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        // Linked to contact
        $contact = app(ContactService::class)->createContact([
            'type' => 'individual',
            'name' => 'مورد للاختبار',
            'is_customer' => false,
            'is_supplier' => true,
        ]);
        $this->deleteJson("/api/accounting/accounts/{$contact->payable_account_id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        // Unused leaf account can be deleted
        $marketing = ChartOfAccount::query()->where('code', '5.006')->firstOrFail();
        $this->deleteJson("/api/accounting/accounts/{$marketing->id}")->assertOk();
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $marketing->id]);
    }

    public function test_tree_and_paginated_endpoints_have_constant_query_count_without_n_plus_one(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        // Warm up request pipeline before counting queries
        $this->getJson('/api/accounting/accounts/tree')->assertOk();

        // Measure query count with default tree
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/accounting/accounts/tree')->assertOk();
        $baseQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Add 25 more accounts across nested levels
        $expenses = ChartOfAccount::query()->where('code', '5')->firstOrFail();
        for ($i = 1; $i <= 25; $i++) {
            ChartOfAccount::query()->create([
                'parent_id' => $expenses->id,
                'code' => sprintf('5.%03d', 100 + $i),
                'name' => "مصروف إضافي {$i}",
                'account_type' => AccountType::EXPENSE->value,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $treeRes = $this->getJson('/api/accounting/accounts/tree')->assertOk();
        $largerQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($baseQueryCount, $largerQueryCount);
        $this->assertLessThanOrEqual(6, $largerQueryCount);
        $this->assertCount(5, $treeRes->json('data'));

        // Paginated search & filter
        $this->getJson('/api/accounting/accounts?account_type=expense&search=مصروف إضافي&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(10, 'data');
    }
}
