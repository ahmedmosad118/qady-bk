<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;

/**
 * Single source of truth for Accounting Settings keys, constraints, and defaults.
 */
class AccountingSettingRegistry
{
    public const SETTING_TYPE = 'accounting';

    /**
     * Obsolete keys removed when moving cash/bank to Payment Methods
     * and customer/supplier accounts directly to Contacts.
     *
     * @var array<int, string>
     */
    public const OBSOLETE_KEYS = [
        'cash',
        'bank',
        'accounts_receivable',
        'accounts_payable',
    ];

    /**
     * Return all accounting setting definitions keyed by setting key.
     *
     * @return array<string, array{
     *     key: string,
     *     account_type: AccountType,
     *     required: bool,
     *     default_chart_key: ?string
     * }>
     */
    public static function definitions(): array
    {
        return [
            'inventory' => [
                'key' => 'inventory',
                'account_type' => AccountType::ASSET,
                'required' => true,
                'default_chart_key' => 'inventory',
            ],
            'capital' => [
                'key' => 'capital',
                'account_type' => AccountType::EQUITY,
                'required' => true,
                'default_chart_key' => 'capital',
            ],
            'owner_current' => [
                'key' => 'owner_current',
                'account_type' => AccountType::EQUITY,
                'required' => false,
                'default_chart_key' => null,
            ],
            'sales_revenue' => [
                'key' => 'sales_revenue',
                'account_type' => AccountType::REVENUE,
                'required' => true,
                'default_chart_key' => 'book_sales',
            ],
            'sales_returns' => [
                'key' => 'sales_returns',
                'account_type' => AccountType::REVENUE,
                'required' => true,
                'default_chart_key' => 'sales_returns',
            ],
            'cost_of_goods_sold' => [
                'key' => 'cost_of_goods_sold',
                'account_type' => AccountType::EXPENSE,
                'required' => true,
                'default_chart_key' => 'cost_of_goods_sold',
            ],
            'operating_expenses' => [
                'key' => 'operating_expenses',
                'account_type' => AccountType::EXPENSE,
                'required' => true,
                'default_chart_key' => 'operating_expenses',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<int, string>
     */
    public static function requiredKeys(): array
    {
        return array_values(array_keys(array_filter(
            self::definitions(),
            static fn (array $def): bool => $def['required']
        )));
    }

    /**
     * @return array<int, string>
     */
    public static function obsoleteKeys(): array
    {
        return self::OBSOLETE_KEYS;
    }

    /**
     * @return array{
     *     key: string,
     *     account_type: AccountType,
     *     required: bool,
     *     default_chart_key: ?string
     * }|null
     */
    public static function get(string $key): ?array
    {
        return self::definitions()[$key] ?? null;
    }

    public static function label(string $key): string
    {
        return (string) __("accounting.settings.keys.{$key}.label");
    }

    public static function description(string $key): string
    {
        return (string) __("accounting.settings.keys.{$key}.description");
    }

    /**
     * Return localized metadata for UI and API consumption.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function metadataList(): array
    {
        $items = [];
        foreach (self::definitions() as $key => $def) {
            $items[] = [
                'key' => $key,
                'label' => self::label($key),
                'description' => self::description($key),
                'account_type' => $def['account_type']->value,
                'account_type_label' => $def['account_type']->label(),
                'required' => $def['required'],
            ];
        }

        return $items;
    }
}
