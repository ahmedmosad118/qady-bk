<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table): void {
                if (! Schema::hasColumn('expenses', 'journal_id')) {
                    $table->foreignId('journal_id')
                        ->nullable()
                        ->after('contact_id')
                        ->constrained('journals')
                        ->restrictOnDelete();

                    $table->index('journal_id', 'expenses_journal_id_index');
                }

                if (! Schema::hasColumn('expenses', 'posted_by')) {
                    $table->foreignId('posted_by')
                        ->nullable()
                        ->after('created_by')
                        ->constrained('users')
                        ->restrictOnDelete();
                }

                if (! Schema::hasColumn('expenses', 'posted_at')) {
                    $table->timestamp('posted_at')
                        ->nullable()
                        ->after('posted_by');
                }
            });
        }

        if (Schema::hasTable('expense_items')) {
            Schema::table('expense_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('expense_items', 'account_id')) {
                    $table->foreignId('account_id')
                        ->nullable()
                        ->after('expense_id')
                        ->constrained('accounts')
                        ->restrictOnDelete();

                    $table->index('account_id', 'expense_items_account_id_index');
                }

                if (! Schema::hasColumn('expense_items', 'expense_category_id')) {
                    $table->foreignId('expense_category_id')
                        ->nullable()
                        ->after('account_id')
                        ->constrained('expense_categories')
                        ->restrictOnDelete();

                    $table->index('expense_category_id', 'expense_items_expense_category_id_index');
                }

                if (! Schema::hasColumn('expense_items', 'reference_number')) {
                    $table->string('reference_number', 100)
                        ->nullable()
                        ->after('expense_category_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->guardAgainstDataLoss('expenses', ['journal_id', 'posted_by', 'posted_at']);
        $this->guardAgainstDataLoss('expense_items', ['account_id', 'expense_category_id', 'reference_number']);

        Schema::withoutForeignKeyConstraints(function (): void {
            $this->dropColumnsSafely('expense_items', ['account_id', 'expense_category_id', 'reference_number']);
            $this->dropColumnsSafely('expenses', ['journal_id', 'posted_by', 'posted_at']);
        });
    }

    /**
     * Prevent silent data loss if any of the newly added columns already contain non-null values.
     *
     * @param  list<string>  $columns
     */
    private function guardAgainstDataLoss(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ($columns as $column) {
            if (Schema::hasColumn($tableName, $column) && DB::table($tableName)->whereNotNull($column)->exists()) {
                throw new RuntimeException(
                    "Cannot rollback migration: {$tableName}.{$column} already contains data."
                );
            }
        }
    }

    /**
     * Drop foreign keys, single-column indexes, and columns safely across MySQL and SQLite.
     *
     * @param  list<string>  $columns
     */
    private function dropColumnsSafely(string $tableName, array $columns): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $existingColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($tableName, $column)
        ));

        if ($existingColumns === []) {
            return;
        }

        $foreignKeys = Schema::getForeignKeys($tableName);
        foreach ($foreignKeys as $fk) {
            if (array_intersect($existingColumns, $fk['columns']) !== []) {
                Schema::table($tableName, static function (Blueprint $table) use ($fk): void {
                    $table->dropForeign($fk['columns']);
                });
            }
        }

        $indexes = Schema::getIndexes($tableName);
        foreach ($indexes as $index) {
            if ($index['primary']) {
                continue;
            }

            if (count($index['columns']) === 1 && in_array($index['columns'][0], $existingColumns, true)) {
                Schema::table($tableName, static function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index['name']);
                });
            }
        }

        Schema::table($tableName, static function (Blueprint $table) use ($existingColumns): void {
            $table->dropColumn($existingColumns);
        });
    }
};
