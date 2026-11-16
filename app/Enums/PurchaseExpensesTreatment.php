<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseExpensesTreatment: string
{
    case LANDED_COST = 'landed_cost';
    case SEPARATE_EXPENSE = 'separate_expense';

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
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::LANDED_COST => 'Landed Cost',
            self::SEPARATE_EXPENSE => 'Separate Expense',
        };
    }
}
