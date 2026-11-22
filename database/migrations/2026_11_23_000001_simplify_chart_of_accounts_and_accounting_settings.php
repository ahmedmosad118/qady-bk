<?php

declare(strict_types=1);

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy structural group and control accounts that held no direct transactions
     * and can be safely removed if unmodified and unreferenced.
     *
     * @var array<string, string>
     */
    private const LEGACY_STRUCTURAL_ACCOUNTS = [
        '1' => 'الأصول',
        '1.001' => 'الأصول المتداولة',
        '1.002' => 'الأصول غير المتداولة',
        '1.001.003' => 'العملاء (الذمم المدينة)',
        '2' => 'الالتزامات',
        '2.001' => 'الالتزامات المتداولة',
        '2.002' => 'الالتزامات طويلة الأجل',
        '2.001.001' => 'الموردون (الذمم الدائنة)',
        '3' => 'حقوق الملكية',
        '4' => 'الإيرادات',
        '5' => 'المصروفات',
    ];

    /**
     * Obsolete accounting settings keys replaced by Payment Methods and direct Contact accounts.
     *
     * @var array<int, string>
     */
    private const OBSOLETE_SETTING_KEYS = [
        'cash',
        'bank',
        'accounts_receivable',
        'accounts_payable',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        if (! Schema::hasColumn('chart_of_accounts', 'account_purpose')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                $table->enum('account_purpose', AccountPurpose::values())
                    ->default(AccountPurpose::GENERAL->value)
                    ->after('account_type');
                $table->index('account_purpose', 'idx_chart_of_accounts_purpose');
            });
        }

        $contactAccountIds = $this->getContactAccountIds();
        $settingRow = Schema::hasTable('settings')
            ? DB::table('settings')->where('type', 'accounting')->first()
            : null;

        $settingData = [];
        if ($settingRow && is_string($settingRow->data)) {
            $decoded = json_decode($settingRow->data, true);
            $settingData = is_array($decoded) ? $decoded : [];
        }

        $this->classifyLegacyPurposeAccounts($settingData, $contactAccountIds);

        if ($settingRow) {
            foreach (self::OBSOLETE_SETTING_KEYS as $obsoleteKey) {
                unset($settingData[$obsoleteKey]);
            }

            DB::table('settings')
                ->where('id', $settingRow->id)
                ->update([
                    'data' => json_encode($settingData, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasColumn('chart_of_accounts', 'parent_id')) {
            // Detach all parent-child links first so children remain independent accounts
            // and self-referential foreign keys do not block structural cleanup.
            DB::table('chart_of_accounts')->update(['parent_id' => null]);

            $this->removeUnreferencedLegacyStructuralAccounts($settingData, $contactAccountIds);

            Schema::withoutForeignKeyConstraints(static function (): void {
                Schema::table('chart_of_accounts', function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('parent_id');
                });
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        if (! Schema::hasColumn('chart_of_accounts', 'parent_id')) {
            Schema::withoutForeignKeyConstraints(static function (): void {
                Schema::table('chart_of_accounts', function (Blueprint $table): void {
                    $table->foreignId('parent_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('chart_of_accounts')
                        ->restrictOnDelete();
                });
            });
        }

        if (Schema::hasColumn('chart_of_accounts', 'account_purpose')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                $table->dropIndex('idx_chart_of_accounts_purpose');
                $table->dropColumn('account_purpose');
            });
        }
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
     * @param  array<string, mixed>  $settingData
     * @param  array<int, true>  $contactAccountIds
     */
    private function classifyLegacyPurposeAccounts(array $settingData, array $contactAccountIds): void
    {
        $cashAssigned = false;
        $legacyCashId = is_numeric($settingData['cash'] ?? null) ? (int) $settingData['cash'] : null;

        if ($legacyCashId !== null && $legacyCashId > 0 && ! isset($contactAccountIds[$legacyCashId])) {
            $updated = DB::table('chart_of_accounts')
                ->where('id', $legacyCashId)
                ->where('account_type', AccountType::ASSET->value)
                ->update(['account_purpose' => AccountPurpose::CASH->value]);

            if ($updated > 0) {
                $cashAssigned = true;
            }
        }

        if (! $cashAssigned) {
            $fallbackCash = DB::table('chart_of_accounts')
                ->where('account_type', AccountType::ASSET->value)
                ->where(function ($query): void {
                    $query->whereIn('code', ['1001', '1.001.001'])
                        ->orWhereIn('name', ['الصندوق (النقدية)', 'الصندوق']);
                })
                ->orderBy('id')
                ->get(['id']);

            foreach ($fallbackCash as $candidate) {
                if (! isset($contactAccountIds[(int) $candidate->id])) {
                    DB::table('chart_of_accounts')
                        ->where('id', $candidate->id)
                        ->update(['account_purpose' => AccountPurpose::CASH->value]);
                    break;
                }
            }
        }

        $bankAssigned = false;
        $legacyBankId = is_numeric($settingData['bank'] ?? null) ? (int) $settingData['bank'] : null;

        if ($legacyBankId !== null && $legacyBankId > 0 && ! isset($contactAccountIds[$legacyBankId])) {
            $updated = DB::table('chart_of_accounts')
                ->where('id', $legacyBankId)
                ->where('account_type', AccountType::ASSET->value)
                ->update(['account_purpose' => AccountPurpose::BANK->value]);

            if ($updated > 0) {
                $bankAssigned = true;
            }
        }

        if (! $bankAssigned) {
            $fallbackBank = DB::table('chart_of_accounts')
                ->where('account_type', AccountType::ASSET->value)
                ->where(function ($query): void {
                    $query->whereIn('code', ['1002', '1.001.002'])
                        ->orWhereIn('name', ['البنك', 'البنوك']);
                })
                ->orderBy('id')
                ->get(['id']);

            foreach ($fallbackBank as $candidate) {
                if (! isset($contactAccountIds[(int) $candidate->id])) {
                    DB::table('chart_of_accounts')
                        ->where('id', $candidate->id)
                        ->update(['account_purpose' => AccountPurpose::BANK->value]);
                    break;
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settingData
     * @param  array<int, true>  $contactAccountIds
     */
    private function removeUnreferencedLegacyStructuralAccounts(array $settingData, array $contactAccountIds): void
    {
        $protectedIds = $contactAccountIds;

        foreach ($settingData as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $protectedIds[(int) $value] = true;
            }
        }

        if (Schema::hasTable('payment_methods') && Schema::hasColumn('payment_methods', 'chart_of_account_id')) {
            $pmIds = DB::table('payment_methods')
                ->whereNotNull('chart_of_account_id')
                ->pluck('chart_of_account_id');
            foreach ($pmIds as $pmAccountId) {
                $protectedIds[(int) $pmAccountId] = true;
            }
        }

        $candidates = DB::table('chart_of_accounts')
            ->whereIn('code', array_keys(self::LEGACY_STRUCTURAL_ACCOUNTS))
            ->get(['id', 'code', 'name']);

        $idsToDelete = [];
        foreach ($candidates as $candidate) {
            $expectedName = self::LEGACY_STRUCTURAL_ACCOUNTS[$candidate->code] ?? null;
            if ($expectedName !== $candidate->name) {
                continue;
            }
            if (isset($protectedIds[(int) $candidate->id])) {
                continue;
            }
            $idsToDelete[] = (int) $candidate->id;
        }

        if ($idsToDelete !== []) {
            DB::table('chart_of_accounts')->whereIn('id', $idsToDelete)->delete();
        }
    }
};
