<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Warehouse;
use App\Services\Inventory\DefaultWarehouseResolver;

/**
 * POS always sells from the single system default warehouse.
 * Client-supplied warehouse IDs are intentionally ignored.
 */
class PosDefaultWarehouseResolver
{
    public function __construct(
        protected DefaultWarehouseResolver $resolver,
    ) {}

    public function resolve(): Warehouse
    {
        return $this->resolver->resolveOrFail('pos.errors.default_warehouse_missing');
    }
}
