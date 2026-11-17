<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * POS always sells from the single system default warehouse.
 * Client-supplied warehouse IDs are intentionally ignored.
 */
class PosDefaultWarehouseResolver
{
    public function resolve(): Warehouse
    {
        /** @var Warehouse|null $warehouse */
        $warehouse = Warehouse::query()->default()->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse' => [__('pos.errors.default_warehouse_missing')],
            ]);
        }

        return $warehouse;
    }
}
