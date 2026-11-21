<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\AccountType;

/**
 * Single source of truth for Accounting Settings keys, constraints, and defaults.
 *
 * Every key maps an actual system function to its required AccountType,
 * whether it is mandatory, whether it serves as a control parent account
 * (for contact sub-accounts), and its default chart registry key.
 */
class AccountingSettingRegistry
{
    public const SETTING_TYPE = 'accounting';

    /**
     * Return all accounting setting definitions keyed by setting key.
     *
     * @return array<string, array{
     *     key: string,
     *     account_type: AccountType,
     *     required: bool,
     *     is_control_account: bool,
     *     default_chart_key: string
     * }>
     */
    public static function definitions(): array
    {
        return [
            'cash' => [
                'key' => 'cash',
                'account_type' => AccountType::ASSET,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'cash',
            ],
            'bank' => [
                'key' => 'bank',
                'account_type' => AccountType::ASSET,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'banks',
            ],
            'accounts_receivable' => [
                'key' => 'accounts_receivable',
                'account_type' => AccountType::ASSET,
                'required' => true,
                'is_control_account' => true,
                'default_chart_key' => 'accounts_receivable',
            ],
            'inventory' => [
                'key' => 'inventory',
                'account_type' => AccountType::ASSET,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'inventory',
            ],
            'accounts_payable' => [
                'key' => 'accounts_payable',
                'account_type' => AccountType::LIABILITY,
                'required' => true,
                'is_control_account' => true,
                'default_chart_key' => 'accounts_payable',
            ],
            'capital' => [
                'key' => 'capital',
                'account_type' => AccountType::EQUITY,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'capital',
            ],
            'owner_current' => [
                'key' => 'owner_current',
                'account_type' => AccountType::EQUITY,
                'required' => false,
                'is_control_account' => false,
                'default_chart_key' => 'owner_current_account',
            ],
            'sales_revenue' => [
                'key' => 'sales_revenue',
                'account_type' => AccountType::REVENUE,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'book_sales',
            ],
            'sales_returns' => [
                'key' => 'sales_returns',
                'account_type' => AccountType::REVENUE,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'sales_returns_and_allowances',
            ],
            'cost_of_goods_sold' => [
                'key' => 'cost_of_goods_sold',
                'account_type' => AccountType::EXPENSE,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'cost_of_goods_sold',
            ],
            'operating_expenses' => [
                'key' => 'operating_expenses',
                'account_type' => AccountType::EXPENSE,
                'required' => true,
                'is_control_account' => false,
                'default_chart_key' => 'other_operating_expenses',
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
    public static function postingKeys(): array
    {
        return array_values(array_keys(array_filter(
            self::definitions(),
            static fn (array $def): bool => ! $def['is_control_account']
        )));
    }

    /**
     * @return array<int, string>
     */
    public static function controlKeys(): array
    {
        return array_values(array_keys(array_filter(
            self::definitions(),
            static fn (array $def): bool => $def['is_control_account']
        )));
    }

    /**
     * @return array{
     *     key: string,
     *     account_type: AccountType,
     *     required: bool,
     *     is_control_account: bool,
     *     default_chart_key: string
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
                'is_control_account' => $def['is_control_account'],
            ];
        }

        return $items;
    }
}
