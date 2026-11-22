<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PurchaseInvoice;
use App\Models\User;

class PurchaseInvoicePolicy extends BasePolicy
{
    protected string $module = 'purchase_invoices';

    /** @var list<string> */
    protected array $excludeFromBefore = ['update', 'cancel', 'delete'];

    public function update(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['purchase_invoices.update'])
            && (! $model instanceof PurchaseInvoice || $model->isEditable());
    }

    public function cancel(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['purchase_invoices.cancel'])
            && (! $model instanceof PurchaseInvoice || $model->isEditable());
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['purchase_invoices.delete'])
            && (! $model instanceof PurchaseInvoice || $model->isEditable());
    }
}
