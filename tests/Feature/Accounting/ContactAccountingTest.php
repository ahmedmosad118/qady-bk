<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\User;
use App\Services\Accounting\ContactAccountService;
use App\Services\ContactService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();
    }

    public function test_customer_only_creates_only_asset_receivable_account(): void
    {
        $beforeCount = ChartOfAccount::query()->count();

        $res = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'عميل فقط',
            'mobile' => '01010000001',
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertCreated();

        $customer = Contact::query()->findOrFail($res->json('data.id'));
        $this->assertNotNull($customer->receivable_account_id);
        $this->assertNull($customer->payable_account_id);
        $this->assertSame($beforeCount + 1, ChartOfAccount::query()->count());

        $this->assertSame(AccountType::ASSET, $customer->receivableAccount->account_type);
        $this->assertSame(AccountPurpose::GENERAL, $customer->receivableAccount->account_purpose);
    }

    public function test_supplier_only_creates_only_liability_payable_account(): void
    {
        $beforeCount = ChartOfAccount::query()->count();

        $res = $this->postJson('/api/contacts', [
            'type' => 'company',
            'name' => 'دار الفكر للنشر',
            'mobile' => '01010000002',
            'is_customer' => false,
            'is_supplier' => true,
        ])->assertCreated();

        $supplier = Contact::query()->findOrFail($res->json('data.id'));
        $this->assertNull($supplier->receivable_account_id);
        $this->assertNotNull($supplier->payable_account_id);
        $this->assertSame($beforeCount + 1, ChartOfAccount::query()->count());

        $this->assertSame(AccountType::LIABILITY, $supplier->payableAccount->account_type);
        $this->assertSame(AccountPurpose::GENERAL, $supplier->payableAccount->account_purpose);
    }

    public function test_both_roles_creates_exactly_two_accounts_and_does_not_duplicate_on_re_sync(): void
    {
        $beforeCount = ChartOfAccount::query()->count();

        $res = $this->postJson('/api/contacts', [
            'type' => 'company',
            'name' => 'دار المعارف',
            'mobile' => '01010000003',
            'is_customer' => true,
            'is_supplier' => true,
        ])->assertCreated();

        $contact = Contact::query()->findOrFail($res->json('data.id'));
        $this->assertNotNull($contact->receivable_account_id);
        $this->assertNotNull($contact->payable_account_id);
        $this->assertNotSame($contact->receivable_account_id, $contact->payable_account_id);
        $this->assertSame($beforeCount + 2, ChartOfAccount::query()->count());

        // Re-syncing must not duplicate accounts
        app(ContactAccountService::class)->syncAccountsForContact($contact);
        $this->assertSame($beforeCount + 2, ChartOfAccount::query()->count());
    }

    public function test_updating_contact_roles_creates_missing_account_and_preserves_historical_accounts(): void
    {
        // Start as customer-only
        $res = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'عميل يتحول لمورد',
            'mobile' => '01010000004',
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertCreated();

        $contact = Contact::query()->findOrFail($res->json('data.id'));
        $originalRecId = $contact->receivable_account_id;
        $this->assertNotNull($originalRecId);
        $this->assertNull($contact->payable_account_id);

        // Add supplier role -> creates payable account while keeping original receivable
        $this->putJson("/api/contacts/{$contact->id}", [
            'type' => 'individual',
            'name' => $contact->name,
            'mobile' => $contact->mobile,
            'is_customer' => true,
            'is_supplier' => true,
        ])->assertOk();

        $contact->refresh();
        $originalPayId = $contact->payable_account_id;
        $this->assertSame($originalRecId, $contact->receivable_account_id);
        $this->assertNotNull($originalPayId);
        $this->assertSame(AccountType::LIABILITY, $contact->payableAccount->account_type);

        // Remove supplier role -> historical payable account is preserved, not deleted
        $this->putJson("/api/contacts/{$contact->id}", [
            'type' => 'individual',
            'name' => $contact->name,
            'mobile' => $contact->mobile,
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertOk();

        $contact->refresh();
        $this->assertSame($originalRecId, $contact->receivable_account_id);
        $this->assertSame($originalPayId, $contact->payable_account_id);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $originalPayId]);
    }

    public function test_contact_account_unique_constraints_prevent_sharing_one_account_across_contacts(): void
    {
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

    public function test_backfill_respects_contact_roles_preserves_valid_links_handles_invalid_and_soft_deleted_and_is_idempotent(): void
    {
        // 1. Customer-only missing receivable -> should get ONLY receivable
        $customerOnly = Contact::factory()->create([
            'name' => 'عميل قديم',
            'is_customer' => true,
            'is_supplier' => false,
            'receivable_account_id' => null,
            'payable_account_id' => null,
        ]);

        // 2. Supplier-only missing payable -> should get ONLY payable
        $supplierOnly = Contact::factory()->create([
            'name' => 'مورد قديم',
            'is_customer' => false,
            'is_supplier' => true,
            'receivable_account_id' => null,
            'payable_account_id' => null,
        ]);

        // 3. Both roles, partially configured (valid Asset receivable, missing payable)
        $validRec = ChartOfAccount::query()->create([
            'code' => '9001',
            'name' => 'حساب عميل محفوظ',
            'account_type' => AccountType::ASSET->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);
        $partialBoth = Contact::factory()->bothRoles()->create([
            'name' => 'جهة قديمة جزئية',
            'receivable_account_id' => $validRec->id,
            'payable_account_id' => null,
        ]);

        // 4. Customer-only with invalid receivable type (Liability) -> repaired to Asset, payable remains null
        $wrongTypeRec = ChartOfAccount::query()->create([
            'code' => '9002',
            'name' => 'حساب خاطئ النوع',
            'account_type' => AccountType::LIABILITY->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);
        $invalidRecCustomer = Contact::factory()->create([
            'name' => 'عميل برابط خاطئ',
            'is_customer' => true,
            'is_supplier' => false,
            'receivable_account_id' => $wrongTypeRec->id,
            'payable_account_id' => null,
        ]);

        // 5. Soft-deleted supplier-only missing payable
        $softDeletedSupplier = Contact::factory()->supplier()->create([
            'name' => 'مورد محذوف',
            'receivable_account_id' => null,
            'payable_account_id' => null,
        ]);
        $softDeletedSupplier->delete();

        // Run backfill via Artisan command without needing control accounts or settings
        $this->artisan('accounting:backfill-contact-accounts')
            ->assertSuccessful();

        $customerOnly->refresh();
        $supplierOnly->refresh();
        $partialBoth->refresh();
        $invalidRecCustomer->refresh();
        $softDeletedSupplier->refresh();

        // Customer-only gets ONLY receivable
        $this->assertNotNull($customerOnly->receivable_account_id);
        $this->assertNull($customerOnly->payable_account_id);
        $this->assertSame(AccountType::ASSET, $customerOnly->receivableAccount->account_type);

        // Supplier-only gets ONLY payable
        $this->assertNull($supplierOnly->receivable_account_id);
        $this->assertNotNull($supplierOnly->payable_account_id);
        $this->assertSame(AccountType::LIABILITY, $supplierOnly->payableAccount->account_type);

        // Partial both preserved valid receivable and created payable
        $this->assertSame($validRec->id, $partialBoth->receivable_account_id);
        $this->assertNotNull($partialBoth->payable_account_id);

        // Invalid receivable safely repaired; legacy account row preserved
        $this->assertNotSame($wrongTypeRec->id, $invalidRecCustomer->receivable_account_id);
        $this->assertSame(AccountType::ASSET, $invalidRecCustomer->receivableAccount->account_type);
        $this->assertNull($invalidRecCustomer->payable_account_id);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $wrongTypeRec->id]);

        // Soft-deleted supplier-only backfilled with ONLY payable
        $this->assertNull($softDeletedSupplier->receivable_account_id);
        $this->assertNotNull($softDeletedSupplier->payable_account_id);

        $accountsCountAfterFirstRun = ChartOfAccount::query()->count();

        // Re-run backfill -> strictly idempotent
        $stats = app(ContactAccountService::class)->backfillMissingAccounts();
        $this->assertSame(0, $stats['processed']);
        $this->assertSame($accountsCountAfterFirstRun, ChartOfAccount::query()->count());
    }

    public function test_prune_unneeded_removes_only_role_mismatched_accounts_safely(): void
    {
        $recForCustomer = ChartOfAccount::query()->create([
            'code' => '9101',
            'name' => 'عميل - عميل',
            'account_type' => AccountType::ASSET->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);
        $unneededPayForCustomer = ChartOfAccount::query()->create([
            'code' => '9102',
            'name' => 'عميل - مورد غير مطلوب',
            'account_type' => AccountType::LIABILITY->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);
        $customerOnly = Contact::factory()->create([
            'is_customer' => true,
            'is_supplier' => false,
            'receivable_account_id' => $recForCustomer->id,
            'payable_account_id' => $unneededPayForCustomer->id,
        ]);

        $unneededRecForSupplier = ChartOfAccount::query()->create([
            'code' => '9103',
            'name' => 'مورد - عميل غير مطلوب',
            'account_type' => AccountType::ASSET->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);
        $payForSupplier = ChartOfAccount::query()->create([
            'code' => '9104',
            'name' => 'مورد - مورد',
            'account_type' => AccountType::LIABILITY->value,
            'account_purpose' => AccountPurpose::GENERAL->value,
        ]);
        $supplierOnly = Contact::factory()->create([
            'is_customer' => false,
            'is_supplier' => true,
            'receivable_account_id' => $unneededRecForSupplier->id,
            'payable_account_id' => $payForSupplier->id,
        ]);

        $both = app(ContactService::class)->createContact([
            'type' => 'company',
            'name' => 'عميل ومورد معاً',
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $this->artisan('accounting:backfill-contact-accounts --prune-unneeded')
            ->assertSuccessful();

        $customerOnly->refresh();
        $supplierOnly->refresh();
        $both->refresh();

        $this->assertSame($recForCustomer->id, $customerOnly->receivable_account_id);
        $this->assertNull($customerOnly->payable_account_id);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $unneededPayForCustomer->id]);

        $this->assertNull($supplierOnly->receivable_account_id);
        $this->assertSame($payForSupplier->id, $supplierOnly->payable_account_id);
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $unneededRecForSupplier->id]);

        // Both-roles contact retains both accounts
        $this->assertNotNull($both->receivable_account_id);
        $this->assertNotNull($both->payable_account_id);

        // Re-running is idempotent
        $this->artisan('accounting:backfill-contact-accounts --prune-unneeded')
            ->expectsOutputToContain('Contacts updated: 0, Receivable removed: 0, Payable removed: 0.')
            ->assertSuccessful();
    }
}
