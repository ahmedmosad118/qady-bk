<?php

declare(strict_types=1);

namespace App\Events\Purchase;

use App\Models\PurchaseInvoice;
use Illuminate\Foundation\Events\Dispatchable;

class PurchaseInvoiceDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly PurchaseInvoice $invoice,
    ) {}
}
