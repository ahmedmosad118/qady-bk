<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Enums\ExpenseStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\Journal;
use App\Models\User;
use App\Services\Expense\ExpenseService;
use Database\Seeders\AccountingSeeder;
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

    private ExpenseCategory $secondCategory;

    private Journal $expenseJournal;

    private Account $expenseAccount;

    private Account $secondExpenseAccount;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(AccountingSeeder::class);

        $this->user = $this->createSuperAdmin();
        $this->category = ExpenseCategory::factory()->create(['name' => 'Operations']);
        $this->secondCategory = ExpenseCategory::factory()->create(['name' => 'Maintenance']);

        $this->expenseJournal = Journal::query()->create([
            'code' => 'EXP-MAIN',
            'name' => 'Expense Journal',
            'type' => JournalType::Expense,
            'is_active' => true,
        ]);

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Expenses->value))
            ->orderBy('id')
            ->get();

        $this->expenseAccount = $accounts->get(0);
        $this->secondExpenseAccount = $accounts->get(1) ?? $this->expenseAccount;
    }

    public function test_unauthenticated_user_cannot_access_expenses(): void
    {
        $this->getJson('/api/expenses')->assertUnauthorized();
        $this->postJson('/api/expenses', [])->assertUnauthorized();
    }

    public function test_create_draft_expense_with_multiple_items_accounts_categories_and_supplier(): void
    {
        Sanctum::actingAs($this->user);
        $supplier = Contact::factory()->create([
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);

        $res = $this->postJson('/api/expenses', [
            'expense_number' => 'MANUAL-9999',
            'subtotal' => 999999,
            'total' => 888888,
            'contact_id' => $supplier->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'notes' => 'Monthly bills',
            'items' => [
                [
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'reference_number' => 'INV-1001',
                    'description' => 'Electricity meter A',
                    'amount' => '120.50',
                ],
                [
                    'account_id' => $this->secondExpenseAccount->id,
                    'expense_category_id' => $this->secondCategory->id,
                    'reference_number' => null,
                    'description' => 'Plumbing repair',
                    'amount' => '79.50',
                ],
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.contact_id', $supplier->id)
            ->assertJsonPath('data.journal_id', $this->expenseJournal->id)
            ->assertJsonPath('data.created_by', $this->user->id)
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.total', '200.00')
            ->assertJsonPath('data.items.0.reference_number', 'INV-1001')
            ->assertJsonPath('data.items.0.account_id', $this->expenseAccount->id)
            ->assertJsonPath('data.items.0.expense_category_id', $this->category->id)
            ->assertJsonPath('data.items.1.expense_category_id', $this->secondCategory->id)
            ->assertJsonCount(2, 'data.items');

        $number1 = $res->json('data.expense_number');
        $this->assertNotEquals('MANUAL-9999', $number1);
        $this->assertMatchesRegularExpression('/^EXP-\d{8}-\d{4}$/', $number1);

        // Create second expense without optional supplier
        $res2 = $this->postJson('/api/expenses', [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'items' => [
                [
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'amount' => 45.25,
                ],
            ],
        ]);

        $res2->assertCreated()
            ->assertJsonPath('data.contact_id', null)
            ->assertJsonPath('data.subtotal', '45.25')
            ->assertJsonPath('data.total', '45.25');

        $this->assertNotEquals($number1, $res2->json('data.expense_number'));
    }

    public function test_validates_journal_supplier_accounts_categories_and_amounts(): void
    {
        Sanctum::actingAs($this->user);

        $inactiveJournal = Journal::query()->create([
            'code' => 'EXP-INACT',
            'name' => 'Inactive Expense Journal',
            'type' => JournalType::Expense,
            'is_active' => false,
        ]);
        $purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();
        $cashJournal = Journal::query()->where('type', JournalType::Cash->value)->firstOrFail();

        $inactiveSupplier = Contact::factory()->create(['is_supplier' => true, 'is_active' => false]);
        $customerOnly = Contact::factory()->create(['is_supplier' => false, 'is_customer' => true, 'is_active' => true]);

        $nonExpenseAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Payable->value))
            ->firstOrFail();

        $inactiveExpenseAccount = Account::query()->create([
            'account_type_id' => $this->expenseAccount->account_type_id,
            'code' => '599999',
            'name' => 'Inactive Expense Account',
            'is_active' => false,
        ]);

        $baseItem = [
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->category->id,
            'amount' => 50,
        ];

        // Non-existent journal
        $this->postJson('/api/expenses', [
            'journal_id' => 999999,
            'expense_date' => '2026-11-10',
            'items' => [$baseItem],
        ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);

        // Inactive journal
        $this->postJson('/api/expenses', [
            'journal_id' => $inactiveJournal->id,
            'expense_date' => '2026-11-10',
            'items' => [$baseItem],
        ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);

        // Wrong journal types (Purchase, Cash)
        foreach ([$purchaseJournal->id, $cashJournal->id] as $wrongJournalId) {
            $this->postJson('/api/expenses', [
                'journal_id' => $wrongJournalId,
                'expense_date' => '2026-11-10',
                'items' => [$baseItem],
            ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);
        }

        // Inactive supplier & non-supplier contact
        foreach ([$inactiveSupplier->id, $customerOnly->id] as $invalidContactId) {
            $this->postJson('/api/expenses', [
                'contact_id' => $invalidContactId,
                'journal_id' => $this->expenseJournal->id,
                'expense_date' => '2026-11-10',
                'items' => [$baseItem],
            ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
        }

        // Non-existent account, inactive account, wrong account type
        foreach ([999999, $inactiveExpenseAccount->id, $nonExpenseAccount->id] as $badAccountId) {
            $this->postJson('/api/expenses', [
                'journal_id' => $this->expenseJournal->id,
                'expense_date' => '2026-11-10',
                'items' => [[
                    'account_id' => $badAccountId,
                    'expense_category_id' => $this->category->id,
                    'amount' => 50,
                ]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.account_id']);
        }

        // Non-existent category
        $this->postJson('/api/expenses', [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'items' => [[
                'account_id' => $this->expenseAccount->id,
                'expense_category_id' => 999999,
                'amount' => 50,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.expense_category_id']);

        // Zero items, zero amount, negative amount
        $this->postJson('/api/expenses', [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'items' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items']);

        foreach ([0, -15] as $badAmount) {
            $this->postJson('/api/expenses', [
                'journal_id' => $this->expenseJournal->id,
                'expense_date' => '2026-11-10',
                'items' => [[
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'amount' => $badAmount,
                ]],
            ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.amount']);
        }
    }

    public function test_can_edit_draft_expense_add_modify_remove_items_and_preserve_ids_and_number(): void
    {
        Sanctum::actingAs($this->user);

        $supplier1 = Contact::factory()->create(['is_supplier' => true, 'is_active' => true]);
        $supplier2 = Contact::factory()->create(['is_supplier' => true, 'is_active' => true]);
        $secondJournal = Journal::query()->create([
            'code' => 'EXP-ALT',
            'name' => 'Second Expense Journal',
            'type' => JournalType::Expense,
            'is_active' => true,
        ]);

        $createRes = $this->postJson('/api/expenses', [
            'contact_id' => $supplier1->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'notes' => 'Initial note',
            'items' => [
                [
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'reference_number' => 'REF-1',
                    'description' => 'Item 1',
                    'amount' => 100,
                ],
                [
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'description' => 'Item 2',
                    'amount' => 50,
                ],
            ],
        ])->assertCreated();

        $expenseId = $createRes->json('data.id');
        $originalNumber = $createRes->json('data.expense_number');
        $item1Id = $createRes->json('data.items.0.id');
        $item2Id = $createRes->json('data.items.1.id');

        $updateRes = $this->putJson("/api/expenses/{$expenseId}", [
            'expense_number' => 'TAMPERED-001',
            'contact_id' => $supplier2->id,
            'journal_id' => $secondJournal->id,
            'expense_date' => '2026-11-12',
            'notes' => 'Updated note',
            'items' => [
                [
                    'id' => $item1Id,
                    'account_id' => $this->secondExpenseAccount->id,
                    'expense_category_id' => $this->secondCategory->id,
                    'reference_number' => 'REF-1-UPD',
                    'description' => 'Item 1 Updated',
                    'amount' => 125.75,
                ],
                [
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'description' => 'Item 3 New',
                    'amount' => 24.25,
                ],
            ],
        ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.expense_number', $originalNumber)
            ->assertJsonPath('data.contact_id', $supplier2->id)
            ->assertJsonPath('data.journal_id', $secondJournal->id)
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.total', '150.00')
            ->assertJsonPath('data.items.0.id', $item1Id)
            ->assertJsonPath('data.items.0.account_id', $this->secondExpenseAccount->id)
            ->assertJsonPath('data.items.0.expense_category_id', $this->secondCategory->id)
            ->assertJsonPath('data.items.0.reference_number', 'REF-1-UPD')
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseMissing('expense_items', ['id' => $item2Id]);
    }

    public function test_rejects_foreign_or_duplicate_item_ids_on_edit(): void
    {
        Sanctum::actingAs($this->user);

        $expenseA = Expense::factory()->create([
            'journal_id' => $this->expenseJournal->id,
            'created_by' => $this->user->id,
        ]);
        $itemA = ExpenseItem::factory()->create([
            'expense_id' => $expenseA->id,
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->category->id,
            'amount' => 50,
        ]);

        $expenseB = Expense::factory()->create([
            'journal_id' => $this->expenseJournal->id,
            'created_by' => $this->user->id,
        ]);
        $itemB = ExpenseItem::factory()->create([
            'expense_id' => $expenseB->id,
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->category->id,
            'amount' => 90,
        ]);

        // Foreign item ID
        $this->putJson("/api/expenses/{$expenseA->id}", [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-12',
            'items' => [[
                'id' => $itemB->id,
                'account_id' => $this->expenseAccount->id,
                'expense_category_id' => $this->category->id,
                'amount' => 90,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.id']);

        // Duplicate item ID
        $this->putJson("/api/expenses/{$expenseA->id}", [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-12',
            'items' => [
                [
                    'id' => $itemA->id,
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'amount' => 50,
                ],
                [
                    'id' => $itemA->id,
                    'account_id' => $this->expenseAccount->id,
                    'expense_category_id' => $this->category->id,
                    'amount' => 25,
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.1.id']);
    }

    public function test_can_delete_draft_expense_and_blocks_edit_or_delete_of_posted_expense(): void
    {
        Sanctum::actingAs($this->user);

        $draft = Expense::factory()->create([
            'journal_id' => $this->expenseJournal->id,
            'status' => ExpenseStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);
        ExpenseItem::factory()->create([
            'expense_id' => $draft->id,
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->category->id,
            'amount' => 60,
        ]);

        $this->deleteJson("/api/expenses/{$draft->id}")->assertOk();
        $this->assertDatabaseMissing('expenses', ['id' => $draft->id]);
        $this->assertDatabaseMissing('expense_items', ['expense_id' => $draft->id]);

        $posted = Expense::factory()->create([
            'journal_id' => $this->expenseJournal->id,
            'status' => ExpenseStatus::POSTED,
            'subtotal' => 250,
            'total' => 250,
            'created_by' => $this->user->id,
        ]);
        $postedItem = ExpenseItem::factory()->create([
            'expense_id' => $posted->id,
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->category->id,
            'amount' => 250,
        ]);

        $this->putJson("/api/expenses/{$posted->id}", [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-12',
            'items' => [[
                'id' => $postedItem->id,
                'account_id' => $this->expenseAccount->id,
                'expense_category_id' => $this->category->id,
                'amount' => 999,
            ]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->deleteJson("/api/expenses/{$posted->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
        $this->assertDatabaseHas('expenses', ['id' => $posted->id, 'total' => 250]);
    }

    public function test_can_filter_sort_and_paginate_expenses_by_item_category_and_search(): void
    {
        Sanctum::actingAs($this->user);
        $supplier = Contact::factory()->create(['name' => 'Acme Co', 'is_supplier' => true, 'is_active' => true]);

        $exp1 = Expense::factory()->create([
            'expense_number' => 'EXP-20261101-0001',
            'contact_id' => $supplier->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-01',
            'subtotal' => 100,
            'total' => 100,
            'status' => ExpenseStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);
        ExpenseItem::factory()->create([
            'expense_id' => $exp1->id,
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->category->id,
            'reference_number' => 'REF-ALPHA',
            'description' => 'Office power bill',
            'amount' => 100,
        ]);

        $exp2 = Expense::factory()->create([
            'expense_number' => 'EXP-20261115-0002',
            'contact_id' => null,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'subtotal' => 500,
            'total' => 500,
            'status' => ExpenseStatus::POSTED,
            'created_by' => $this->user->id,
        ]);
        ExpenseItem::factory()->create([
            'expense_id' => $exp2->id,
            'account_id' => $this->expenseAccount->id,
            'expense_category_id' => $this->secondCategory->id,
            'reference_number' => 'REF-BETA',
            'description' => 'Airfare',
            'amount' => 500,
        ]);

        $this->getJson('/api/expenses?status=posted')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.expense_number', 'EXP-20261115-0002');

        // Search by item reference_number
        $this->getJson('/api/expenses?search=REF-ALPHA')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.expense_number', 'EXP-20261101-0001');

        // Filter by item category + contact + date range
        $this->getJson("/api/expenses?expense_category_id={$this->category->id}&contact_id={$supplier->id}&expense_date_from=2026-11-01&expense_date_to=2026-11-05")
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
                'journal_id' => $this->expenseJournal->id,
                'expense_date' => '2026-11-10',
                'items' => [
                    [
                        'account_id' => $this->expenseAccount->id,
                        'expense_category_id' => 999999,
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
            'journal_id' => $this->expenseJournal->id,
            'created_by' => $this->user->id,
        ]);

        $this->getJson('/api/expenses')->assertForbidden();
        $this->getJson("/api/expenses/{$expense->id}")->assertForbidden();
        $this->postJson('/api/expenses', [])->assertForbidden();
        $this->putJson("/api/expenses/{$expense->id}", [])->assertForbidden();
        $this->deleteJson("/api/expenses/{$expense->id}")->assertForbidden();
    }
}
