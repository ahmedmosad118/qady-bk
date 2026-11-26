<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createSuperAdmin();
    }

    public function test_unauthenticated_user_cannot_access_expense_categories(): void
    {
        $this->getJson('/api/expense-categories')->assertUnauthorized();
        $this->postJson('/api/expense-categories', ['name' => 'Rent'])->assertUnauthorized();
    }

    public function test_can_create_view_update_and_search_expense_categories(): void
    {
        Sanctum::actingAs($this->user);

        $createRes = $this->postJson('/api/expense-categories', [
            'name' => 'Electricity',
            'description' => 'Monthly electricity bills',
        ]);

        $createRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Electricity')
            ->assertJsonPath('data.description', 'Monthly electricity bills');

        $categoryId = $createRes->json('data.id');

        ExpenseCategory::factory()->create(['name' => 'Office Cleaning']);

        // Search filter
        $listRes = $this->getJson('/api/expense-categories?search=Electri');
        $listRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Electricity');

        // List all dropdown endpoint
        $allRes = $this->getJson('/api/expense-categories?all=1');
        $allRes->assertOk()
            ->assertJsonCount(2, 'data');

        // Show single category
        $this->getJson("/api/expense-categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('data.id', $categoryId)
            ->assertJsonPath('data.expenses_count', 0);

        // Update category
        $this->putJson("/api/expense-categories/{$categoryId}", [
            'name' => 'Electricity & Utilities',
            'description' => 'Updated description',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Electricity & Utilities')
            ->assertJsonPath('data.description', 'Updated description');
    }

    public function test_prevents_duplicate_expense_category_names(): void
    {
        Sanctum::actingAs($this->user);
        ExpenseCategory::factory()->create(['name' => 'Rent']);

        $this->postJson('/api/expense-categories', [
            'name' => 'Rent',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $other = ExpenseCategory::factory()->create(['name' => 'Internet']);

        $this->putJson("/api/expense-categories/{$other->id}", [
            'name' => 'Rent',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // Updating same category keeping its own name succeeds
        $this->putJson("/api/expense-categories/{$other->id}", [
            'name' => 'Internet',
            'description' => 'Fiber internet',
        ])->assertOk();
    }

    public function test_can_delete_unused_category_but_prevents_deleting_used_category(): void
    {
        Sanctum::actingAs($this->user);

        $unused = ExpenseCategory::factory()->create(['name' => 'Unused']);
        $used = ExpenseCategory::factory()->create(['name' => 'Used']);

        $expense = Expense::factory()->create([
            'created_by' => $this->user->id,
        ]);
        ExpenseItem::factory()->create([
            'expense_id' => $expense->id,
            'expense_category_id' => $used->id,
        ]);

        // Unused deletes cleanly
        $this->deleteJson("/api/expense-categories/{$unused->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('expense_categories', ['id' => $unused->id]);

        // Used category cannot be deleted via API
        $this->deleteJson("/api/expense-categories/{$used->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);
        $this->assertDatabaseHas('expense_categories', ['id' => $used->id]);
    }

    public function test_unauthorized_user_receives_forbidden_for_category_actions(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $category = ExpenseCategory::factory()->create();

        $this->getJson('/api/expense-categories')->assertForbidden();
        $this->getJson("/api/expense-categories/{$category->id}")->assertForbidden();
        $this->postJson('/api/expense-categories', ['name' => 'Blocked'])->assertForbidden();
        $this->putJson("/api/expense-categories/{$category->id}", ['name' => 'Blocked'])->assertForbidden();
        $this->deleteJson("/api/expense-categories/{$category->id}")->assertForbidden();
    }

    public function test_expenses_count_returns_unique_expense_ids_not_item_count(): void
    {
        Sanctum::actingAs($this->user);

        $category = ExpenseCategory::factory()->create(['name' => 'Utilities']);

        $expense1 = Expense::factory()->create(['created_by' => $this->user->id]);
        ExpenseItem::factory()->count(3)->create([
            'expense_id' => $expense1->id,
            'expense_category_id' => $category->id,
        ]);

        $expense2 = Expense::factory()->create(['created_by' => $this->user->id]);
        ExpenseItem::factory()->create([
            'expense_id' => $expense2->id,
            'expense_category_id' => $category->id,
        ]);

        $this->getJson("/api/expense-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.expenses_count', 2);
    }
}
