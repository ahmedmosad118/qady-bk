<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum JournalEntryStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
