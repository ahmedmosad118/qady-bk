<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\ExpenseStatus;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\User;
use App\Services\Expense\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = $this->createSuperAdmin();
        $this->category = ExpenseCategory::factory()->create(['name' => 'Operations']);
    }

    public function test_unauthenticated_user_cannot_access_expenses(): void
    {
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->postJson('/api/expenses', [])->assertUnauthorized();
    }

    public function test_create_draft_expense_generates_unique_number_and_calculates_totals_on_backend(): void
    {
        Sanctum::actingAs($this->user);
        $contact = Contact::factory()->create();

        $res = $this->postJson('/api/expenses', [
            'expense_number' => 'MANUAL-9999', // must be ignored
            'subtotal' => 999999, // must be ignored
            'total' => 888888, // must be ignored
            'expense_category_id' => $this->category->id,
            'contact_id' => $contact->id,
            'expense_date' => '2026-11-10',
            'notes' => 'Monthly bills',
            'items' => [
                ['name' => 'Electricity', 'description' => 'Meter A', 'amount' => '120.50'],
                ['name' => 'Water', 'description' => null, 'amount' => '79.50'],
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.expense_category_id', $this->category->id)
            ->assertJsonPath('data.contact_id', $contact->id)
            ->assertJsonPath('data.created_by', $this->user->id)
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonCount(2, 'data.items');

        $number1 = $res->json('data.expense_number');
        $this->assertNotEquals('MANUAL-9999', $number1);
        $this->assertMatchesRegularExpression('/^EXP-\d{8}-\d{4}$/', $number1);

        // Create second expense without optional contact
        $res2 = $this->postJson('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-10',
            'items' => [
                ['name' => 'Courier', 'amount' => 45.25],
            ],
        ]);

        $res2->assertCreated()
            ->assertJsonPath('data.contact_id', null)
            ->assertJsonPath('data.subtotal', '45.25')
            ->assertJsonPath('data.total', '45.25');

        $this->assertNotEquals($number1, $res2->json('data.expense_number'));
    }

    public function test_rejects_expense_without_items_or_non_positive_item_amounts(): void
    {
        Sanctum::actingAs($this->user);

        // Zero items
        $this->postJson('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-10',
            'items' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        // Zero amount item
        $this->postJson('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-10',
            'items' => [
                ['name' => 'Free item', 'amount' => 0],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.amount']);

        // Negative amount item
        $this->postJson('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-10',
            'items' => [
                ['name' => 'Negative', 'amount' => -15],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.amount']);
    }

    public function test_can_edit_draft_expense_add_modify_remove_items_and_preserve_number(): void
    {
        Sanctum::actingAs($this->user);
        $otherCategory = ExpenseCategory::factory()->create(['name' => 'Maintenance']);
        $contact = Contact::factory()->create();

        $createRes = $this->postJson('/api/expenses', [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-10',
            'notes' => 'Initial notes',
            'items' => [
                ['name' => 'Item 1', 'amount' => 100],
                ['name' => 'Item 2', 'amount' => 50],
            ],
        ])->assertCreated();

        $expenseId = $createRes->json('data.id');
        $originalNumber = $createRes->json('data.expense_number');
        $item1Id = $createRes->json('data.items.0.id');

        $updateRes = $this->putJson("/api/expenses/{$expenseId}", [
            'expense_number' => 'HACK-0001',
            'expense_category_id' => $otherCategory->id,
            'contact_id' => $contact->id,
            'expense_date' => '2026-11-12',
            'notes' => 'Updated notes',
            'items' => [
                ['id' => $item1Id, 'name' => 'Item 1 Updated', 'description' => 'New desc', 'amount' => 125.75],
                ['name' => 'Item 3 New', 'amount' => 74.25],
            ],
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.expense_number', $originalNumber)
            ->assertJsonPath('data.expense_category_id', $otherCategory->id)
            ->assertJsonPath('data.contact_id', $contact->id)
            ->assertJsonPath('data.expense_date', '2026-11-12')
            ->assertJsonPath('data.notes', 'Updated notes')
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseMissing('expense_items', [
            'expense_id' => $expenseId,
            'name' => 'Item 2',
        ]);
    }

    public function test_prevents_removing_all_items_and_rejects_foreign_item_ids_on_edit(): void
    {
        Sanctum::actingAs($this->user);

        $expenseA = Expense::factory()->create([
            'expense_category_id' => $this->category->id,
            'created_by' => $this->user->id,
        ]);
        $itemA = ExpenseItem::factory()->create(['expense_id' => $expenseA->id, 'amount' => 50]);

        $expenseB = Expense::factory()->create([
            'expense_category_id' => $this->category->id,
            'created_by' => $this->user->id,
        ]);
        $itemB = ExpenseItem::factory()->create(['expense_id' => $expenseB->id, 'amount' => 90]);

        // Removing all items fails
        $this->putJson("/api/expenses/{$expenseA->id}", [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-12',
            'items' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        // Submitting itemB ID on expenseA fails
        $this->putJson("/api/expenses/{$expenseA->id}", [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-12',
            'items' => [
                ['id' => $itemB->id, 'name' => 'Foreign item', 'amount' => 10],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.id']);
    }

    public function test_can_delete_draft_expense_and_blocks_edit_or_delete_of_posted_expense(): void
    {
        Sanctum::actingAs($this->user);

        $draft = Expense::factory()->create([
            'expense_category_id' => $this->category->id,
            'status' => ExpenseStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);
        ExpenseItem::factory()->create(['expense_id' => $draft->id, 'amount' => 60]);

        $this->deleteJson("/api/expenses/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('expenses', ['id' => $draft->id]);

        $posted = Expense::factory()->create([
            'expense_category_id' => $this->category->id,
            'status' => ExpenseStatus::POSTED,
            'subtotal' => 250,
            'total' => 250,
            'created_by' => $this->user->id,
        ]);
        $postedItem = ExpenseItem::factory()->create(['expense_id' => $posted->id, 'amount' => 250]);

        // Cannot edit posted
        $this->putJson("/api/expenses/{$posted->id}", [
            'expense_category_id' => $this->category->id,
            'expense_date' => '2026-11-14',
            'items' => [
                ['id' => $postedItem->id, 'name' => 'Tamper', 'amount' => 10],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Cannot delete posted
        $this->deleteJson("/api/expenses/{$posted->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('expenses', ['id' => $posted->id, 'total' => 250]);
    }

    public function test_can_filter_sort_and_paginate_expenses(): void
    {
        Sanctum::actingAs($this->user);
        $otherCat = ExpenseCategory::factory()->create(['name' => 'Travel']);
        $contact = Contact::factory()->create(['name' => 'Acme Co']);

        Expense::factory()->create([
            'expense_number' => 'EXP-20261101-0001',
            'expense_category_id' => $this->category->id,
            'contact_id' => $contact->id,
            'expense_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
            'status' => ExpenseStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);

        Expense::factory()->create([
            'expense_number' => 'EXP-20261115-0002',
            'expense_category_id' => $otherCat->id,
            'contact_id' => null,
            'expense_date' => '2026-11-15',
            'subtotal' => 500,
            'total' => 500,
            'status' => ExpenseStatus::POSTED,
            'created_by' => $this->user->id,
        ]);

        // Filter by status=posted
        $this->getJson('/api/expenses?status=posted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.expense_number', 'EXP-20261115-0002');

        // Filter by search
        $this->getJson('/api/expenses?search=0001')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.expense_number', 'EXP-20261101-0001');

        // Filter by category + contact + date range
        $this->getJson("/api/expenses?expense_category_id={$this->category->id}&contact_id={$contact->id}&expense_date_from=2026-11-01&expense_date_to=2026-11-05")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.expense_number', 'EXP-20261101-0001');

        // Sort by total asc
        $this->getJson('/api/expenses?sort_by=total&sort_order=asc&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.expense_number', 'EXP-20261101-0001');
    }

    public function test_failed_transaction_rolls_back_db_and_deletes_uploaded_files(): void
    {
        $service = app(ExpenseService::class);
        $file = UploadedFile::fake()->image('receipt.png');

        $this->expectException(ValidationException::class);

        try {
            $service->createExpense([
                'expense_category_id' => 999999, // triggers failure after/around transaction
                'expense_date' => '2026-11-10',
                'items' => [
                    [
                        'name' => 'Test',
                        'amount' => 50,
                        'attachments' => [$file],
                    ],
                ],
            ], $this->user);
        } finally {
            $this->assertDatabaseCount('expenses', 0);
            $this->assertDatabaseCount('expense_item_attachments', 0);
            $this->assertEmpty(Storage::disk('local')->allFiles());
        }
    }

    public function test_unauthorized_user_receives_forbidden_for_expense_actions(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $expense = Expense::factory()->create([
            'expense_category_id' => $this->category->id,
            'created_by' => $this->user->id,
        ]);

        $this->getJson('/api/expenses')->assertForbidden();
        $this->getJson("/api/expenses/{$expense->id}")->assertForbidden();
        $this->postJson('/api/expenses', [])->assertForbidden();
        $this->putJson("/api/expenses/{$expense->id}", [])->assertForbidden();
        $this->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();
    }
}
