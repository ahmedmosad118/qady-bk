<?php

declare(strict_types=1);

namespace App\Policies;

class ContactPolicy extends BasePolicy
{
    protected string $module = 'contacts';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'pos.sell',
        'purchase_orders.view',
        'purchase_orders.create',
        'purchase_orders.update',
        'purchase_invoices.view',
        'purchase_invoices.create',
        'purchase_invoices.update',
        'expenses.view',
        'expenses.create',
        'expenses.update',
    ];
}
