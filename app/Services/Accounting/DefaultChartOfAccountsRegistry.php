<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;

/**
 * Central definition of the default Chart of Accounts for Qady Book ERP.
 *
 * Logical keys in this registry are used solely during seeding and default settings
 * initialization — no `system_key` column is persisted in `chart_of_accounts`.
 */
class DefaultChartOfAccountsRegistry
{
    /**
     * Return the default hierarchical chart tree.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tree(): array
    {
        return [
            [
                'key' => 'assets',
                'code' => '1',
                'name' => 'الأصول',
                'account_type' => AccountType::ASSET,
                'description' => 'الحساب الرئيسي لجميع أصول المكتبة وممتلكاتها',
                'children' => [
                    [
                        'key' => 'current_assets',
                        'code' => '1.001',
                        'name' => 'الأصول المتداولة',
                        'account_type' => AccountType::ASSET,
                        'description' => 'الأصول القابلة للتحول إلى نقدية خلال الدورة التشغيلية',
                        'children' => [
                            [
                                'key' => 'cash',
                                'code' => '1.001.001',
                                'name' => 'الصندوق (النقدية)',
                                'account_type' => AccountType::ASSET,
                                'description' => 'النقدية بالخزينة وصناديق نقاط البيع',
                            ],
                            [
                                'key' => 'banks',
                                'code' => '1.001.002',
                                'name' => 'البنوك',
                                'account_type' => AccountType::ASSET,
                                'description' => 'الحسابات المصرفية ومدفوعات البطاقات والتحويلات البنكية',
                            ],
                            [
                                'key' => 'accounts_receivable',
                                'code' => '1.001.003',
                                'name' => 'العملاء (الذمم المدينة)',
                                'account_type' => AccountType::ASSET,
                                'description' => 'حساب مراقبة ذمم العملاء',
                            ],
                            [
                                'key' => 'inventory',
                                'code' => '1.001.004',
                                'name' => 'المخزون',
                                'account_type' => AccountType::ASSET,
                                'description' => 'مخزون الكتب والإصدارات والمنتجات',
                            ],
                            [
                                'key' => 'prepaid_expenses',
                                'code' => '1.001.005',
                                'name' => 'المصروفات المقدمة',
                                'account_type' => AccountType::ASSET,
                                'description' => 'مصروفات مدفوعة مقدماً تخص فترات مستقبلية',
                            ],
                            [
                                'key' => 'advances_and_custodies',
                                'code' => '1.001.006',
                                'name' => 'السلف والعهد',
                                'account_type' => AccountType::ASSET,
                                'description' => 'سلف الموظفين والعهد النقدية',
                            ],
                            [
                                'key' => 'other_current_assets',
                                'code' => '1.001.007',
                                'name' => 'أصول متداولة أخرى',
                                'account_type' => AccountType::ASSET,
                                'description' => 'أصول متداولة متنوعة',
                            ],
                        ],
                    ],
                    [
                        'key' => 'non_current_assets',
                        'code' => '1.002',
                        'name' => 'الأصول غير المتداولة',
                        'account_type' => AccountType::ASSET,
                        'description' => 'الأصول الثابتة طويلة الأجل',
                        'children' => [
                            [
                                'key' => 'furniture_and_fixtures',
                                'code' => '1.002.001',
                                'name' => 'الأثاث والتجهيزات',
                                'account_type' => AccountType::ASSET,
                                'description' => 'أثاث المكتبة ورفوف العرض والتجهيزات',
                            ],
                            [
                                'key' => 'computers_and_equipment',
                                'code' => '1.002.002',
                                'name' => 'الأجهزة والمعدات',
                                'account_type' => AccountType::ASSET,
                                'description' => 'أجهزة الحاسب ونقاط البيع والطابعات والماسحات',
                            ],
                            [
                                'key' => 'accumulated_depreciation',
                                'code' => '1.002.003',
                                'name' => 'مجمع الإهلاك',
                                'account_type' => AccountType::ASSET,
                                'description' => 'المجمع التراكمي لإهلاك الأصول الثابتة',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'liabilities',
                'code' => '2',
                'name' => 'الالتزامات',
                'account_type' => AccountType::LIABILITY,
                'description' => 'الحساب الرئيسي لالتزامات المكتبة تجاه الغير',
                'children' => [
                    [
                        'key' => 'current_liabilities',
                        'code' => '2.001',
                        'name' => 'الالتزامات المتداولة',
                        'account_type' => AccountType::LIABILITY,
                        'description' => 'الالتزامات قصيرة الأجل المستحقة خلال الدورة التشغيلية',
                        'children' => [
                            [
                                'key' => 'accounts_payable',
                                'code' => '2.001.001',
                                'name' => 'الموردون (الذمم الدائنة)',
                                'account_type' => AccountType::LIABILITY,
                                'description' => 'حساب مراقبة ذمم الموردين ودور النشر',
                            ],
                            [
                                'key' => 'accrued_expenses',
                                'code' => '2.001.002',
                                'name' => 'المصروفات المستحقة',
                                'account_type' => AccountType::LIABILITY,
                                'description' => 'مصروفات مستحقة الدفع ولم تُسدد بعد',
                            ],
                            [
                                'key' => 'other_payables',
                                'code' => '2.001.003',
                                'name' => 'ذمم دائنة أخرى',
                                'account_type' => AccountType::LIABILITY,
                                'description' => 'التزامات دائنة متنوعة',
                            ],
                            [
                                'key' => 'customer_advances',
                                'code' => '2.001.004',
                                'name' => 'دفعات مقدمة من العملاء',
                                'account_type' => AccountType::LIABILITY,
                                'description' => 'مبالغ محصلة مقدماً من العملاء قبل التسليم',
                            ],
                        ],
                    ],
                    [
                        'key' => 'long_term_liabilities',
                        'code' => '2.002',
                        'name' => 'الالتزامات طويلة الأجل',
                        'account_type' => AccountType::LIABILITY,
                        'description' => 'الالتزامات المستحقة بعد أكثر من سنة مالية',
                        'children' => [
                            [
                                'key' => 'long_term_loans',
                                'code' => '2.002.001',
                                'name' => 'قروض طويلة الأجل',
                                'account_type' => AccountType::LIABILITY,
                                'description' => 'تمويلات وقروض طويلة الأجل',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'equity',
                'code' => '3',
                'name' => 'حقوق الملكية',
                'account_type' => AccountType::EQUITY,
                'description' => 'الحساب الرئيسي لرأس المال والأرباح المحتجزة',
                'children' => [
                    [
                        'key' => 'capital',
                        'code' => '3.001',
                        'name' => 'رأس المال',
                        'account_type' => AccountType::EQUITY,
                        'description' => 'رأس المال المستثمر في المكتبة',
                    ],
                    [
                        'key' => 'owner_current_account',
                        'code' => '3.002',
                        'name' => 'جاري المالك',
                        'account_type' => AccountType::EQUITY,
                        'description' => 'المسحوبات والإيداعات الجارية للمالك',
                    ],
                    [
                        'key' => 'retained_earnings',
                        'code' => '3.003',
                        'name' => 'الأرباح المبقاة',
                        'account_type' => AccountType::EQUITY,
                        'description' => 'الأرباح المرحلة من الفترات المالية السابقة',
                    ],
                    [
                        'key' => 'current_period_profit_loss',
                        'code' => '3.004',
                        'name' => 'أرباح وخسائر الفترة الحالية',
                        'account_type' => AccountType::EQUITY,
                        'description' => 'نتيجة النشاط للفترة المالية الحالية',
                    ],
                ],
            ],
            [
                'key' => 'revenue',
                'code' => '4',
                'name' => 'الإيرادات',
                'account_type' => AccountType::REVENUE,
                'description' => 'الحساب الرئيسي لإيرادات المبيعات والأنشطة الأخرى',
                'children' => [
                    [
                        'key' => 'book_sales',
                        'code' => '4.001',
                        'name' => 'مبيعات الكتب',
                        'account_type' => AccountType::REVENUE,
                        'description' => 'إيرادات مبيعات الكتب والإصدارات',
                    ],
                    [
                        'key' => 'other_revenue',
                        'code' => '4.002',
                        'name' => 'إيرادات أخرى',
                        'account_type' => AccountType::REVENUE,
                        'description' => 'إيرادات متنوعة غير متعلقة بالمبيعات الأساسية',
                    ],
                    [
                        'key' => 'sales_returns_and_allowances',
                        'code' => '4.003',
                        'name' => 'مردودات ومسموحات المبيعات',
                        'account_type' => AccountType::REVENUE,
                        'description' => 'مرتجعات وخصومات المبيعات',
                    ],
                ],
            ],
            [
                'key' => 'expenses',
                'code' => '5',
                'name' => 'المصروفات',
                'account_type' => AccountType::EXPENSE,
                'description' => 'الحساب الرئيسي لتكاليف المبيعات والمصروفات التشغيلية',
                'children' => [
                    [
                        'key' => 'cost_of_goods_sold',
                        'code' => '5.001',
                        'name' => 'تكلفة البضاعة المباعة',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'تكلفة الكتب والمنتجات المباعة',
                    ],
                    [
                        'key' => 'salaries',
                        'code' => '5.002',
                        'name' => 'الرواتب والأجور',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'رواتب ومستحقات العاملين',
                    ],
                    [
                        'key' => 'rent',
                        'code' => '5.003',
                        'name' => 'الإيجار',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'إيجار مقار المكتبة والمخازن',
                    ],
                    [
                        'key' => 'utilities',
                        'code' => '5.004',
                        'name' => 'المرافق والخدمات',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'الكهرباء والمياه والإنترنت والاتصالات',
                    ],
                    [
                        'key' => 'shipping_and_transport',
                        'code' => '5.005',
                        'name' => 'الشحن والنقل',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'مصروفات الشحن والنقل والتوصيل',
                    ],
                    [
                        'key' => 'marketing',
                        'code' => '5.006',
                        'name' => 'التسويق والإعلان',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'مصروفات التسويق والمعارض والإعلانات',
                    ],
                    [
                        'key' => 'other_operating_expenses',
                        'code' => '5.007',
                        'name' => 'مصروفات تشغيلية أخرى',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'مصروفات تشغيلية وإدارية متنوعة',
                    ],
                    [
                        'key' => 'depreciation',
                        'code' => '5.008',
                        'name' => 'مصروف الإهلاك',
                        'account_type' => AccountType::EXPENSE,
                        'description' => 'مصروف إهلاك الأصول الثابتة',
                    ],
                ],
            ],
        ];
    }
}
