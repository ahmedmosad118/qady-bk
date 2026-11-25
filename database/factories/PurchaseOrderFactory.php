<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'PO-'.now()->format('Ymd').'-'.fake()->unique()->numerify('####'),
            'supplier_id' => Contact::factory()->state(['is_supplier' => true]),
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(7)->toDateString(),
            'status' => PurchaseOrderStatus::DRAFT,
            'notes' => fake()->optional()->sentence(),
            'subtotal' => 0.00,
            'discount_type' => null,
            'discount_value' => 0.00,
            'discount_amount' => 0.00,
            'additional_expenses_total' => 0.00,
            'total' => 0.00,
            'confirmed_at' => null,
            'confirmed_by' => null,
            'created_by' => User::factory(),
        ];
    }
}
