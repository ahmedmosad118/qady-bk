<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class WarehouseStockPolicy extends BasePolicy
{
    protected string $module = 'warehouse_stocks';

    public function viewMovements(User $user): bool
    {
        return $this->canAny($user, ['warehouse_stocks.view_movements']);
    }
}
