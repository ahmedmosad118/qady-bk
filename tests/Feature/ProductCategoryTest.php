<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_top_level_and_child_categories_can_be_created(): void
    {
        $this->actingUser();

        $top = $this->postJson('/api/product-categories', [
            'name' => 'فقه',
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.parent_id', null)->json('data');

        $child = $this->postJson('/api/product-categories', [
            'name' => 'فقه مالكي',
            'parent_id' => $top['id'],
            'is_active' => true,
        ])->assertCreated()->json('data');

        $this->assertSame($top['id'], $child['parent_id']);

        // The index returns the tree with the child nested under its parent.
        $this->getJson('/api/product-categories')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'فقه')
            ->assertJsonPath('data.0.children.0.name', 'فقه مالكي');
    }

    public function test_a_subcategory_cannot_be_created_inside_another_subcategory(): void
    {
        $this->actingUser();
        $top = ProductCategory::factory()->create();
        $child = ProductCategory::factory()->childOf($top)->create(['name' => 'فقه حنفي']);

        $response = $this->postJson('/api/product-categories', [
            'name' => 'فقه حنفي - فرع',
            'parent_id' => $child->id,
            'is_active' => true,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    }

    public function test_a_category_cannot_be_its_own_parent(): void
    {
        $this->actingUser();
        $top = ProductCategory::factory()->create();

        $this->postJson('/api/product-categories', [
            'name' => 'تصنيف جديد',
            'parent_id' => $top->id,
            'is_active' => true,
        ])->assertCreated();

        $this->putJson("/api/product-categories/{$top->id}", [
            'name' => $top->name,
            'parent_id' => $top->id,
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    }

    public function test_a_category_cannot_be_moved_under_a_different_parent(): void
    {
        $this->actingUser();
        $top = ProductCategory::factory()->create();
        $other = ProductCategory::factory()->create(['name' => 'أصول']);
        $child = ProductCategory::factory()->childOf($top)->create();

        $this->putJson("/api/product-categories/{$child->id}", [
            'name' => $child->name,
            'parent_id' => $other->id,
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    }

    public function test_an_invalid_parent_is_rejected_on_the_backend(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/product-categories', [
            'name' => 'تصنيف',
            'parent_id' => 9999,
            'is_active' => true,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    }

    public function test_category_name_is_required(): void
    {
        $this->actingUser();

        $this->postJson('/api/product-categories', ['is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/product-categories', [
            'name' => str_repeat('خ', 151),
            'is_active' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['name']);
    }

    public function test_deleting_a_category_with_children_is_rejected(): void
    {
        $this->actingUser();
        $top = ProductCategory::factory()->create();
        ProductCategory::factory()->childOf($top)->create();

        $this->deleteJson("/api/product-categories/{$top->id}")
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('product_categories', ['id' => $top->id]);
        $this->assertDatabaseHas('product_categories', ['parent_id' => $top->id]);
    }

    public function test_deleting_a_category_keeps_its_products_with_null_category(): void
    {
        $this->actingUser();
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->inCategory($category)->create();

        $this->deleteJson("/api/product-categories/{$category->id}")->assertOk();

        $this->assertDatabaseMissing('product_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category_id' => null,
            'deleted_at' => null,
        ]);
    }
}
