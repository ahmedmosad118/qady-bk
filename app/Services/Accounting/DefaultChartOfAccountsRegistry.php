<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;

/**
 * Central definition of the simplified single-level default Chart of Accounts
 * for a small bookstore (دار القاضي).
 */
class DefaultChartOfAccountsRegistry
{
    /**
     * Return the default single-level bookstore accounts.
     *
     * @return array<int, array{
     *     key: string,
     *     code: string,
     *     name: string,
     *     account_type: AccountType,
     *     account_purpose: AccountPurpose,
     *     description: string,
     *     legacy_codes: array<int, string>,
     *     legacy_names: array<int, string>
     * }>
     */
    public static function accounts(): array
    {
        return [
            [
                'key' => 'cash',
                'code' => '1001',
                'name' => 'الصندوق الرئيسي',
                'account_type' => AccountType::ASSET,
                'account_purpose' => AccountPurpose::CASH,
                'description' => 'النقدية بالخزينة وصناديق نقاط البيع',
                'legacy_codes' => ['1.001.001'],
                'legacy_names' => ['الصندوق الرئيسي', 'الصندوق (النقدية)', 'الصندوق'],
            ],
            [
                'key' => 'bank',
                'code' => '1002',
                'name' => 'البنك',
                'account_type' => AccountType::ASSET,
                'account_purpose' => AccountPurpose::BANK,
                'description' => 'الحساب المصرفي لمدفوعات البطاقات والتحويلات البنكية',
                'legacy_codes' => ['1.001.002'],
                'legacy_names' => ['البنك', 'البنوك'],
            ],
            [
                'key' => 'inventory',
                'code' => '1003',
                'name' => 'المخزون',
                'account_type' => AccountType::ASSET,
                'account_purpose' => AccountPurpose::GENERAL,
                'description' => 'مخزون الكتب والإصدارات والمنتجات',
                'legacy_codes' => ['1.001.004'],
                'legacy_names' => ['المخزون'],
            ],
            [
                'key' => 'capital',
                'code' => '3001',
                'name' => 'رأس المال',
                'account_type' => AccountType::EQUITY,
                'account_purpose' => AccountPurpose::GENERAL,
                'description' => 'رأس المال المستثمر في المكتبة',
                'legacy_codes' => ['3.001'],
                'legacy_names' => ['رأس المال'],
            ],
            [
                'key' => 'book_sales',
                'code' => '4001',
                'name' => 'مبيعات الكتب',
                'account_type' => AccountType::REVENUE,
                'account_purpose' => AccountPurpose::GENERAL,
                'description' => 'إيرادات مبيعات الكتب والإصدارات',
                'legacy_codes' => ['4.001'],
                'legacy_names' => ['مبيعات الكتب'],
            ],
            [
                'key' => 'sales_returns',
                'code' => '4002',
                'name' => 'مردودات المبيعات',
                'account_type' => AccountType::REVENUE,
                'account_purpose' => AccountPurpose::GENERAL,
                'description' => 'مرتجعات المبيعات في نقاط البيع',
                'legacy_codes' => ['4.003'],
                'legacy_names' => ['مردودات المبيعات', 'مردودات ومسموحات المبيعات'],
            ],
            [
                'key' => 'cost_of_goods_sold',
                'code' => '5001',
                'name' => 'تكلفة البضاعة المباعة',
                'account_type' => AccountType::EXPENSE,
                'account_purpose' => AccountPurpose::GENERAL,
                'description' => 'تكلفة الكتب والمنتجات المباعة',
                'legacy_codes' => ['5.001'],
                'legacy_names' => ['تكلفة البضاعة المباعة'],
            ],
            [
                'key' => 'operating_expenses',
                'code' => '5002',
                'name' => 'المصروفات التشغيلية',
                'account_type' => AccountType::EXPENSE,
                'account_purpose' => AccountPurpose::GENERAL,
                'description' => 'المصروفات التشغيلية والإدارية للمكتبة',
                'legacy_codes' => ['5.007'],
                'legacy_names' => ['المصروفات التشغيلية', 'مصروفات تشغيلية أخرى'],
            ],
        ];
    }
}
