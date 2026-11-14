<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMovementDirection: string
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';

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
     * Get the inverted direction for reversal operations.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::INBOUND => self::OUTBOUND,
            self::OUTBOUND => self::INBOUND,
        };
    }
}
