<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\NormalBalance;
use App\Enums\PaymentMethodType;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChartOfAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionsTableSeeder::class,
            AccountingSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo([
            'accounting.accounts.view',
            'accounting.accounts.create',
            'accounting.accounts.update',
            'accounting.accounts.delete',
        ]);
    }

    public function test_tree_returns_all_classes_and_account_types_with_counts(): void
    {
        $bankCash = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();

        Account::query()->create([
            'account_type_id' => $bankCash->id,
            'code' => '9901',
            'name' => 'خزينة اختبارية',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/accounting/accounts/tree')
            ->assertOk()
            ->assertJsonPath('success', true);

        $classes = $response->json('data');
        $this->assertCount(6, $classes);

        $totalTypes = array_sum(array_map(static fn (array $c): int => count($c['account_types']), $classes));
        $this->assertSame(18, $totalTypes);

        $assetClass = collect($classes)->firstWhere('code', 'asset');
        $bankCashNode = collect($assetClass['account_types'])->firstWhere('code', 'bank_cash');
        $this->assertSame(3, $bankCashNode['accounts_count']); // 1101, 1102 + 9901
    }

    public function test_index_supports_type_filter_search_and_pagination(): void
    {
        $bankCash = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();
        $expenses = AccountType::query()->where('code', AccountTypeCode::Expenses->value)->firstOrFail();

        for ($i = 1; $i <= 5; $i++) {
            Account::query()->create([
                'account_type_id' => $bankCash->id,
                'code' => '991'.$i,
                'name' => "خزينة {$i}",
            ]);
        }

        Account::query()->create([
            'account_type_id' => $expenses->id,
            'code' => '9951',
            'name' => 'مصروف اختباري',
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/accounting/accounts?account_type_id={$bankCash->id}&per_page=2&page=2")
            ->assertOk()
            ->assertJsonPath('meta.total', 7) // 1101, 1102 + 5 created
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 4)
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->admin)
            ->getJson("/api/accounting/accounts?account_type_id={$bankCash->id}&search=9913")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', '9913');
    }

    public function test_can_create_update_and_delete_account_with_validation(): void
    {
        $bankCash = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();

        $createRes = $this->actingAs($this->admin)
            ->postJson('/api/accounting/accounts', [
                'account_type_id' => $bankCash->id,
                'code' => '9921',
                'name' => 'خزينة فرعية',
                'normal_balance_override' => NormalBalance::Debit->value,
                'reconcile' => true,
                'is_active' => true,
                'is_system' => true, // Must be ignored
                'system_key' => 'hacked', // Must be ignored
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', '9921')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.reconcile', true);

        $accountId = (int) $createRes->json('data.id');
        $this->assertNull(Account::query()->findOrFail($accountId)->system_key);

        // Duplicate code rejected
        $this->actingAs($this->admin)
            ->postJson('/api/accounting/accounts', [
                'account_type_id' => $bankCash->id,
                'code' => '9921',
                'name' => 'مكرر',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        // Update account
        $this->actingAs($this->admin)
            ->putJson("/api/accounting/accounts/{$accountId}", [
                'name' => 'الخزينة المحدثة',
                'reconcile' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'الخزينة المحدثة')
            ->assertJsonPath('data.reconcile', false);

        // Delete account
        $this->actingAs($this->admin)
            ->deleteJson("/api/accounting/accounts/{$accountId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(Account::query()->find($accountId));
    }

    public function test_protects_system_accounts_and_linked_accounts(): void
    {
        $bankCash = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();
        $expenses = AccountType::query()->where('code', AccountTypeCode::Expenses->value)->firstOrFail();

        $systemAccount = Account::query()->create([
            'account_type_id' => $bankCash->id,
            'code' => '1000',
            'name' => 'حساب نظامي',
            'is_system' => true,
            'system_key' => 'sys_cash',
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/accounting/accounts/{$systemAccount->id}", [
                'code' => '9999',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->actingAs($this->admin)
            ->putJson("/api/accounting/accounts/{$systemAccount->id}", [
                'account_type_id' => $expenses->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type_id']);

        $this->actingAs($this->admin)
            ->deleteJson("/api/accounting/accounts/{$systemAccount->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        $pmAccount = Account::query()->create([
            'account_type_id' => $bankCash->id,
            'code' => '1105',
            'name' => 'صندوق نقاط البيع',
        ]);

        PaymentMethod::query()->create([
            'name' => 'نقدي',
            'type' => PaymentMethodType::CASH->value,
            'account_id' => $pmAccount->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson("/api/accounting/accounts/{$pmAccount->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);
    }
}
