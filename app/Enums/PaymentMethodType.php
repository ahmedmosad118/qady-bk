<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentMethodType: string
{
    case CASH = 'cash';
    case VISA = 'visa';
    case BANK_TRANSFER = 'bank_transfer';

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
     * Human-readable label in Arabic.
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'نقدي',
            self::VISA => 'فيزا',
            self::BANK_TRANSFER => 'تحويل بنكي',
        };
    }
}
