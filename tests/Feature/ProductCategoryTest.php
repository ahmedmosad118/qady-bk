<?php

namespace Tests\Feature;

use App\Enums\Accounting\AccountTypeCode;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return $this->actingAsSuperAdmin();
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

    public function test_category_can_be_created_with_stock_input_account(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $stockInputAccount = Account::query()->where('system_key', 'stock_input')->firstOrFail();

        $created = $this->postJson('/api/product-categories', [
            'name' => 'كتب التفسير',
            'is_active' => true,
            'stock_input_account_id' => $stockInputAccount->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_input_account_id', $stockInputAccount->id)
            ->json('data');

        $this->assertDatabaseHas('product_categories', [
            'id' => $created['id'],
            'stock_input_account_id' => $stockInputAccount->id,
        ]);

        $this->getJson('/api/product-categories')
            ->assertOk()
            ->assertJsonPath('data.0.stock_input_account_id', $stockInputAccount->id)
            ->assertJsonPath('data.0.stock_input_account.id', $stockInputAccount->id);
    }

    public function test_category_stock_input_account_can_be_updated_and_cleared(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $currentAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::CurrentAssets->value)
            ->firstOrFail();

        $firstAccount = Account::query()->where('system_key', 'stock_input')->firstOrFail();
        $secondAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1399',
            'name' => 'حساب وسيط استلام مخصص',
            'is_active' => true,
            'is_system' => false,
        ]);

        $category = ProductCategory::factory()->create([
            'stock_input_account_id' => $firstAccount->id,
        ]);

        $this->putJson("/api/product-categories/{$category->id}", [
            'name' => $category->name,
            'is_active' => true,
            'stock_input_account_id' => $secondAccount->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.stock_input_account_id', $secondAccount->id);

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'stock_input_account_id' => $secondAccount->id,
        ]);

        $this->putJson("/api/product-categories/{$category->id}", [
            'name' => $category->name,
            'is_active' => true,
            'stock_input_account_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.stock_input_account_id', null);

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'stock_input_account_id' => null,
        ]);
    }

    public function test_category_allows_null_stock_input_account(): void
    {
        $this->actingUser();

        $this->postJson('/api/product-categories', [
            'name' => 'كتب عامة',
            'is_active' => true,
            'stock_input_account_id' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.stock_input_account_id', null);
    }

    public function test_non_existent_stock_input_account_is_rejected(): void
    {
        $this->actingUser();

        $this->postJson('/api/product-categories', [
            'name' => 'فئة بحساب غير موجود',
            'is_active' => true,
            'stock_input_account_id' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_input_account_id']);
    }

    public function test_inactive_stock_input_account_is_rejected(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $currentAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::CurrentAssets->value)
            ->firstOrFail();

        $inactiveAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1398',
            'name' => 'حساب وسيط معطل',
            'is_active' => false,
            'is_system' => false,
        ]);

        $this->postJson('/api/product-categories', [
            'name' => 'فئة بحساب معطل',
            'is_active' => true,
            'stock_input_account_id' => $inactiveAccount->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_input_account_id']);
    }

    public function test_non_current_assets_stock_input_account_is_rejected(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $cashAccount = Account::query()->where('code', '1101')->firstOrFail();

        $this->postJson('/api/product-categories', [
            'name' => 'فئة بحساب غير متوافق',
            'is_active' => true,
            'stock_input_account_id' => $cashAccount->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_input_account_id']);
    }

    public function test_account_linked_to_product_category_cannot_be_deleted(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $currentAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::CurrentAssets->value)
            ->firstOrFail();

        $customAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1397',
            'name' => 'حساب وسيط مرتبط',
            'is_active' => true,
            'is_system' => false,
        ]);

        ProductCategory::factory()->create([
            'stock_input_account_id' => $customAccount->id,
        ]);

        $this->deleteJson("/api/accounting/accounts/{$customAccount->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account']);

        $this->assertDatabaseHas('accounts', ['id' => $customAccount->id]);

        $this->expectException(QueryException::class);
        $customAccount->delete();
    }

    public function test_accounts_endpoint_filters_active_current_assets_accounts(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $currentAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::CurrentAssets->value)
            ->firstOrFail();

        Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1396',
            'name' => 'حساب أصول متداولة معطل',
            'is_active' => false,
            'is_system' => false,
        ]);

        $response = $this->getJson('/api/accounting/accounts?account_type_code=current_assets&is_active=1&per_page=100')
            ->assertOk();

        $items = $response->json('data');
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertSame(AccountTypeCode::CurrentAssets->value, $item['account_type']['code']);
            $this->assertTrue($item['is_active']);
        }
    }

    public function test_account_linked_to_product_category_cannot_be_deactivated_or_changed_to_non_current_assets(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $currentAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::CurrentAssets->value)
            ->firstOrFail();

        $fixedAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::FixedAssets->value)
            ->firstOrFail();

        $linkedAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1395',
            'name' => 'حساب وسيط محمي',
            'is_active' => true,
            'is_system' => false,
        ]);

        ProductCategory::factory()->create([
            'stock_input_account_id' => $linkedAccount->id,
        ]);

        // Cannot deactivate linked account
        $this->putJson("/api/accounting/accounts/{$linkedAccount->id}", [
            'is_active' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);

        // Cannot change type to non-current_assets
        $this->putJson("/api/accounting/accounts/{$linkedAccount->id}", [
            'account_type_id' => $fixedAssetsType->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type_id']);

        // Can still update name, code, and reconcile
        $this->putJson("/api/accounting/accounts/{$linkedAccount->id}", [
            'name' => 'حساب وسيط محدث',
            'code' => '1394',
            'reconcile' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'حساب وسيط محدث')
            ->assertJsonPath('data.code', '1394')
            ->assertJsonPath('data.reconcile', true);
    }

    public function test_accounts_endpoint_paginates_and_searches_beyond_100_current_assets_accounts(): void
    {
        $this->actingUser();
        $this->seed(AccountingSeeder::class);

        $currentAssetsType = AccountType::query()
            ->where('code', AccountTypeCode::CurrentAssets->value)
            ->firstOrFail();

        $rows = [];
        for ($i = 1; $i <= 105; $i++) {
            $rows[] = [
                'account_type_id' => $currentAssetsType->id,
                'code' => '135'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => "حساب أصول متداولة {$i}",
                'is_active' => true,
                'is_system' => false,
                'reconcile' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Account::query()->insert($rows);

        $page1 = $this->getJson('/api/accounting/accounts?account_type_code=current_assets&is_active=1&per_page=100&page=1')
            ->assertOk();
        $this->assertCount(100, $page1->json('data'));
        $this->assertGreaterThan(100, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));

        $page2 = $this->getJson('/api/accounting/accounts?account_type_code=current_assets&is_active=1&per_page=100&page=2')
            ->assertOk();
        $this->assertNotEmpty($page2->json('data'));

        $searchRes = $this->getJson('/api/accounting/accounts?account_type_code=current_assets&is_active=1&search=135105&per_page=10')
            ->assertOk();
        $this->assertCount(1, $searchRes->json('data'));
        $this->assertSame('135105', $searchRes->json('data.0.code'));
    }
}
