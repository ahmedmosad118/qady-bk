<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_categories') && ! Schema::hasColumn('product_categories', 'stock_input_account_id')) {
            Schema::table('product_categories', function (Blueprint $table): void {
                $table->foreignId('stock_input_account_id')
                    ->nullable()
                    ->after('parent_id')
                    ->constrained('accounts')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_categories') || ! Schema::hasColumn('product_categories', 'stock_input_account_id')) {
            return;
        }

        $foreignKeys = Schema::getForeignKeys('product_categories');
        foreach ($foreignKeys as $fk) {
            if (in_array('stock_input_account_id', $fk['columns'], true)) {
                Schema::table('product_categories', function (Blueprint $table) use ($fk): void {
                    $table->dropForeign($fk['name']);
                });
            }
        }

        $indexes = Schema::getIndexes('product_categories');
        foreach ($indexes as $index) {
            if ($index['primary']) {
                continue;
            }
            if ($index['columns'] === ['stock_input_account_id']) {
                Schema::table('product_categories', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index['name']);
                });
            }
        }

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropColumn('stock_input_account_id');
        });
    }
};
