<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExpenseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_expenses_table_matches_target_schema_and_has_no_legacy_category_column(): void
    {
        $this->assertTrue(Schema::hasColumns('expenses', [
            'id',
            'expense_number',
            'contact_id',
            'journal_id',
            'expense_date',
            'subtotal',
            'total',
            'status',
            'notes',
            'created_by',
            'posted_by',
            'posted_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertFalse(Schema::hasColumn('expenses', 'expense_category_id'));

        $foreignKeys = Schema::getForeignKeys('expenses');
        $fkColumns = collect($foreignKeys)->flatMap(fn (array $fk): array => $fk['columns'])->all();
        $this->assertContains('contact_id', $fkColumns);
        $this->assertContains('journal_id', $fkColumns);
        $this->assertContains('created_by', $fkColumns);
        $this->assertContains('posted_by', $fkColumns);

        $indexes = Schema::getIndexes('expenses');
        foreach (['expense_date', 'status', 'contact_id', 'journal_id', 'created_by', 'posted_by'] as $col) {
            $this->assertTrue(
                collect($indexes)->contains(fn (array $idx): bool => in_array($col, $idx['columns'], true)),
                "Missing index for expenses.{$col}"
            );
        }

        $user = User::factory()->create();

        $id = DB::table('expenses')->insertGetId([
            'expense_number' => 'EXP-20261126-0001',
            'contact_id' => null,
            'journal_id' => null,
            'expense_date' => '2026-11-26',
            'subtotal' => 100.00,
            'total' => 100.00,
            'status' => 'cancelled',
            'notes' => null,
            'created_by' => $user->id,
            'posted_by' => null,
            'posted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('expenses', [
            'id' => $id,
            'status' => 'cancelled',
        ]);
    }

    public function test_expense_items_table_matches_target_schema_and_has_no_legacy_name_column(): void
    {
        $this->assertTrue(Schema::hasColumns('expense_items', [
            'id',
            'expense_id',
            'account_id',
            'expense_category_id',
            'reference_number',
            'description',
            'amount',
            'created_at',
            'updated_at',
        ]));

        $this->assertFalse(Schema::hasColumn('expense_items', 'name'));

        $foreignKeys = Schema::getForeignKeys('expense_items');
        $fkColumns = collect($foreignKeys)->flatMap(fn (array $fk): array => $fk['columns'])->all();
        $this->assertContains('expense_id', $fkColumns);
        $this->assertContains('account_id', $fkColumns);
        $this->assertContains('expense_category_id', $fkColumns);

        $indexes = Schema::getIndexes('expense_items');
        foreach (['expense_id', 'account_id', 'expense_category_id'] as $col) {
            $this->assertTrue(
                collect($indexes)->contains(fn (array $idx): bool => in_array($col, $idx['columns'], true)),
                "Missing index for expense_items.{$col}"
            );
        }

        $user = User::factory()->create();
        $category = ExpenseCategory::factory()->create();

        $expenseId = DB::table('expenses')->insertGetId([
            'expense_number' => 'EXP-20261126-0002',
            'expense_date' => '2026-11-26',
            'subtotal' => 250.00,
            'total' => 250.00,
            'status' => 'draft',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('expense_items')->insertGetId([
            'expense_id' => $expenseId,
            'account_id' => null,
            'expense_category_id' => $category->id,
            'reference_number' => 'REF-1001',
            'description' => 'Electricity bill',
            'amount' => 250.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('expense_items', [
            'id' => $itemId,
            'expense_category_id' => $category->id,
            'reference_number' => 'REF-1001',
        ]);
    }

    public function test_expense_items_foreign_keys_reject_invalid_account_or_category(): void
    {
        $user = User::factory()->create();

        $expenseId = DB::table('expenses')->insertGetId([
            'expense_number' => 'EXP-20261126-0003',
            'expense_date' => '2026-11-26',
            'subtotal' => 50.00,
            'total' => 50.00,
            'status' => 'draft',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('expense_items')->insert([
            'expense_id' => $expenseId,
            'account_id' => 999999,
            'expense_category_id' => 999999,
            'amount' => 50.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
