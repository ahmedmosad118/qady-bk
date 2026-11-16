<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseCategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
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

        Expense::factory()->create([
            'expense_category_id' => $used->id,
            'created_by' => $this->user->id,
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
        Sanctum::actingAs($this->user);
        $category = ExpenseCategory::factory()->create();

        Gate::define('expense_categories.view', fn () => false);
        Gate::define('expense_categories.create', fn () => false);
        Gate::define('expense_categories.update', fn () => false);
        Gate::define('expense_categories.delete', fn () => false);

        $this->getJson('/api/expense-categories')->assertForbidden();
        $this->getJson("/api/expense-categories/{$category->id}")->assertForbidden();
        $this->postJson('/api/expense-categories', ['name' => 'Blocked'])->assertForbidden();
        $this->putJson("/api/expense-categories/{$category->id}", ['name' => 'Blocked'])->assertForbidden();
        $this->deleteJson("/api/expense-categories/{$category->id}")->assertForbidden();
    }
}
