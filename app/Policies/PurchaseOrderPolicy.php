<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PurchaseOrderPolicy extends BasePolicy
{
    protected string $module = 'purchase_orders';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'purchase_invoices.create',
        'purchase_invoices.update',
    ];

    public function view(User $user, mixed $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function confirm(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['purchase_orders.confirm']);
    }

    public function cancel(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['purchase_orders.cancel']);
    }
}
