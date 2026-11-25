<?php

declare(strict_types=1);

namespace App\Events\Purchase;

use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class PurchaseInvoiceCreated
{
    use Dispatchable;

    public function __construct(
        public readonly PurchaseInvoice $invoice,
        public readonly User $user,
    ) {}
}
