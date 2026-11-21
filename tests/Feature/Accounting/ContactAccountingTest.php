<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\ContactAccountService;
use App\Services\ContactService;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creating_customer_only_or_supplier_only_creates_both_independent_detail_accounts(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $arControl = ChartOfAccount::query()->where('code', '1.001.003')->firstOrFail();
        $apControl = ChartOfAccount::query()->where('code', '2.001.001')->firstOrFail();

        // Customer only
        $customerRes = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'عميل فقط',
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertCreated();

        $customer = Contact::query()->findOrFail($customerRes->json('data.id'));
        $this->assertNotNull($customer->receivable_account_id);
        $this->assertNotNull($customer->payable_account_id);
        $this->assertNotSame($customer->receivable_account_id, $customer->payable_account_id);

        $recAcc = $customer->receivableAccount;
        $payAcc = $customer->payableAccount;

        $this->assertSame($arControl->id, $recAcc->parent_id);
        $this->assertSame(AccountType::ASSET, $recAcc->account_type);
        $this->assertTrue($recAcc->isPostable());

        $this->assertSame($apControl->id, $payAcc->parent_id);
        $this->assertSame(AccountType::LIABILITY, $payAcc->account_type);
        $this->assertTrue($payAcc->isPostable());

        // Supplier only
        $supplierRes = $this->postJson('/api/contacts', [
            'type' => 'company',
            'name' => 'دار الفكر للنشر',
            'is_customer' => false,
            'is_supplier' => true,
        ])->assertCreated();

        $supplier = Contact::query()->findOrFail($supplierRes->json('data.id'));
        $this->assertNotNull($supplier->receivable_account_id);
        $this->assertNotNull($supplier->payable_account_id);
        $this->assertNotSame($customer->receivable_account_id, $supplier->receivable_account_id);
        $this->assertNotSame($customer->payable_account_id, $supplier->payable_account_id);
    }

    public function test_contact_account_unique_constraints_prevent_sharing_one_account_across_contacts(): void
    {
        $this->seed(ChartOfAccountSeeder::class);

        $contact1 = app(ContactService::class)->createContact([
            'type' => 'individual',
            'name' => 'جهة أولى',
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $contact2 = Contact::factory()->create([
            'receivable_account_id' => null,
            'payable_account_id' => null,
        ]);

        $this->expectException(QueryException::class);

        $contact2->receivable_account_id = $contact1->receivable_account_id;
        $contact2->save();
    }

    public function test_create_contact_rolls_back_completely_when_account_creation_fails(): void
    {
        // Do NOT seed default chart of accounts -> control accounts missing
        $initialContacts = Contact::query()->count();
        $initialAccounts = ChartOfAccount::query()->count();

        try {
            app(ContactService::class)->createContact([
                'type' => 'individual',
                'name' => 'جهة فاشلة',
                'is_customer' => true,
                'is_supplier' => false,
            ]);
            $this->fail('Expected ValidationException when control accounts are missing.');
        } catch (ValidationException) {
            $this->assertSame($initialContacts, Contact::query()->count());
            $this->assertSame($initialAccounts, ChartOfAccount::query()->count());
        }

        // Also simulate partial failure: AR control exists, AP control deleted -> rolls back AR account & contact too
        $this->seed(ChartOfAccountSeeder::class);
        $arControl = ChartOfAccount::query()->where('code', '1.001.003')->firstOrFail();

        // Corrupt AP setting to point to a missing/wrong account via direct DB update
        $setting = Setting::query()->where('type', 'accounting')->firstOrFail();
        $data = $setting->data;
        $data['accounts_payable'] = $arControl->id; // Invalid: Asset instead of Liability
        $setting->update(['data' => $data]);

        $accountsBefore = ChartOfAccount::query()->count();
        $contactsBefore = Contact::query()->count();

        try {
            app(ContactService::class)->createContact([
                'type' => 'individual',
                'name' => 'جهة فشل جزئي',
                'is_customer' => true,
                'is_supplier' => true,
            ]);
            $this->fail('Expected ValidationException when payable control account is invalid.');
        } catch (ValidationException) {
            $this->assertSame($contactsBefore, Contact::query()->count());
            $this->assertSame($accountsBefore, ChartOfAccount::query()->count());
        }
    }

    public function test_backfill_is_idempotent_and_requires_default_control_accounts(): void
    {
        // Legacy contacts inserted before accounting integration
        $legacyBothNull = Contact::factory()->create(['name' => 'عميل قديم']);
        $legacyBothNull->receivable_account_id = null;
        $legacyBothNull->payable_account_id = null;
        $legacyBothNull->save();

        // Refuses backfill before default chart/settings exist
        try {
            app(ContactAccountService::class)->backfillMissingAccounts();
            $this->fail('Expected ValidationException when control accounts are absent.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        // Seed defaults first
        $this->seed(ChartOfAccountSeeder::class);

        // Create a partially backfilled contact (only receivable set)
        $arControl = ChartOfAccount::query()->where('code', '1.001.003')->firstOrFail();
        $manualRec = ChartOfAccount::query()->create([
            'parent_id' => $arControl->id,
            'code' => '1.001.003.900',
            'name' => 'حساب قديم محفوظ',
            'account_type' => AccountType::ASSET->value,
        ]);
        $legacyPartial = Contact::factory()->create(['name' => 'مورد قديم جزئي']);
        $legacyPartial->receivable_account_id = $manualRec->id;
        $legacyPartial->payable_account_id = null;
        $legacyPartial->save();

        // Run backfill via Artisan command
        $this->artisan('accounting:backfill-contact-accounts')
            ->assertSuccessful();

        $legacyBothNull->refresh();
        $legacyPartial->refresh();

        $this->assertNotNull($legacyBothNull->receivable_account_id);
        $this->assertNotNull($legacyBothNull->payable_account_id);
        $this->assertSame($manualRec->id, $legacyPartial->receivable_account_id);
        $this->assertNotNull($legacyPartial->payable_account_id);

        $accountsCountAfterFirstRun = ChartOfAccount::query()->count();

        // Re-run backfill -> must create zero new accounts
        $stats = app(ContactAccountService::class)->backfillMissingAccounts();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame($accountsCountAfterFirstRun, ChartOfAccount::query()->count());
    }
}
