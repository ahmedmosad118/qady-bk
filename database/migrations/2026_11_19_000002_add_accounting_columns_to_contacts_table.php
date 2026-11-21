<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('contacts', 'receivable_account_id')) {
                $table->foreignId('receivable_account_id')
                    ->nullable()
                    ->after('is_supplier')
                    ->constrained('chart_of_accounts')
                    ->restrictOnDelete();
                $table->unique('receivable_account_id', 'contacts_receivable_account_unique');
            }

            if (! Schema::hasColumn('contacts', 'payable_account_id')) {
                $table->foreignId('payable_account_id')
                    ->nullable()
                    ->after('receivable_account_id')
                    ->constrained('chart_of_accounts')
                    ->restrictOnDelete();
                $table->unique('payable_account_id', 'contacts_payable_account_unique');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            if (Schema::hasColumn('contacts', 'payable_account_id')) {
                $table->dropForeign(['payable_account_id']);
                $table->dropUnique('contacts_payable_account_unique');
                $table->dropColumn('payable_account_id');
            }

            if (Schema::hasColumn('contacts', 'receivable_account_id')) {
                $table->dropForeign(['receivable_account_id']);
                $table->dropUnique('contacts_receivable_account_unique');
                $table->dropColumn('receivable_account_id');
            }
        });
    }
};
