<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_number' => 'EXP-' . now()->format('Ymd') . '-' . fake()->unique()->numerify('####'),
            'expense_category_id' => ExpenseCategory::factory(),
            'contact_id' => null,
            'expense_date' => now()->toDateString(),
            'subtotal' => 100.00,
            'total' => 100.00,
            'status' => ExpenseStatus::DRAFT,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
