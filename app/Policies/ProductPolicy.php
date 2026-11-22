<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ProductPolicy extends BasePolicy
{
    protected string $module = 'products';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'products.print_barcodes',
        'inventory_documents.view',
        'inventory_documents.create',
        'inventory_documents.update',
        'stocktakes.view',
        'stocktakes.create',
        'purchase_orders.view',
        'purchase_orders.create',
        'purchase_orders.update',
        'purchase_invoices.view',
        'purchase_invoices.create',
        'purchase_invoices.update',
        'warehouse_stocks.view',
        'warehouse_stocks.view_movements',
    ];

    public function printBarcodes(User $user): bool
    {
        return $this->canAny($user, ['products.print_barcodes']);
    }

    public function lookupBarcode(User $user): bool
    {
        return $this->viewAny($user);
    }
}
