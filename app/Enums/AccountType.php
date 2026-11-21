<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountType: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case REVENUE = 'revenue';
    case EXPENSE = 'expense';

    /**
     * Get all enum string values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Localized human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ASSET => (string) __('accounting.types.asset'),
            self::LIABILITY => (string) __('accounting.types.liability'),
            self::EQUITY => (string) __('accounting.types.equity'),
            self::REVENUE => (string) __('accounting.types.revenue'),
            self::EXPENSE => (string) __('accounting.types.expense'),
        };
    }
}
