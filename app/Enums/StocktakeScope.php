<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeScope: string
{
    case FULL = 'full';
    case SELECTED = 'selected';

    /**
     * Get array of all string values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::FULL => 'جرد كامل',
            self::SELECTED => 'منتجات مختارة',
        };
    }
}
