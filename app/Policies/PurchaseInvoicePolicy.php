<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseInvoice;
use App\Models\User;

class PurchaseInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseInvoice $invoice): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, PurchaseInvoice $invoice): bool
    {
        return $invoice->isEditable();
    }

    public function cancel(User $user, PurchaseInvoice $invoice): bool
    {
        return $invoice->isEditable();
    }

    public function delete(User $user, PurchaseInvoice $invoice): bool
    {
        return $invoice->isEditable();
    }
}
