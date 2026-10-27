<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactAddress>
 */
class ContactAddressFactory extends Factory
{
    protected $model = ContactAddress::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'label' => fake()->randomElement(['المقر الرئيسي', 'فرع الإسكندرية', 'عنوان التوصيل', 'الفوترة']),
            'address' => fake()->streetAddress(),
            'city' => fake()->randomElement(['القاهرة', 'الجيزة', 'الإسكندرية', 'المنصورة']),
            'governorate' => fake()->randomElement(['القاهرة', 'الجيزة', 'الإسكندرية', 'الدقهلية']),
            'postal_code' => fake()->numerify('#####'),
            'country' => 'مصر',
            'is_default' => false,
        ];
    }

    /**
     * Mark address as default.
     */
    public function default(): static
    {
        return $this->state(fn () => [
            'is_default' => true,
        ]);
    }
}
