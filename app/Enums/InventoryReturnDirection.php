<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryReturnDirection: string
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
}
