<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum PaymentType: string
{
    case SEND = 'send';
    case RECEIVE = 'receive';
    case TRANSFER = 'transfer';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
