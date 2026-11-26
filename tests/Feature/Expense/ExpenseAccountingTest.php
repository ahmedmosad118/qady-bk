<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\JournalType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fy;

    private AccountingPeriod $period;

    private Journal $expenseJournal;

    private Account $expenseAccount1;

    private Account $expenseAccount2;

    private Account $defaultPayableAccount;

    private ExpenseCategory $category1;

    private ExpenseCategory $category2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingSeeder::class);
        $this->user = $this->createSuperAdmin();

        $this->fy = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);

        $this->period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $this->fy->id,
            'period_number' => 11,
            'name' => 'Nov 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $this->expenseJournal = Journal::query()->where('code', 'EXP')->firstOrFail();

        $expenseAccounts = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Expenses->value))
            ->orderBy('id')
            ->get();

        $this->expenseAccount1 = $expenseAccounts->get(0);
        $this->expenseAccount2 = $expenseAccounts->get(1) ?? $this->expenseAccount1;

        $this->defaultPayableAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Payable->value))
            ->firstOrFail();

        Setting::query()->create([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [
                'accounts_payable' => $this->defaultPayableAccount->id,
            ],
        ]);

        $this->category1 = ExpenseCategory::factory()->create(['name' => 'Utilities']);
        $this->category2 = ExpenseCategory::factory()->create(['name' => 'Maintenance']);
    }

    public function test_creating_expense_creates_balanced_draft_journal_entry_with_item_debit_lines_and_default_payable(): void
    {
        Sanctum::actingAs($this->user);

        $res = $this->postJson('/api/expenses', [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'items' => [
                [
                    'account_id' => $this->expenseAccount1->id,
                    'expense_category_id' => $this->category1->id,
                    'description' => 'Electricity bill',
                    'amount' => '120.50',
                ],
                [
                    'account_id' => $this->expenseAccount2->id,
                    'expense_category_id' => $this->category2->id,
                    'description' => null,
                    'amount' => '79.50',
                ],
            ],
        ])->assertCreated();

        $expenseId = $res->json('data.id');
        $expenseNumber = $res->json('data.expense_number');

        /** @var JournalEntry|null $entry */
        $entry = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expenseId)
            ->with('lines')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame($this->expenseJournal->id, $entry->journal_id);
        $this->assertSame($this->fy->id, $entry->fiscal_year_id);
        $this->assertSame($this->period->id, $entry->accounting_period_id);
        $this->assertNull($entry->contact_id);
        $this->assertSame('2026-11-15', $entry->entry_date->toDateString());
        $this->assertSame($expenseNumber, $entry->reference);
        $this->assertSame(__('accounting.labels.expense_entry_description', ['number' => $expenseNumber]), $entry->description);

        $lines = $entry->lines->sortBy('line_number')->values();
        $this->assertCount(3, $lines);

        // Debit lines
        $this->assertSame($this->expenseAccount1->id, $lines[0]->account_id);
        $this->assertSame('120.50', $lines[0]->debit);
        $this->assertSame('0.00', $lines[0]->credit);
        $this->assertSame('Electricity bill', $lines[0]->label);

        $this->assertSame($this->expenseAccount2->id, $lines[1]->account_id);
        $this->assertSame('79.50', $lines[1]->debit);
        $this->assertSame('0.00', $lines[1]->credit);
        $this->assertSame('Maintenance', $lines[1]->label);

        // Credit line using default accounts_payable
        $this->assertSame($this->defaultPayableAccount->id, $lines[2]->account_id);
        $this->assertSame('0.00', $lines[2]->debit);
        $this->assertSame('200.00', $lines[2]->credit);
        $this->assertSame($expenseNumber, $lines[2]->label);
    }

    public function test_uses_supplier_payable_account_when_valid_and_falls_back_when_null(): void
    {
        Sanctum::actingAs($this->user);

        $payableTypeId = $this->defaultPayableAccount->account_type_id;
        $customPayable = Account::query()->create([
            'account_type_id' => $payableTypeId,
            'code' => '211999',
            'name' => 'Custom Supplier Payable',
            'is_active' => true,
        ]);

        $supplierWithAccount = Contact::factory()->create([
            'name' => 'Supplier A',
            'is_supplier' => true,
            'is_active' => true,
            'payable_account_id' => $customPayable->id,
        ]);

        $res1 = $this->postJson('/api/expenses', [
            'contact_id' => $supplierWithAccount->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'amount' => 100,
            ]],
        ])->assertCreated();

        $entry1 = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $res1->json('data.id'))
            ->with('lines')
            ->firstOrFail();

        $this->assertSame($supplierWithAccount->id, $entry1->contact_id);
        $creditLine1 = $entry1->lines->firstWhere('credit', '100.00');
        $this->assertSame($customPayable->id, $creditLine1->account_id);
        $this->assertSame('Supplier A', $creditLine1->label);

        // Supplier without payable_account_id falls back to default accounts_payable
        $supplierWithoutAccount = Contact::factory()->create([
            'name' => 'Supplier B',
            'is_supplier' => true,
            'is_active' => true,
            'payable_account_id' => null,
        ]);

        $res2 = $this->postJson('/api/expenses', [
            'contact_id' => $supplierWithoutAccount->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'amount' => 50,
            ]],
        ])->assertCreated();

        $entry2 = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $res2->json('data.id'))
            ->with('lines')
            ->firstOrFail();

        $creditLine2 = $entry2->lines->firstWhere('credit', '50.00');
        $this->assertSame($this->defaultPayableAccount->id, $creditLine2->account_id);
    }

    public function test_rejects_invalid_supplier_payable_account_without_falling_back_and_rolls_back(): void
    {
        Sanctum::actingAs($this->user);

        // Inactive payable account on supplier
        $inactivePayable = Account::query()->create([
            'account_type_id' => $this->defaultPayableAccount->account_type_id,
            'code' => '211998',
            'name' => 'Inactive Payable',
            'is_active' => false,
        ]);

        $supplierInactiveAcc = Contact::factory()->create([
            'is_supplier' => true,
            'is_active' => true,
            'payable_account_id' => $inactivePayable->id,
        ]);

        $this->postJson('/api/expenses', [
            'contact_id' => $supplierInactiveAcc->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'amount' => 100,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('expense_items', 0);
        $this->assertDatabaseCount('journal_entries', 0);

        // Wrong account type on supplier (Expenses instead of Payable)
        $supplierWrongType = Contact::factory()->create([
            'is_supplier' => true,
            'is_active' => true,
            'payable_account_id' => $this->expenseAccount1->id,
        ]);

        $this->postJson('/api/expenses', [
            'contact_id' => $supplierWrongType->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'amount' => 100,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_rejects_expense_when_no_valid_payable_account_is_configured(): void
    {
        Sanctum::actingAs($this->user);

        Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->delete();
        $this->defaultPayableAccount->update(['is_active' => false]);

        $this->postJson('/api/expenses', [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-15',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'amount' => 100,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_updating_expense_updates_same_draft_journal_entry_and_lines_without_creating_new_entry(): void
    {
        Sanctum::actingAs($this->user);

        $supplier = Contact::factory()->create([
            'name' => 'Vendor X',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $createRes = $this->postJson('/api/expenses', [
            'contact_id' => $supplier->id,
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'description' => 'Initial line',
                'amount' => 100,
            ]],
        ])->assertCreated();

        $expenseId = $createRes->json('data.id');
        $itemId = $createRes->json('data.items.0.id');

        $originalEntry = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expenseId)
            ->firstOrFail();

        $secondJournal = Journal::query()->create([
            'code' => 'EXP-2',
            'name' => 'Second Expense Journal',
            'type' => JournalType::Expense,
            'is_active' => true,
        ]);

        $this->putJson("/api/expenses/{$expenseId}", [
            'contact_id' => null,
            'journal_id' => $secondJournal->id,
            'expense_date' => '2026-11-20',
            'items' => [
                [
                    'id' => $itemId,
                    'account_id' => $this->expenseAccount2->id,
                    'expense_category_id' => $this->category2->id,
                    'description' => 'Updated line',
                    'amount' => 150,
                ],
                [
                    'account_id' => $this->expenseAccount1->id,
                    'expense_category_id' => $this->category1->id,
                    'description' => 'Added line',
                    'amount' => 50,
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'expense')->where('source_id', $expenseId)->count());

        $updatedEntry = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expenseId)
            ->with('lines')
            ->firstOrFail();

        $this->assertSame($originalEntry->id, $updatedEntry->id);
        $this->assertSame($originalEntry->entry_number, $updatedEntry->entry_number);
        $this->assertSame($secondJournal->id, $updatedEntry->journal_id);
        $this->assertNull($updatedEntry->contact_id);
        $this->assertSame('2026-11-20', $updatedEntry->entry_date->toDateString());

        $lines = $updatedEntry->lines->sortBy('line_number')->values();
        $this->assertCount(3, $lines);
        $this->assertSame('150.00', $lines[0]->debit);
        $this->assertSame('50.00', $lines[1]->debit);
        $this->assertSame('200.00', $lines[2]->credit);
    }

    public function test_journal_entry_failure_on_update_rolls_back_expense_and_items_completely(): void
    {
        Sanctum::actingAs($this->user);

        $createRes = $this->postJson('/api/expenses', [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-11-10',
            'items' => [[
                'account_id' => $this->expenseAccount1->id,
                'expense_category_id' => $this->category1->id,
                'description' => 'Original',
                'amount' => 100,
            ]],
        ])->assertCreated();

        $expenseId = $createRes->json('data.id');
        $itemId = $createRes->json('data.items.0.id');

        // Attempt update into a date with no open accounting period -> JE context fails -> full rollback
        $this->putJson("/api/expenses/{$expenseId}", [
            'journal_id' => $this->expenseJournal->id,
            'expense_date' => '2026-10-15',
            'items' => [[
                'id' => $itemId,
                'account_id' => $this->expenseAccount2->id,
                'expense_category_id' => $this->category2->id,
                'description' => 'Should rollback',
                'amount' => 999,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['accounting_period_id']);

        $expense = Expense::query()->with('items')->findOrFail($expenseId);
        $this->assertSame('2026-11-10', $expense->expense_date->toDateString());
        $this->assertSame('100.00', $expense->total);
        $this->assertSame('Original', $expense->items->first()->description);
    }
}
