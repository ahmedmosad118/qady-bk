<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Enums\Accounting\AccountTypeCode;

/**
 * Single source of truth for active Accounting Settings keys, constraints, and metadata.
 */
class AccountingSettingRegistry
{
    public const SETTING_TYPE = 'accounting';

    /**
     * Obsolete keys removed when moving cash/bank to Payment Methods.
     *
     * @var array<int, string>
     */
    public const OBSOLETE_KEYS = [
        'cash',
        'bank',
    ];

    /**
     * Return active accounting setting definitions keyed by setting key.
     *
     * @return array<string, array{
     *     key: string,
     *     account_type: AccountTypeCode,
     *     required: bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            'accounts_receivable' => [
                'key' => 'accounts_receivable',
                'account_type' => AccountTypeCode::Receivable,
                'required' => false,
            ],
            'accounts_payable' => [
                'key' => 'accounts_payable',
                'account_type' => AccountTypeCode::Payable,
                'required' => false,
            ],
            'additional_expenses_account_id' => [
                'key' => 'additional_expenses_account_id',
                'account_type' => AccountTypeCode::Expenses,
                'required' => false,
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
    public static function obsoleteKeys(): array
    {
        return self::OBSOLETE_KEYS;
    }

    public static function label(string $key): string
    {
        return (string) __("accounting.settings.keys.{$key}.label");
    }

    public static function description(string $key): string
    {
        return (string) __("accounting.settings.keys.{$key}.description");
    }

    public static function accountTypeLabel(AccountTypeCode $type): string
    {
        return (string) __("accounting.account_type_codes.{$type->value}");
    }
}
