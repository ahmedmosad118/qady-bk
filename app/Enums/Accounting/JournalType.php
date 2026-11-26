<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum JournalType: string
{
    case Sales = 'Sales';
    case Purchase = 'Purchase';
    case Cash = 'Cash';
    case Bank = 'Bank';
    case Expense = 'Expense';
    case Miscellaneous = 'Miscellaneous';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
