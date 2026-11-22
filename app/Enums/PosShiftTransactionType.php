<?php

declare(strict_types=1);

namespace App\Enums;

enum PosShiftTransactionType: string
{
    case CASH_SALE = 'cash_sale';
    case CASH_RETURN = 'cash_return';
    case EXPENSE = 'expense';
    case WITHDRAWAL = 'withdrawal';

    /**
     * Get all string values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
