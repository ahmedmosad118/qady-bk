<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PurchaseInvoiceStatus;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseInvoice>
 */
class PurchaseInvoiceFactory extends Factory
{
    protected $model = PurchaseInvoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $invoiceDate = now()->toDateString();

        return [
            'invoice_number' => 'PI-' . now()->format('Ymd') . '-' . fake()->unique()->numerify('####'),
            'supplier_id' => Contact::factory()->state(['is_supplier' => true]),
            'purchase_order_id' => null,
            'invoice_date' => $invoiceDate,
            'due_date' => $invoiceDate,
            'subtotal' => 0.00,
            'discount_type' => null,
            'discount_value' => 0.00,
            'discount_amount' => 0.00,
            'additional_expenses_total' => 0.00,
            'total' => 0.00,
            'notes' => fake()->optional()->sentence(),
            'status' => PurchaseInvoiceStatus::DRAFT,
            'created_by' => User::factory(),
        ];
    }
}
