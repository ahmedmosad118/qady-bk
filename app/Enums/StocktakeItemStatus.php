<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeItemStatus: string
{
    case PENDING = 'pending';
    case COUNTING = 'counting';
    case COUNTED = 'counted';

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
            self::PENDING => 'بانتظار العد',
            self::COUNTING => 'جاري العد',
            self::COUNTED => 'تم العد',
        };
    }
}
