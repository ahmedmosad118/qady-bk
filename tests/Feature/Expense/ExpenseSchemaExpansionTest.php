<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\Journal;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class ExpenseSchemaExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_table_has_expanded_accounting_columns_indexes_foreign_keys_and_legacy_column(): void
    {
        $this->assertTrue(Schema::hasColumns('expenses', [
            'journal_id',
            'posted_by',
            'posted_at',
            'expense_category_id',
        ]));

        $foreignKeys = Schema::getForeignKeys('expenses');
        $fkColumns = collect($foreignKeys)->flatMap(fn (array $fk): array => $fk['columns'])->all();
        $this->assertContains('journal_id', $fkColumns);
        $this->assertContains('posted_by', $fkColumns);
        $this->assertContains('expense_category_id', $fkColumns);

        $journalFk = collect($foreignKeys)->first(fn (array $fk): bool => $fk['columns'] === ['journal_id']);
        $this->assertNotNull($journalFk);
        $this->assertSame('journals', $journalFk['foreign_table']);

        $postedByFk = collect($foreignKeys)->first(fn (array $fk): bool => $fk['columns'] === ['posted_by']);
        $this->assertNotNull($postedByFk);
        $this->assertSame('users', $postedByFk['foreign_table']);

        $indexes = Schema::getIndexes('expenses');
        $hasJournalIndex = collect($indexes)->contains(
            fn (array $idx): bool => in_array('journal_id', $idx['columns'], true)
        );
        $this->assertTrue($hasJournalIndex);

        $expense = Expense::factory()->create([
            'journal_id' => null,
            'posted_by' => null,
            'posted_at' => null,
        ]);

        $row = DB::table('expenses')->where('id', $expense->id)->first();
        $this->assertNull($row->journal_id);
        $this->assertNull($row->posted_by);
        $this->assertNull($row->posted_at);
        $this->assertNotNull($row->expense_category_id);
    }

    public function test_expense_items_table_has_expanded_accounting_columns_indexes_foreign_keys_and_legacy_column(): void
    {
        $this->assertTrue(Schema::hasColumns('expense_items', [
            'account_id',
            'expense_category_id',
            'reference_number',
            'name',
        ]));

        $foreignKeys = Schema::getForeignKeys('expense_items');
        $fkColumns = collect($foreignKeys)->flatMap(fn (array $fk): array => $fk['columns'])->all();
        $this->assertContains('account_id', $fkColumns);
        $this->assertContains('expense_category_id', $fkColumns);

        $accountFk = collect($foreignKeys)->first(fn (array $fk): bool => $fk['columns'] === ['account_id']);
        $this->assertNotNull($accountFk);
        $this->assertSame('accounts', $accountFk['foreign_table']);

        $categoryFk = collect($foreignKeys)->first(fn (array $fk): bool => $fk['columns'] === ['expense_category_id']);
        $this->assertNotNull($categoryFk);
        $this->assertSame('expense_categories', $categoryFk['foreign_table']);

        $indexes = Schema::getIndexes('expense_items');
        $this->assertTrue(collect($indexes)->contains(
            fn (array $idx): bool => in_array('account_id', $idx['columns'], true)
        ));
        $this->assertTrue(collect($indexes)->contains(
            fn (array $idx): bool => in_array('expense_category_id', $idx['columns'], true)
        ));

        $item = ExpenseItem::factory()->create([
            'name' => 'Legacy Item Name',
        ]);

        $row = DB::table('expense_items')->where('id', $item->id)->first();
        $this->assertNull($row->account_id);
        $this->assertNull($row->expense_category_id);
        $this->assertNull($row->reference_number);
        $this->assertSame('Legacy Item Name', $row->name);
    }

    public function test_new_foreign_keys_reject_invalid_references_and_restrict_delete(): void
    {
        $this->seed(AccountingSeeder::class);

        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->create();
        $journal = Journal::query()->firstOrFail();

        $expense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'created_by' => $user->id,
        ]);

        $this->expectException(QueryException::class);
        DB::table('expenses')->where('id', $expense->id)->update(['journal_id' => 999999]);
        $this->fail('Expected foreign key constraint failure for invalid journal_id.');
    }

    public function test_migration_rollback_succeeds_when_new_columns_are_empty_and_preserves_legacy_columns(): void
    {
        $category = ExpenseCategory::factory()->create();
        $expense = Expense::factory()->create(['expense_category_id' => $category->id]);
        $item = ExpenseItem::factory()->create([
            'expense_id' => $expense->id,
            'name' => 'Preserved Legacy Name',
        ]);

        $migration = require database_path('migrations/2026_11_26_003023_expand_expense_accounting_schema.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('expenses', 'journal_id'));
        $this->assertFalse(Schema::hasColumn('expenses', 'posted_by'));
        $this->assertFalse(Schema::hasColumn('expenses', 'posted_at'));
        $this->assertTrue(Schema::hasColumn('expenses', 'expense_category_id'));

        $this->assertFalse(Schema::hasColumn('expense_items', 'account_id'));
        $this->assertFalse(Schema::hasColumn('expense_items', 'expense_category_id'));
        $this->assertFalse(Schema::hasColumn('expense_items', 'reference_number'));
        $this->assertTrue(Schema::hasColumn('expense_items', 'name'));

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'expense_category_id' => $category->id,
        ]);

        // Re-apply up() to leave schema clean for RefreshDatabase teardown.
        $migration->up();

        $this->assertTrue(Schema::hasColumns('expenses', ['journal_id', 'posted_by', 'posted_at']));
        $this->assertTrue(Schema::hasColumns('expense_items', ['account_id', 'expense_category_id', 'reference_number']));
    }

    public function test_migration_rollback_blocks_when_new_columns_contain_data(): void
    {
        $this->seed(AccountingSeeder::class);

        $journal = Journal::query()->firstOrFail();
        $expense = Expense::factory()->create();
        DB::table('expenses')->where('id', $expense->id)->update(['journal_id' => $journal->id]);

        $migration = require database_path('migrations/2026_11_26_003023_expand_expense_accounting_schema.php');

        try {
            $migration->down();
            $this->fail('Expected RuntimeException when rolling back with populated journal_id.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('expenses.journal_id', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('expenses', 'journal_id'));
        $this->assertTrue(Schema::hasColumn('expense_items', 'account_id'));
    }
}
