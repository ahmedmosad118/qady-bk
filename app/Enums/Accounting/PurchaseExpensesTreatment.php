<?php

declare(strict_types=1);

namespace App\Enums\Accounting;

enum PurchaseExpensesTreatment: string
{
    case LandedCost = 'LANDED_COST';
    case SeparateExpense = 'SEPARATE_EXPENSE';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
