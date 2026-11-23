<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_methods')) {
            if (! Schema::hasColumn('payment_methods', 'account_id')) {
                Schema::table('payment_methods', function (Blueprint $table): void {
                    $table->foreignId('account_id')
                        ->nullable()
                        ->after('type')
                        ->constrained('accounts')
                        ->restrictOnDelete();
                });
            }

            $this->dropLegacyColumnSafely('payment_methods', 'cashbox_id');
            $this->dropLegacyColumnSafely('payment_methods', 'bank_account_id');
            $this->dropLegacyColumnSafely('payment_methods', 'chart_of_account_id');
        }

        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table): void {
                if (! Schema::hasColumn('contacts', 'receivable_account_id')) {
                    $table->foreignId('receivable_account_id')
                        ->nullable()
                        ->after('is_supplier')
                        ->constrained('accounts')
                        ->restrictOnDelete();
                }

                if (! Schema::hasColumn('contacts', 'payable_account_id')) {
                    $table->foreignId('payable_account_id')
                        ->nullable()
                        ->after('receivable_account_id')
                        ->constrained('accounts')
                        ->restrictOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contacts')) {
            $this->dropLegacyColumnSafely('contacts', 'payable_account_id');
            $this->dropLegacyColumnSafely('contacts', 'receivable_account_id');
        }

        if (Schema::hasTable('payment_methods')) {
            $this->dropLegacyColumnSafely('payment_methods', 'account_id');
        }
    }

    private function dropLegacyColumnSafely(string $tableName, string $columnName): void
    {
        if (! Schema::hasColumn($tableName, $columnName)) {
            return;
        }

        $foreignKeys = Schema::getForeignKeys($tableName);
        foreach ($foreignKeys as $fk) {
            if (in_array($columnName, $fk['columns'], true)) {
                Schema::table($tableName, function (Blueprint $table) use ($fk): void {
                    $table->dropForeign($fk['name']);
                });
            }
        }

        $indexes = Schema::getIndexes($tableName);
        foreach ($indexes as $index) {
            if ($index['primary']) {
                continue;
            }
            if ($index['columns'] === [$columnName]) {
                Schema::table($tableName, function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index['name']);
                });
            }
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName): void {
            $table->dropColumn($columnName);
        });
    }
};
