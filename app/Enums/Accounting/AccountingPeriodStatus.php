<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum AccountingPeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
