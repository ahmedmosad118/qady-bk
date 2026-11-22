<?php

declare(strict_types=1);

namespace App\Policies;

class WarehousePolicy extends BasePolicy
{
    protected string $module = 'warehouses';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'pos.sell',
        'inventory_documents.view',
        'inventory_documents.create',
        'inventory_documents.update',
        'warehouse_stocks.view',
        'warehouse_stocks.view_movements',
        'stocktakes.view',
        'stocktakes.create',
    ];
}
