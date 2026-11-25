<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum PaymentRelatedType: string
{
    case CUSTOMER = 'customer';
    case SUPPLIER = 'supplier';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
