<?php

declare(strict_types=1);

namespace App\Enums;

enum ExpenseStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';

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
            self::DRAFT => 'Draft',
            self::POSTED => 'Posted',
        };
    }
}
