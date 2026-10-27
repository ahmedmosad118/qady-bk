<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ContactType::INDIVIDUAL,
            'name' => fake()->name(),
            'company_contact_person' => null,
            'phone' => '02'.fake()->numerify('########'),
            'mobile' => '01'.fake()->numerify('#########'),
            'email' => fake()->safeEmail(),
            'tax_number' => null,
            'tax_registration_type' => null,
            'commercial_register' => null,
            'is_customer' => true,
            'is_supplier' => false,
            'is_active' => true,
            'notes' => fake()->sentence(),
        ];
    }

    /**
     * State for a company contact.
     */
    public function company(): static
    {
        return $this->state(fn () => [
            'type' => ContactType::COMPANY,
            'name' => fake()->company().' للمحاماة والاستشارات القانونية',
            'company_contact_person' => fake()->name(),
            'tax_number' => fake()->numerify('###-###-###'),
            'tax_registration_type' => 'قيمة مضافة',
            'commercial_register' => fake()->numerify('######'),
        ]);
    }

    /**
     * State for a supplier.
     */
    public function supplier(): static
    {
        return $this->state(fn () => [
            'is_customer' => false,
            'is_supplier' => true,
        ]);
    }

    /**
     * State for a contact that is both a customer and supplier.
     */
    public function bothRoles(): static
    {
        return $this->state(fn () => [
            'is_customer' => true,
            'is_supplier' => true,
        ]);
    }
}
