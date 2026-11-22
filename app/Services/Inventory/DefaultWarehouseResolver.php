<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

class DefaultWarehouseResolver
{
    public function resolveOrFail(
        string $errorMessageKey = 'inventory.errors.warehouse_not_found',
        bool $requireActive = false
    ): Warehouse {
        $query = Warehouse::query()->where('is_default', true);

        if ($requireActive) {
            $query->where('is_active', true);
        }

        /** @var Warehouse|null $warehouse */
        $warehouse = $query->first();

        if ($warehouse === null) {
            throw ValidationException::withMessages([
                'warehouse' => [(string) __($errorMessageKey)],
            ]);
        }

        return $warehouse;
    }

    public function findDefaultOrFirst(): ?Warehouse
    {
        return Warehouse::query()->where('is_default', true)->first()
            ?? Warehouse::query()->first();
    }
}
