<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Accounting\AccountClassCode;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\JournalType;
use App\Models\AccountClass;
use App\Models\AccountType;
use App\Models\Journal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccountingSeeder extends Seeder
{
    /**
     * Seed account classes, account types, and default journals idempotently in strict order.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $classesByCode = $this->seedAccountClasses();
            $this->seedAccountTypes($classesByCode);
            $this->seedJournals();
        });

        $this->call(AccountSeeder::class);
    }

    /**
     * @return array<string, int>
     */
    private function seedAccountClasses(): array
    {
        $definitions = [
            ['code' => AccountClassCode::Asset->value, 'name' => 'الأصول', 'sort_order' => 1],
            ['code' => AccountClassCode::Liability->value, 'name' => 'الالتزامات', 'sort_order' => 2],
            ['code' => AccountClassCode::Equity->value, 'name' => 'حقوق الملكية', 'sort_order' => 3],
            ['code' => AccountClassCode::Income->value, 'name' => 'الإيرادات', 'sort_order' => 4],
            ['code' => AccountClassCode::Expense->value, 'name' => 'المصروفات', 'sort_order' => 5],
            ['code' => AccountClassCode::OffBalance->value, 'name' => 'خارج الميزانية', 'sort_order' => 6],
        ];

        $existing = AccountClass::query()
            ->whereIn('code', array_column($definitions, 'code'))
            ->get()
            ->keyBy('code');

        $idsByCode = [];

        foreach ($definitions as $item) {
            /** @var AccountClass|null $record */
            $record = $existing->get($item['code']);

            if ($record) {
                $record->fill([
                    'name' => $item['name'],
                    'sort_order' => $item['sort_order'],
                ]);

                if ($record->isDirty()) {
                    $record->save();
                }

                $idsByCode[$item['code']] = $record->id;

                continue;
            }

            $created = AccountClass::query()->create($item);
            $idsByCode[$item['code']] = $created->id;
        }

        return $idsByCode;
    }

    /**
     * @param  array<string, int>  $classesByCode
     */
    private function seedAccountTypes(array $classesByCode): void
    {
        $definitions = [
            // Asset
            ['code' => AccountTypeCode::Receivable->value, 'class_code' => AccountClassCode::Asset->value, 'name' => 'الذمم المدينة (العملاء)', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 1],
            ['code' => AccountTypeCode::BankCash->value, 'class_code' => AccountClassCode::Asset->value, 'name' => 'النقدية والبنوك', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 2],
            ['code' => AccountTypeCode::CurrentAssets->value, 'class_code' => AccountClassCode::Asset->value, 'name' => 'الأصول المتداولة', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 3],
            ['code' => AccountTypeCode::NonCurrentAssets->value, 'class_code' => AccountClassCode::Asset->value, 'name' => 'الأصول غير المتداولة', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 4],
            ['code' => AccountTypeCode::Prepayments->value, 'class_code' => AccountClassCode::Asset->value, 'name' => 'المصروفات المقدمة', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 5],
            ['code' => AccountTypeCode::FixedAssets->value, 'class_code' => AccountClassCode::Asset->value, 'name' => 'الأصول الثابتة', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 6],

            // Liability
            ['code' => AccountTypeCode::Payable->value, 'class_code' => AccountClassCode::Liability->value, 'name' => 'الذمم الدائنة (الموردون)', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 7],
            ['code' => AccountTypeCode::CreditCard->value, 'class_code' => AccountClassCode::Liability->value, 'name' => 'بطاقات الائتمان', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 8],
            ['code' => AccountTypeCode::CurrentLiabilities->value, 'class_code' => AccountClassCode::Liability->value, 'name' => 'الالتزامات المتداولة', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 9],
            ['code' => AccountTypeCode::NonCurrentLiabilities->value, 'class_code' => AccountClassCode::Liability->value, 'name' => 'الالتزامات غير المتداولة', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 10],

            // Equity
            ['code' => AccountTypeCode::Equity->value, 'class_code' => AccountClassCode::Equity->value, 'name' => 'حقوق الملكية', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 11],
            ['code' => AccountTypeCode::CurrentYearEarnings->value, 'class_code' => AccountClassCode::Equity->value, 'name' => 'أرباح العام الحالي', 'include_in_balance_sheet' => true, 'include_in_profit_loss' => false, 'sort_order' => 12],

            // Income
            ['code' => AccountTypeCode::Income->value, 'class_code' => AccountClassCode::Income->value, 'name' => 'الإيرادات', 'include_in_balance_sheet' => false, 'include_in_profit_loss' => true, 'sort_order' => 13],
            ['code' => AccountTypeCode::OtherIncome->value, 'class_code' => AccountClassCode::Income->value, 'name' => 'إيرادات أخرى', 'include_in_balance_sheet' => false, 'include_in_profit_loss' => true, 'sort_order' => 14],

            // Expense
            ['code' => AccountTypeCode::Expenses->value, 'class_code' => AccountClassCode::Expense->value, 'name' => 'المصروفات', 'include_in_balance_sheet' => false, 'include_in_profit_loss' => true, 'sort_order' => 15],
            ['code' => AccountTypeCode::Depreciation->value, 'class_code' => AccountClassCode::Expense->value, 'name' => 'الإهلاك', 'include_in_balance_sheet' => false, 'include_in_profit_loss' => true, 'sort_order' => 16],
            ['code' => AccountTypeCode::CostOfRevenue->value, 'class_code' => AccountClassCode::Expense->value, 'name' => 'تكلفة الإيرادات', 'include_in_balance_sheet' => false, 'include_in_profit_loss' => true, 'sort_order' => 17],

            // Off Balance Sheet
            ['code' => AccountTypeCode::OffBalance->value, 'class_code' => AccountClassCode::OffBalance->value, 'name' => 'خارج الميزانية', 'include_in_balance_sheet' => false, 'include_in_profit_loss' => false, 'sort_order' => 18],
        ];

        $existing = AccountType::query()
            ->whereIn('code', array_column($definitions, 'code'))
            ->get()
            ->keyBy('code');

        foreach ($definitions as $item) {
            $attributes = [
                'account_class_id' => $classesByCode[$item['class_code']],
                'name' => $item['name'],
                'include_in_balance_sheet' => $item['include_in_balance_sheet'],
                'include_in_profit_loss' => $item['include_in_profit_loss'],
                'sort_order' => $item['sort_order'],
            ];

            /** @var AccountType|null $record */
            $record = $existing->get($item['code']);

            if ($record) {
                $record->fill($attributes);

                if ($record->isDirty()) {
                    $record->save();
                }

                continue;
            }

            AccountType::query()->create(array_merge($attributes, [
                'code' => $item['code'],
                'is_active' => true,
            ]));
        }
    }

    private function seedJournals(): void
    {
        $definitions = [
            ['code' => 'SAL', 'name' => 'دفتر المبيعات', 'type' => JournalType::Sales->value],
            ['code' => 'PUR', 'name' => 'دفتر المشتريات', 'type' => JournalType::Purchase->value],
            ['code' => 'EXP', 'name' => 'دفتر المصروفات', 'type' => JournalType::Expense->value],
            ['code' => 'CSH', 'name' => 'دفتر النقدية', 'type' => JournalType::Cash->value],
            ['code' => 'BNK', 'name' => 'دفتر البنك', 'type' => JournalType::Bank->value],
            ['code' => 'MSC', 'name' => 'دفتر اليومية العامة', 'type' => JournalType::Miscellaneous->value],
            ['code' => 'ADJST', 'name' => 'التسويات', 'type' => JournalType::Miscellaneous->value],
            ['code' => 'STJ', 'name' => 'دفتر تقييم المخزون', 'type' => JournalType::Miscellaneous->value],
            ['code' => 'OPBJ', 'name' => 'الأرصدة الافتتاحية', 'type' => JournalType::Miscellaneous->value],
        ];

        $existing = Journal::query()
            ->whereIn('code', array_column($definitions, 'code'))
            ->get()
            ->keyBy('code');

        foreach ($definitions as $item) {
            /** @var Journal|null $record */
            $record = $existing->get($item['code']);

            if ($record) {
                $record->fill([
                    'name' => $item['name'],
                    'type' => $item['type'],
                ]);

                if ($record->isDirty()) {
                    $record->save();
                }

                continue;
            }

            Journal::query()->create(array_merge($item, [
                'is_active' => true,
            ]));
        }
    }
}
