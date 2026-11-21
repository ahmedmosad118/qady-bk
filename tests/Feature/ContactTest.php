<?php

namespace Tests\Feature;

use App\Enums\ContactType;
use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountSeeder::class);
    }

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_can_create_an_individual_contact(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'أحمد محمود القاضي',
            'phone' => '0223456789',
            'mobile' => '01012345678',
            'email' => 'ahmed.kadi@example.com',
            'is_customer' => true,
            'is_supplier' => false,
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'أحمد محمود القاضي')
            ->assertJsonPath('data.type', 'individual');

        $this->assertDatabaseHas('contacts', [
            'name' => 'أحمد محمود القاضي',
            'type' => 'individual',
            'is_customer' => 1,
            'is_supplier' => 0,
            'deleted_at' => null,
        ]);
    }

    public function test_can_create_a_company_contact(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/contacts', [
            'type' => 'company',
            'name' => 'مؤسسة العدالة للمحاماة والاستشارات القانونية',
            'company_contact_person' => 'المستشار محمد نبيل',
            'tax_number' => '123-456-789',
            'tax_registration_type' => 'قيمة مضافة',
            'commercial_register' => '987654',
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_contact_person', 'المستشار محمد نبيل')
            ->assertJsonPath('data.tax_number', '123-456-789');

        $this->assertDatabaseHas('contacts', [
            'name' => 'مؤسسة العدالة للمحاماة والاستشارات القانونية',
            'type' => 'company',
            'tax_number' => '123-456-789',
        ]);
    }

    public function test_can_create_a_contact_that_is_both_customer_and_supplier(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/contacts', [
            'type' => 'company',
            'name' => 'دار المعارف للطباعة والنشر والتوزيع',
            'is_customer' => true,
            'is_supplier' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.is_customer', true)
            ->assertJsonPath('data.is_supplier', true);

        $this->assertDatabaseHas('contacts', [
            'name' => 'دار المعارف للطباعة والنشر والتوزيع',
            'is_customer' => 1,
            'is_supplier' => 1,
        ]);
    }

    public function test_rejects_contact_with_neither_role_enabled(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'محمد سمير',
            'is_customer' => false,
            'is_supplier' => false,
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['is_customer', 'is_supplier']);
    }

    public function test_accepts_contact_without_optional_tax_or_contact_details(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'عميل نقدي بدون بيانات إضافية',
            'is_customer' => true,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('contacts', [
            'name' => 'عميل نقدي بدون بيانات إضافية',
            'phone' => null,
            'mobile' => null,
            'email' => null,
            'tax_number' => null,
            'commercial_register' => null,
        ]);
    }

    public function test_can_create_multiple_addresses_for_one_contact(): void
    {
        $this->actingUser();
        $contact = Contact::factory()->create();

        $addr1 = $this->postJson("/api/contacts/{$contact->id}/addresses", [
            'label' => 'المقر الرئيسي',
            'address' => 'شارع عباس العقاد، مدينة نصر',
            'city' => 'القاهرة',
            'governorate' => 'القاهرة',
            'postal_code' => '11765',
            'country' => 'مصر',
            'is_default' => true,
        ])->assertCreated()->json('data');

        $addr2 = $this->postJson("/api/contacts/{$contact->id}/addresses", [
            'label' => 'فرع الإسكندرية',
            'address' => 'طريق الجيش، لوران',
            'city' => 'الإسكندرية',
            'governorate' => 'الإسكندرية',
            'country' => 'مصر',
            'is_default' => false,
        ])->assertCreated()->json('data');

        $list = $this->getJson("/api/contacts/{$contact->id}/addresses")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertSame($contact->id, $addr1['contact_id']);
        $this->assertSame($contact->id, $addr2['contact_id']);
    }

    public function test_setting_and_changing_default_address_safely_enforces_single_default(): void
    {
        $this->actingUser();
        $contact = Contact::factory()->create();

        $addr1 = $contact->addresses()->create([
            'label' => 'عنوان 1',
            'address' => 'شارع 1',
            'is_default' => true,
        ]);

        $addr2 = $contact->addresses()->create([
            'label' => 'عنوان 2',
            'address' => 'شارع 2',
            'is_default' => false,
        ]);

        $this->assertTrue($addr1->fresh()->is_default);
        $this->assertFalse($addr2->fresh()->is_default);

        // Change default to address 2
        $this->patchJson("/api/contacts/{$contact->id}/addresses/{$addr2->id}/default")->assertOk();

        // Address 1 must now be false, address 2 must be true
        $this->assertFalse($addr1->fresh()->is_default);
        $this->assertTrue($addr2->fresh()->is_default);

        // Verify only ONE default address exists for this contact
        $defaultCount = $contact->addresses()->where('is_default', true)->count();
        $this->assertSame(1, $defaultCount);
    }

    public function test_cannot_access_or_modify_address_of_another_contact(): void
    {
        $this->actingUser();
        $contact1 = Contact::factory()->create();
        $contact2 = Contact::factory()->create();

        $addressOfContact2 = $contact2->addresses()->create([
            'label' => 'عنوان خاص بالمستأجر 2',
            'address' => 'مكان 2',
            'is_default' => true,
        ]);

        // Attempting to modify Contact 2's address through Contact 1's route must return 404
        $this->patchJson("/api/contacts/{$contact1->id}/addresses/{$addressOfContact2->id}/default")
            ->assertNotFound();

        $this->deleteJson("/api/contacts/{$contact1->id}/addresses/{$addressOfContact2->id}")
            ->assertNotFound();
    }

    public function test_safe_soft_deletion_preserves_contact_and_address_history(): void
    {
        $this->actingUser();
        $contact = Contact::factory()->create();
        $address = $contact->addresses()->create([
            'label' => 'مقر تاريخي',
            'address' => 'شارع مصطفى النحاس',
            'is_default' => true,
        ]);

        $this->deleteJson("/api/contacts/{$contact->id}")->assertOk();

        // Contact is soft deleted (deleted_at is set, row remains in DB)
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);

        // Address is preserved for historical records
        $this->assertDatabaseHas('contact_addresses', [
            'id' => $address->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_can_list_contacts_with_stats_and_role_filters(): void
    {
        $this->actingUser();

        Contact::factory()->count(2)->create();
        Contact::factory()->supplier()->create();
        Contact::factory()->company()->bothRoles()->create();

        $this->getJson('/api/contacts?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/contacts?is_supplier=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->getJson('/api/contacts?type=company')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_individual_customer_clears_company_contact_and_tax_fields(): void
    {
        $this->actingUser();

        $created = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'سارة علي',
            'company_contact_person' => 'مسؤول غير مطلوب',
            'tax_number' => '999-888-777',
            'tax_registration_type' => 'قيمة مضافة',
            'commercial_register' => '112233',
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertCreated()->json('data');

        $this->assertNull($created['company_contact_person']);
        $this->assertNull($created['tax_number']);
        $this->assertNull($created['tax_registration_type']);
        $this->assertNull($created['commercial_register']);

        // Switching a company customer to individual clears tax/contact-person fields on update too.
        $company = Contact::factory()->company()->create([
            'is_customer' => true,
            'is_supplier' => false,
        ]);

        $updated = $this->putJson("/api/contacts/{$company->id}", [
            'type' => 'individual',
            'name' => $company->name,
            'company_contact_person' => $company->company_contact_person,
            'tax_number' => $company->tax_number,
            'tax_registration_type' => $company->tax_registration_type,
            'commercial_register' => $company->commercial_register,
            'is_customer' => true,
            'is_supplier' => false,
        ])->assertOk()->json('data');

        $this->assertNull($updated['company_contact_person']);
        $this->assertNull($updated['tax_number']);
        $this->assertNull($updated['tax_registration_type']);
        $this->assertNull($updated['commercial_register']);
    }

    public function test_individual_supplier_preserves_tax_and_contact_person_fields(): void
    {
        $this->actingUser();

        $created = $this->postJson('/api/contacts', [
            'type' => 'individual',
            'name' => 'مؤلف مستقل',
            'company_contact_person' => 'وكيل المؤلف',
            'tax_number' => '555-444-333',
            'tax_registration_type' => 'بطاقة ضريبية',
            'commercial_register' => '778899',
            'is_customer' => false,
            'is_supplier' => true,
        ])->assertCreated()->json('data');

        $this->assertSame('وكيل المؤلف', $created['company_contact_person']);
        $this->assertSame('555-444-333', $created['tax_number']);
        $this->assertSame('بطاقة ضريبية', $created['tax_registration_type']);
        $this->assertSame('778899', $created['commercial_register']);
    }
}
