<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Accounting\AccountClassCode;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Models\AccountType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountSeeder extends Seeder
{
    /**
     * Seed the default Egyptian Arabic Chart of Accounts idempotently.
     */
    public function run(): void
    {
        $definitions = $this->definitions();

        DB::transaction(function () use ($definitions): void {
            $typesByCode = AccountType::query()
                ->with('accountClass:id,code')
                ->get()
                ->keyBy('code');

            $existingAccounts = Account::query()->get();
            $accountsByCode = $existingAccounts->keyBy('code');
            $accountsBySystemKey = $existingAccounts
                ->filter(static fn (Account $acc): bool => $acc->system_key !== null && $acc->system_key !== '')
                ->keyBy('system_key');

            foreach ($definitions as $item) {
                /** @var AccountType|null $accountType */
                $accountType = $typesByCode->get($item['account_type_code']);

                if (! $accountType) {
                    throw new RuntimeException(
                        "AccountType [{$item['account_type_code']}] not found for account [{$item['code']}]. Run AccountingSeeder first."
                    );
                }

                if ($accountType->accountClass?->code !== $item['expected_class_code']) {
                    throw new RuntimeException(
                        "AccountType [{$item['account_type_code']}] does not belong to AccountClass [{$item['expected_class_code']}]."
                    );
                }

                $systemKey = $item['system_key'];
                if ($systemKey !== null) {
                    /** @var Account|null $existingBySystem */
                    $existingBySystem = $accountsBySystemKey->get($systemKey);
                    /** @var Account|null $existingByCode */
                    $existingByCode = $accountsByCode->get($item['code']);

                    if ($existingBySystem) {
                        if ($existingBySystem->account_type_id !== $accountType->id) {
                            throw new RuntimeException(
                                "System account [{$systemKey}] exists with incompatible account_type_id [{$existingBySystem->account_type_id}]."
                            );
                        }

                        if ($existingByCode && $existingByCode->id !== $existingBySystem->id) {
                            throw new RuntimeException(
                                "Conflict between code [{$item['code']}] and system_key [{$systemKey}]."
                            );
                        }

                        continue;
                    }

                    if ($existingByCode) {
                        throw new RuntimeException(
                            "Conflict: code [{$item['code']}] is already used by another account without system_key [{$systemKey}]."
                        );
                    }

                    $created = Account::query()->create([
                        'account_type_id' => $accountType->id,
                        'code' => $item['code'],
                        'name' => $item['name'],
                        'normal_balance_override' => $item['normal_balance_override'],
                        'reconcile' => $item['reconcile'],
                        'is_active' => true,
                        'is_system' => true,
                        'system_key' => $systemKey,
                    ]);

                    $accountsByCode->put($created->code, $created);
                    $accountsBySystemKey->put($systemKey, $created);

                    continue;
                }

                /** @var Account|null $existing */
                $existing = $accountsByCode->get($item['code']);

                if ($existing) {
                    if ($existing->account_type_id !== $accountType->id) {
                        throw new RuntimeException(
                            "Account [{$item['code']}] exists with incompatible account_type_id [{$existing->account_type_id}]."
                        );
                    }

                    continue;
                }

                $created = Account::query()->create([
                    'account_type_id' => $accountType->id,
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'normal_balance_override' => $item['normal_balance_override'],
                    'reconcile' => $item['reconcile'],
                    'is_active' => true,
                    'is_system' => false,
                    'system_key' => null,
                ]);

                $accountsByCode->put($created->code, $created);
            }
        });
    }

    /**
     * @return array<int, array{
     *     code: string,
     *     name: string,
     *     account_type_code: string,
     *     expected_class_code: string,
     *     normal_balance_override: ?string,
     *     reconcile: bool,
     *     system_key: ?string
     * }>
     */
    private function definitions(): array
    {
        $debit = NormalBalance::Debit->value;
        $credit = NormalBalance::Credit->value;

        return [
            // Assets (1xxx)
            ['code' => '1101', 'name' => 'الخزينة الرئيسية', 'account_type_code' => AccountTypeCode::BankCash->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => true, 'system_key' => null],
            ['code' => '1102', 'name' => 'حسابات البنوك', 'account_type_code' => AccountTypeCode::BankCash->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => true, 'system_key' => null],
            ['code' => '1201', 'name' => 'العملاء', 'account_type_code' => AccountTypeCode::Receivable->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '1301', 'name' => 'مخزون البضاعة', 'account_type_code' => AccountTypeCode::CurrentAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => 'inventory'],
            ['code' => '1302', 'name' => 'بضاعة بالطريق', 'account_type_code' => AccountTypeCode::CurrentAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '1303', 'name' => 'حساب وسيط استلام المخزون', 'account_type_code' => AccountTypeCode::CurrentAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => 'stock_input'],
            ['code' => '1401', 'name' => 'المصروفات المقدمة', 'account_type_code' => AccountTypeCode::Prepayments->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '1501', 'name' => 'الأثاث والتجهيزات', 'account_type_code' => AccountTypeCode::FixedAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '1502', 'name' => 'الأجهزة والمعدات', 'account_type_code' => AccountTypeCode::FixedAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '1503', 'name' => 'مجمع إهلاك الأصول الثابتة', 'account_type_code' => AccountTypeCode::FixedAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '1601', 'name' => 'أصول غير متداولة أخرى', 'account_type_code' => AccountTypeCode::NonCurrentAssets->value, 'expected_class_code' => AccountClassCode::Asset->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],

            // Liabilities (2xxx)
            ['code' => '2101', 'name' => 'الموردون', 'account_type_code' => AccountTypeCode::Payable->value, 'expected_class_code' => AccountClassCode::Liability->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '2201', 'name' => 'المصروفات المستحقة', 'account_type_code' => AccountTypeCode::CurrentLiabilities->value, 'expected_class_code' => AccountClassCode::Liability->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '2202', 'name' => 'الرواتب المستحقة', 'account_type_code' => AccountTypeCode::CurrentLiabilities->value, 'expected_class_code' => AccountClassCode::Liability->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '2203', 'name' => 'التزامات قصيرة الأجل أخرى', 'account_type_code' => AccountTypeCode::CurrentLiabilities->value, 'expected_class_code' => AccountClassCode::Liability->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '2301', 'name' => 'التزامات طويلة الأجل', 'account_type_code' => AccountTypeCode::NonCurrentLiabilities->value, 'expected_class_code' => AccountClassCode::Liability->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '2401', 'name' => 'مستحقات بطاقات الائتمان', 'account_type_code' => AccountTypeCode::CreditCard->value, 'expected_class_code' => AccountClassCode::Liability->value, 'normal_balance_override' => $credit, 'reconcile' => true, 'system_key' => null],

            // Equity (3xxx)
            ['code' => '3101', 'name' => 'رأس المال', 'account_type_code' => AccountTypeCode::Equity->value, 'expected_class_code' => AccountClassCode::Equity->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => 'capital'],
            ['code' => '3102', 'name' => 'جاري المالك', 'account_type_code' => AccountTypeCode::Equity->value, 'expected_class_code' => AccountClassCode::Equity->value, 'normal_balance_override' => null, 'reconcile' => false, 'system_key' => 'owner_current'],
            ['code' => '3103', 'name' => 'الأرباح المحتجزة', 'account_type_code' => AccountTypeCode::Equity->value, 'expected_class_code' => AccountClassCode::Equity->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '3104', 'name' => 'أرباح أو خسائر العام الحالي', 'account_type_code' => AccountTypeCode::CurrentYearEarnings->value, 'expected_class_code' => AccountClassCode::Equity->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],

            // Income (4xxx)
            ['code' => '4101', 'name' => 'إيرادات مبيعات الكتب والبضائع', 'account_type_code' => AccountTypeCode::Income->value, 'expected_class_code' => AccountClassCode::Income->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => 'sales_revenue'],
            ['code' => '4102', 'name' => 'مردودات ومسموحات المبيعات', 'account_type_code' => AccountTypeCode::Income->value, 'expected_class_code' => AccountClassCode::Income->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => 'sales_returns'],
            ['code' => '4201', 'name' => 'إيرادات أخرى', 'account_type_code' => AccountTypeCode::OtherIncome->value, 'expected_class_code' => AccountClassCode::Income->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],
            ['code' => '4202', 'name' => 'إيرادات خدمات وعمولات', 'account_type_code' => AccountTypeCode::OtherIncome->value, 'expected_class_code' => AccountClassCode::Income->value, 'normal_balance_override' => $credit, 'reconcile' => false, 'system_key' => null],

            // Expenses (5xxx)
            ['code' => '5101', 'name' => 'تكلفة البضاعة المباعة', 'account_type_code' => AccountTypeCode::CostOfRevenue->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => 'cost_of_goods_sold'],
            ['code' => '5201', 'name' => 'مصروفات الإيجار', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5202', 'name' => 'مصروفات الكهرباء والمياه', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5203', 'name' => 'مصروفات الرواتب والأجور', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5204', 'name' => 'مصروفات الشحن والنقل', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5205', 'name' => 'مصروفات التسويق والإعلان', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5206', 'name' => 'مصروفات الاتصالات والإنترنت', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5207', 'name' => 'مصروفات الصيانة', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5208', 'name' => 'مصروفات إدارية وعمومية', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5209', 'name' => 'مصروفات بنكية', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],
            ['code' => '5210', 'name' => 'المصروفات التشغيلية', 'account_type_code' => AccountTypeCode::Expenses->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => 'operating_expenses'],
            ['code' => '5301', 'name' => 'مصروف إهلاك الأصول الثابتة', 'account_type_code' => AccountTypeCode::Depreciation->value, 'expected_class_code' => AccountClassCode::Expense->value, 'normal_balance_override' => $debit, 'reconcile' => false, 'system_key' => null],

            // Off Balance Sheet (9xxx)
            ['code' => '9101', 'name' => 'حسابات نظامية مدينة', 'account_type_code' => AccountTypeCode::OffBalance->value, 'expected_class_code' => AccountClassCode::OffBalance->value, 'normal_balance_override' => null, 'reconcile' => false, 'system_key' => null],
            ['code' => '9102', 'name' => 'حسابات نظامية دائنة', 'account_type_code' => AccountTypeCode::OffBalance->value, 'expected_class_code' => AccountClassCode::OffBalance->value, 'normal_balance_override' => null, 'reconcile' => false, 'system_key' => null],
        ];
    }
}
