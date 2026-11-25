<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_invoices') || Schema::hasColumn('purchase_invoices', 'journal_id')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->foreignId('journal_id')
                ->nullable()
                ->after('purchase_order_id')
                ->constrained('journals')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_invoices') || ! Schema::hasColumn('purchase_invoices', 'journal_id')) {
            return;
        }

        $foreignKeys = Schema::getForeignKeys('purchase_invoices');
        foreach ($foreignKeys as $fk) {
            if (in_array('journal_id', $fk['columns'], true)) {
                Schema::table('purchase_invoices', function (Blueprint $table) use ($fk): void {
                    $table->dropForeign($fk['name']);
                });
            }
        }

        $indexes = Schema::getIndexes('purchase_invoices');
        foreach ($indexes as $index) {
            if ($index['primary']) {
                continue;
            }
            if ($index['columns'] === ['journal_id']) {
                Schema::table('purchase_invoices', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index['name']);
                });
            }
        }

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->dropColumn('journal_id');
        });
    }
};
