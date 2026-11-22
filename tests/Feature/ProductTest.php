<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return $this->actingAsSuperAdmin();
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

    public function test_sku_column_is_removed_from_products_table(): void
    {
        $this->assertFalse(Schema::hasColumn('products', 'sku'));
    }

    public function test_a_product_can_be_created_with_valid_data(): void
    {
        $this->actingUser();

        $category = ProductCategory::factory()->create(['name' => 'فقه']);

        $response = $this->postJson('/api/products', $this->validPayload([
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
            'barcode' => '6221000000017',
            'category_id' => $category->id,
            'deleted_at' => null,
        ]);
    }

    public function test_a_product_can_be_created_without_a_barcode(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload());

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['name' => 'الفقه المقارن', 'barcode' => null]);
    }

    public function test_duplicate_barcode_is_rejected(): void
    {
        $this->actingUser();
        Product::factory()->withBarcode('6221000000024')->create();

        $response = $this->postJson('/api/products', $this->validPayload([
            'barcode' => '6221000000024',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['barcode']);
    }

    public function test_barcode_with_leading_zeros_is_stored_as_text(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload([
            'barcode' => '0012345678901',
        ]));

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['barcode' => '0012345678901']);

        $product = Product::where('barcode', '0012345678901')->first();
        $this->assertSame('0012345678901', $product->barcode);
    }

    public function test_negative_prices_are_rejected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload([
            'purchase_price' => -5,
            'selling_price' => 75,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['purchase_price']);

        $response = $this->postJson('/api/products', $this->validPayload([
            'purchase_price' => 50,
            'selling_price' => -1,
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);
    }

    public function test_selling_price_must_always_be_greater_than_purchase_price(): void
    {
        $this->actingUser();

        // Zero prices (equal) must be rejected
        $this->postJson('/api/products', $this->validPayload([
            'purchase_price' => 0,
            'selling_price' => 0,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);

        // Equal positive prices must be rejected
        $this->postJson('/api/products', $this->validPayload([
            'purchase_price' => 50,
            'selling_price' => 50,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);

        // Selling price lower than purchase price must be rejected
        $this->postJson('/api/products', $this->validPayload([
            'purchase_price' => 100,
            'selling_price' => 80,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);

        // Updating a product so selling price <= purchase price must also be rejected
        $product = Product::factory()->create([
            'purchase_price' => 60,
            'selling_price' => 90,
        ]);

        $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'purchase_price' => 90,
            'selling_price' => 90,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);

        $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'purchase_price' => 100,
            'selling_price' => 95,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['selling_price']);

        // Valid when strictly greater (including 0 purchase -> >0 selling)
        $this->postJson('/api/products', $this->validPayload([
            'purchase_price' => 0,
            'selling_price' => 10,
        ]))->assertCreated();
    }

    public function test_category_id_must_exist_and_be_active(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/products', $this->validPayload([
            'category_id' => 9999,
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors(['category_id']);

        $inactive = ProductCategory::factory()->inactive()->create();

        $response = $this->postJson('/api/products', $this->validPayload([
            'category_id' => $inactive->id,
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
    }

    public function test_barcode_remains_unique_when_updating_and_own_value_is_allowed(): void
    {
        $this->actingUser();
        Product::factory()->withBarcode('6221000000099')->create();
        $product = Product::factory()->withBarcode('6221000000088')->create();

        // Keeping its own barcode is fine.
        $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'barcode' => '6221000000088',
            'name' => 'كتاب معدل',
        ]))->assertOk();

        // Taking someone else's barcode is not.
        $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'barcode' => '6221000000099',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['barcode']);

        $this->assertSame('6221000000088', $product->fresh()->barcode);
    }

    public function test_barcode_can_be_cleared_to_null_on_update(): void
    {
        $this->actingUser();
        $product = Product::factory()->withBarcode('6221000000077')->create();

        $response = $this->putJson("/api/products/{$product->id}", $this->validPayload([
            'barcode' => '',
            'name' => 'كتاب بدون باركود',
        ]));

        $response->assertOk()
            ->assertJsonPath('data.barcode', null);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'barcode' => null,
            'name' => 'كتاب بدون باركود',
        ]);
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

    public function test_can_list_products_with_pagination_stats_category_filter_and_warehouse_stock_sum(): void
    {
        $this->actingUser();

        $category = ProductCategory::factory()->create(['name' => 'تفسير']);

        Product::factory()->count(2)->create(['is_active' => true]);
        Product::factory()->inactive()->create();
        $target = Product::factory()->inCategory($category)->create([
            'name' => 'تفسير ابن كثير',
            'purchase_price' => 120.50,
            'selling_price' => 180.00,
        ]);

        $wh1 = Warehouse::query()->create([
            'code' => 'WH-MAIN',
            'name' => 'المخزن الرئيسي',
            'address' => 'القاهرة',
            'is_default' => true,
            'is_active' => true,
        ]);
        $wh2 = Warehouse::query()->create([
            'code' => 'WH-SUB',
            'name' => 'المخزن الفرعي',
            'address' => 'الجيزة',
            'is_default' => false,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $wh1->id,
            'product_id' => $target->id,
            'quantity' => 12,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $wh2->id,
            'product_id' => $target->id,
            'quantity' => 8,
        ]);

        $response = $this->getJson("/api/products?per_page=2&category_id={$category->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.category.name', 'تفسير');

        $this->assertEquals(120.50, (float) $response->json('data.0.purchase_price'));
        $this->assertEquals(20, (float) $response->json('data.0.warehouse_stocks_sum_quantity'));
    }

    public function test_show_in_shop_defaults_to_false_and_can_be_toggled_on_create_and_update(): void
    {
        $this->actingUser();

        $createdDefault = $this->postJson('/api/products', $this->validPayload());
        $createdDefault->assertCreated()
            ->assertJsonPath('data.show_in_shop', false);

        $createdVisible = $this->postJson('/api/products', $this->validPayload([
            'name' => 'كتاب المتجر',
            'show_in_shop' => true,
        ]));
        $createdVisible->assertCreated()
            ->assertJsonPath('data.show_in_shop', true);

        $productId = (int) $createdVisible->json('data.id');

        $this->putJson("/api/products/{$productId}", $this->validPayload([
            'name' => 'كتاب المتجر',
            'show_in_shop' => false,
        ]))
            ->assertOk()
            ->assertJsonPath('data.show_in_shop', false);

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'show_in_shop' => false,
        ]);

        $this->postJson('/api/products', $this->validPayload([
            'show_in_shop' => 'not-a-boolean',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['show_in_shop']);
    }

    public function test_products_can_be_filtered_by_show_in_shop_and_visible_in_shop_scope(): void
    {
        $this->actingUser();

        $shopActive = Product::factory()->visibleInShop()->create(['name' => 'ظاهر نشط', 'is_active' => true]);
        Product::factory()->visibleInShop()->inactive()->create(['name' => 'ظاهر معطل']);
        Product::factory()->create(['name' => 'مخفي نشط', 'is_active' => true, 'show_in_shop' => false]);

        $visibleResponse = $this->getJson('/api/products?show_in_shop=1');
        $visibleResponse->assertOk()
            ->assertJsonPath('meta.total', 2);

        $hiddenResponse = $this->getJson('/api/products?show_in_shop=0');
        $hiddenResponse->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'مخفي نشط');

        $scopedIds = Product::query()->visibleInShop()->pluck('id')->all();
        $this->assertSame([$shopActive->id], $scopedIds);
    }
}
