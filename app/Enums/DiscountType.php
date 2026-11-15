<?php

declare(strict_types=1);

namespace App\Enums;

enum DiscountType: string
{
    case FIXED = 'fixed';
    case PERCENTAGE = 'percentage';

    /**
     * Get array of all string values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Human-readable label in Arabic.
     */
    public function label(): string
    {
        return match ($this) {
            self::FIXED => 'مبلغ ثابت',
            self::PERCENTAGE => 'نسبة مئوية',
        };
    }
}
