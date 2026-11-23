<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum NormalBalance: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
