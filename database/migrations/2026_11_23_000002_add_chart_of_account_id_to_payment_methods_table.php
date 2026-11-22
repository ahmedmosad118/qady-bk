<?php

declare(strict_types=1);

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        if (! Schema::hasColumn('payment_methods', 'chart_of_account_id')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->foreignId('chart_of_account_id')
                    ->nullable()
                    ->after('type')
                    ->constrained('chart_of_accounts')
                    ->restrictOnDelete();
                $table->index('chart_of_account_id', 'idx_payment_methods_chart_of_account_id');
            });
        }

        if (! Schema::hasTable('chart_of_accounts') || ! Schema::hasColumn('chart_of_accounts', 'account_purpose')) {
            return;
        }

        $contactAccountIds = $this->getContactAccountIds();

        $cashAccountId = $this->findCompatibleAccountId(AccountPurpose::CASH, $contactAccountIds);
        if ($cashAccountId !== null) {
            DB::table('payment_methods')
                ->whereNull('chart_of_account_id')
                ->where('type', PaymentMethodType::CASH->value)
                ->update([
                    'chart_of_account_id' => $cashAccountId,
                    'updated_at' => now(),
                ]);
        }

        $bankAccountId = $this->findCompatibleAccountId(AccountPurpose::BANK, $contactAccountIds);
        if ($bankAccountId !== null) {
            DB::table('payment_methods')
                ->whereNull('chart_of_account_id')
                ->whereIn('type', [
                    PaymentMethodType::VISA->value,
                    PaymentMethodType::BANK_TRANSFER->value,
                ])
                ->update([
                    'chart_of_account_id' => $bankAccountId,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_methods') || ! Schema::hasColumn('payment_methods', 'chart_of_account_id')) {
            return;
        }

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropIndex('idx_payment_methods_chart_of_account_id');
            $table->dropConstrainedForeignId('chart_of_account_id');
        });
    }

    /**
     * @return array<int, true>
     */
    private function getContactAccountIds(): array
    {
        if (! Schema::hasTable('contacts')) {
            return [];
        }

        $set = [];
        $rows = DB::table('contacts')
            ->select(['receivable_account_id', 'payable_account_id'])
            ->where(function ($query): void {
                $query->whereNotNull('receivable_account_id')
                    ->orWhereNotNull('payable_account_id');
            })
            ->get();

        foreach ($rows as $row) {
            if ($row->receivable_account_id !== null) {
                $set[(int) $row->receivable_account_id] = true;
            }
            if ($row->payable_account_id !== null) {
                $set[(int) $row->payable_account_id] = true;
            }
        }

        return $set;
    }

    /**
     * @param  array<int, true>  $contactAccountIds
     */
    private function findCompatibleAccountId(AccountPurpose $purpose, array $contactAccountIds): ?int
    {
        $candidates = DB::table('chart_of_accounts')
            ->where('account_type', AccountType::ASSET->value)
            ->where('account_purpose', $purpose->value)
            ->orderBy('id')
            ->get(['id']);

        foreach ($candidates as $candidate) {
            if (! isset($contactAccountIds[(int) $candidate->id])) {
                return (int) $candidate->id;
            }
        }

        return null;
    }
};
