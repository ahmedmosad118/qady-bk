<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        $contactAccountIds = [];

        if (Schema::hasColumn('contacts', 'receivable_account_id') || Schema::hasColumn('contacts', 'payable_account_id')) {
            $rows = DB::table('contacts')->get(['receivable_account_id', 'payable_account_id']);
            foreach ($rows as $row) {
                if (! empty($row->receivable_account_id)) {
                    $contactAccountIds[(int) $row->receivable_account_id] = true;
                }
                if (! empty($row->payable_account_id)) {
                    $contactAccountIds[(int) $row->payable_account_id] = true;
                }
            }

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

        if ($contactAccountIds !== [] && Schema::hasTable('chart_of_accounts')) {
            $protectedIds = $this->getProtectedAccountIds();
            $deletableIds = array_values(array_diff(array_keys($contactAccountIds), array_keys($protectedIds)));

            if ($deletableIds !== []) {
                DB::table('chart_of_accounts')->whereIn('id', $deletableIds)->delete();
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

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
     * @return array<int, true>
     */
    private function getProtectedAccountIds(): array
    {
        $protected = [];

        if (Schema::hasTable('settings')) {
            $setting = DB::table('settings')->where('type', 'accounting')->first();
            if ($setting && is_string($setting->data)) {
                $decoded = json_decode($setting->data, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $val) {
                        if (is_numeric($val) && (int) $val > 0) {
                            $protected[(int) $val] = true;
                        }
                    }
                }
            }
        }

        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'chart_of_account_id')) {
            $pmIds = DB::table('payment_methods')
                ->whereNotNull('chart_of_account_id')
                ->pluck('chart_of_account_id');

            foreach ($pmIds as $id) {
                $protected[(int) $id] = true;
            }
        }

        return $protected;
    }
};
