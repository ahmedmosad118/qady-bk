<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChartOfAccountMigrationTest extends TestCase
{
    public function test_migrations_preserve_legacy_accounts_and_payment_methods_and_remove_contact_accounts_safely(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        $this->artisan('migrate')->assertSuccessful();

        $m1 = require database_path('migrations/2026_11_23_000001_simplify_chart_of_accounts_and_accounting_settings.php');
        $m2 = require database_path('migrations/2026_11_23_000002_add_chart_of_account_id_to_payment_methods_table.php');
        $m3 = require database_path('migrations/2026_11_23_000003_remove_contact_accounts.php');

        // Roll back the three new migrations to simulate an existing pre-migration database
        $m3->down();
        $m2->down();
        $m1->down();

        $this->assertTrue(Schema::hasColumn('chart_of_accounts', 'parent_id'));
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'account_purpose'));
        $this->assertFalse(Schema::hasColumn('payment_methods', 'chart_of_account_id'));
        $this->assertTrue(Schema::hasColumn('contacts', 'receivable_account_id'));
        $this->assertTrue(Schema::hasColumn('contacts', 'payable_account_id'));

        $assetsRootId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => null,
            'code' => '1',
            'name' => 'الأصول',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currentAssetsId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $assetsRootId,
            'code' => '1.001',
            'name' => 'الأصول المتداولة',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customGroupId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $assetsRootId,
            'code' => '1.099',
            'name' => 'مجموعة مخصصة للمستخدم',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $currentAssetsId,
            'code' => '1.001.001',
            'name' => 'خزينة المكتبة الرئيسية',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bankId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $currentAssetsId,
            'code' => '1.001.002',
            'name' => 'حساب البنك الأهلي',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $arControlId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $currentAssetsId,
            'code' => '1.001.003',
            'name' => 'العملاء (الذمم المدينة)',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inventoryId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $currentAssetsId,
            'code' => '1.001.004',
            'name' => 'المخزون',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contactRecId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => $arControlId,
            'code' => '1.001.003.001',
            'name' => 'أحمد - عميل',
            'account_type' => AccountType::ASSET->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contactPayId = DB::table('chart_of_accounts')->insertGetId([
            'parent_id' => null,
            'code' => '2.001.001.001',
            'name' => 'أحمد - مورد',
            'account_type' => AccountType::LIABILITY->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contacts')->insert([
            'type' => 'individual',
            'name' => 'أحمد',
            'is_customer' => 1,
            'is_supplier' => 1,
            'receivable_account_id' => $contactRecId,
            'payable_account_id' => $contactPayId,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('settings')->insert([
            'type' => 'accounting',
            'data' => json_encode([
                'cash' => $cashId,
                'bank' => $bankId,
                'accounts_receivable' => $arControlId,
                'accounts_payable' => 99999,
                'inventory' => $inventoryId,
                'unrelated_key' => 'keep_intact',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashPmId = DB::table('payment_methods')->insertGetId([
            'name' => 'نقدي',
            'type' => PaymentMethodType::CASH->value,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $visaPmId = DB::table('payment_methods')->insertGetId([
            'name' => 'فيزا',
            'type' => PaymentMethodType::VISA->value,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run all three migrations forward
        $m1->up();
        $m2->up();
        $m3->up();

        // 1. Hierarchy & contact account columns removed, purpose added
        $this->assertFalse(Schema::hasColumn('chart_of_accounts', 'parent_id'));
        $this->assertTrue(Schema::hasColumn('chart_of_accounts', 'account_purpose'));
        $this->assertFalse(Schema::hasColumn('contacts', 'receivable_account_id'));
        $this->assertFalse(Schema::hasColumn('contacts', 'payable_account_id'));

        // 2. Legacy unmodified structural groups, control accounts, and contact accounts removed; real accounts preserved
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $assetsRootId]);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $currentAssetsId]);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $arControlId]);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $contactRecId]);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $contactPayId]);

        $this->assertDatabaseHas('chart_of_accounts', ['id' => $customGroupId, 'code' => '1.099', 'name' => 'مجموعة مخصصة للمستخدم']);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $cashId, 'code' => '1.001.001', 'name' => 'خزينة المكتبة الرئيسية', 'account_purpose' => AccountPurpose::CASH->value]);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $bankId, 'code' => '1.001.002', 'name' => 'حساب البنك الأهلي', 'account_purpose' => AccountPurpose::BANK->value]);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $inventoryId, 'code' => '1.001.004', 'account_purpose' => AccountPurpose::GENERAL->value]);

        // 3. Obsolete setting keys removed, valid & unrelated keys preserved
        $updatedSettings = json_decode((string) DB::table('settings')->where('type', 'accounting')->value('data'), true);
        $this->assertArrayNotHasKey('cash', $updatedSettings);
        $this->assertArrayNotHasKey('bank', $updatedSettings);
        $this->assertArrayNotHasKey('accounts_receivable', $updatedSettings);
        $this->assertArrayNotHasKey('accounts_payable', $updatedSettings);
        $this->assertSame($inventoryId, $updatedSettings['inventory']);
        $this->assertSame('keep_intact', $updatedSettings['unrelated_key']);

        // 4. Payment methods preserved IDs/types and linked to classified cash/bank accounts
        $this->assertDatabaseHas('payment_methods', [
            'id' => $cashPmId,
            'type' => PaymentMethodType::CASH->value,
            'chart_of_account_id' => $cashId,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'id' => $visaPmId,
            'type' => PaymentMethodType::VISA->value,
            'chart_of_account_id' => $bankId,
        ]);
    }
}
