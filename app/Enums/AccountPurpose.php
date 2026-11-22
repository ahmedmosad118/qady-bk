<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountPurpose: string
{
    case CASH = 'cash';
    case BANK = 'bank';
    case GENERAL = 'general';

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
            self::CASH => (string) __('accounting.purposes.cash'),
            self::BANK => (string) __('accounting.purposes.bank'),
            self::GENERAL => (string) __('accounting.purposes.general'),
        };
    }
}
