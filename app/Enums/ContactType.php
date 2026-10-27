<?php

namespace App\Enums;

enum ContactType: string
{
    case INDIVIDUAL = 'individual';
    case COMPANY = 'company';

    /**
     * Get array of all string values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
