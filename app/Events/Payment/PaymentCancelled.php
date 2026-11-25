<?php

declare(strict_types=1);

namespace App\Events\Payment;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PaymentCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly User $user,
    ) {}
}
