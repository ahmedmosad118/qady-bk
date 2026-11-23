<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountClassCode;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountType;
use App\Models\FiscalYear;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_seeder_creates_all_default_and_system_accounts_with_correct_types_and_classes(): void
    {
        $this->seed(AccountingSeeder::class);

        $this->assertSame(38, Account::query()->count());
        $this->assertSame(7, Account::query()->where('is_system', true)->count());

        $accounts = Account::query()->with('accountType.accountClass')->get()->keyBy('code');

        // Verify class distribution
        $countsByClass = $accounts->groupBy(fn (Account $a): string => $a->accountType->accountClass->code)
            ->map->count();

        $this->assertSame(10, $countsByClass[AccountClassCode::Asset->value]);
        $this->assertSame(6, $countsByClass[AccountClassCode::Liability->value]);
        $this->assertSame(4, $countsByClass[AccountClassCode::Equity->value]);
        $this->assertSame(4, $countsByClass[AccountClassCode::Income->value]);
        $this->assertSame(12, $countsByClass[AccountClassCode::Expense->value]);
        $this->assertSame(2, $countsByClass[AccountClassCode::OffBalance->value]);

        // Verify specific account rules & owner_current null normal_balance_override
        $cash = $accounts->get('1101');
        $this->assertSame(AccountTypeCode::BankCash->value, $cash->accountType->code);
        $this->assertSame(NormalBalance::Debit, $cash->normal_balance_override);
        $this->assertTrue($cash->reconcile);

        $accumulatedDep = $accounts->get('1503');
        $this->assertSame(NormalBalance::Credit, $accumulatedDep->normal_balance_override);

        $ownerCurrent = $accounts->get('3102');
        $this->assertTrue($ownerCurrent->is_system);
        $this->assertSame('owner_current', $ownerCurrent->system_key);
        $this->assertNull($ownerCurrent->normal_balance_override);

        // Verify all 7 system_keys exist
        $systemKeys = Account::query()->where('is_system', true)->pluck('system_key')->sort()->values()->all();
        $this->assertSame([
            'capital',
            'cost_of_goods_sold',
            'inventory',
            'operating_expenses',
            'owner_current',
            'sales_returns',
            'sales_revenue',
        ], $systemKeys);

        // No fiscal years or periods created
        $this->assertSame(0, FiscalYear::query()->count());
        $this->assertSame(0, AccountingPeriod::query()->count());
    }

    public function test_account_seeder_is_idempotent_and_preserves_user_edits(): void
    {
        $this->seed(AccountingSeeder::class);
        $this->assertSame(38, Account::query()->count());

        $cash = Account::query()->where('code', '1101')->firstOrFail();
        $cash->update([
            'name' => 'خزينة الفرع الرئيسي المعدلة',
            'reconcile' => false,
            'is_active' => false,
        ]);

        $capital = Account::query()->where('system_key', 'capital')->firstOrFail();
        $capital->update([
            'name' => 'رأس المال المعدل',
            'code' => '3199',
        ]);

        // Re-run seeder
        $this->seed(AccountSeeder::class);

        $this->assertSame(38, Account::query()->count());
        $this->assertSame(7, Account::query()->where('is_system', true)->count());

        $cash->refresh();
        $this->assertSame('خزينة الفرع الرئيسي المعدلة', $cash->name);
        $this->assertFalse($cash->reconcile);
        $this->assertFalse($cash->is_active);

        $capital->refresh();
        $this->assertSame('رأس المال المعدل', $capital->name);
        $this->assertSame('3199', $capital->code);
    }

    public function test_account_seeder_throws_when_account_type_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->seed(AccountSeeder::class);
    }

    public function test_account_seeder_detects_code_and_system_key_conflict(): void
    {
        $this->seed(AccountingSeeder::class);

        // Simulate conflict: delete inventory system account and occupy code 1301 with a non-system account
        Account::query()->where('system_key', 'inventory')->delete();
        $currentAssets = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();

        Account::query()->create([
            'account_type_id' => $currentAssets->id,
            'code' => '1301',
            'name' => 'حساب متعارض',
            'is_system' => false,
            'system_key' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->seed(AccountSeeder::class);
    }
}
