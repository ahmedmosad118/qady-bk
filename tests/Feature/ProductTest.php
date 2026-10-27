<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'الفقه المقارن',
            'purchase_price' => 50,
            'selling_price' => 75,
            'is_active' => true,
        ], $overrides);
    }

    public function test_a_product_can_be_created_with_valid_data(): void
    {
        $this->actingUser();

        $category = ProductCategory::factory()->create(['name' => 'فقه']);

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-1001',
            'barcode' => '6221000000017',
            'author' => 'عبد الرحمن الجزيري',
            'publisher' => 'دار النهضة',
            'edition' => 'الطبعة الأولى',
            'publication_year' => 2020,
            'cover_image' => 'products/covers/1.jpg',
            'category_id' => $category->id,
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'الفقه المقارن');

        $this->assertDatabaseHas('products', [
            'name' => 'الفقه المقارن',
            'sku' => 'LBK-1001',
            'barcode' => '6221000000017',
            'category_id' => $category->id,
            'deleted_at' => null,
        ]);
    }

    public function test_a_product_can_be_created_without_a_barcode(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload(['sku' => 'LBK-2001']));

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['sku' => 'LBK-2001', 'barcode' => null]);
    }

    public function test_an_empty_sku_is_generated_automatically(): void
    {
        $this->actingUser();

        Product::factory()->create(['sku' => 'LBK-0009']);

        $response = $this->postJson('/api/products', $this->validPayload());

        $response->assertCreated()->assertJsonPath('data.sku', 'LBK-0010');
    }

    public function test_duplicated_sku_is_rejected(): void
    {
        $this->actingUser();
        Product::factory()->create(['sku' => 'LBK-3001']);

        $response = $this->postJson('/api/products', $this->validPayload(['sku' => 'LBK-3001']));

        $response->assertUnprocessable()->assertJsonValidationErrors(['sku']);
    }

    public function test_duplicate_barcode_is_rejected(): void
    {
        $this->actingUser();
        Product::factory()->withBarcode('6221000000024')->create();

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-4001',
            'barcode' => '6221000000024',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['barcode']);
    }

    public function test_barcode_with_leading_zeros_is_stored_as_text(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-5001',
            'barcode' => '0012345678901',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['sku' => 'LBK-5001', 'barcode' => '0012345678901']);

        $product = Product::where('sku', 'LBK-5001')->first();
        $this->assertSame('0012345678901', $product->barcode);
    }

    public function test_negative_prices_are_rejected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-6001',
            'purchase_price' => -5,
            'selling_price' => 75,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['purchase_price']);

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-6001',
            'purchase_price' => 50,
            'selling_price' => -1,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);
    }

    public function test_category_id_must_exist_and_be_active(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-7001',
            'category_id' => 9999,
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors(['category_id']);

        $inactive = ProductCategory::factory()->inactive()->create();

        $response = $this->postJson('/api/products', $this->validPayload([
            'sku' => 'LBK-7002',
            'category_id' => $inactive->id,
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
    }

    public function test_sku_remain_unique_when_updating_and_own_values_are_allowed(): void
    {
        $this->actingUser();
        $other = Product::factory()->create(['sku' => 'LBK-8002']);
        $product = Product::factory()->create(['sku' => 'LBK-8001']);

        // Keeping its own sku is fine.
        $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'sku' => 'LBK-8001',
            'name' => 'كتاب معدل',
        ]))->assertOk();

        // Taking someone else's sku is not.
        $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'sku' => 'LBK-8002',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['sku']);

        $this->assertSame('LBK-8001', $product->fresh()->sku);
    }

    public function test_soft_deleting_a_product_keeps_related_records(): void
    {
        $this->actingUser();
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        // The row is soft-deleted: gone from the default scope, still in the table.
        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id));

        // The category is untouched.
        $this->assertDatabaseHas('product_categories', ['id' => $category->id]);
    }
}
