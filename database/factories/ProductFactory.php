<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $purchase = fake()->randomFloat(2, 10, 200);

        return [
            'category_id' => null,
            'name' => fake()->unique()->sentence(4),
            'sku' => 'LBK-'.fake()->unique()->numberBetween(1, 999999),
            'barcode' => null,
            'author' => fake()->name(),
            'publisher' => fake()->company(),
            'edition' => fake()->randomElement([null, 'الطبعة الأولي', 'الطبعة الثانية']),
            'publication_year' => fake()->numberBetween(1990, 2026),
            'cover_image' => null,
            'purchase_price' => $purchase,
            'selling_price' => round($purchase * 1.4, 2),
            'is_active' => true,
        ];
    }

    /**
     * A product inside the given category.
     */
    public function inCategory(ProductCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }

    /**
     * A product with a barcode.
     */
    public function withBarcode(?string $barcode = null): static
    {
        return $this->state(fn (array $attributes) => [
            'barcode' => $barcode ?? (string) fake()->unique()->numberBetween(9000000000000, 9999999999999),
        ]);
    }

    /**
     * An inactive product.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
