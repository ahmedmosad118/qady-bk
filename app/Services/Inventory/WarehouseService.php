<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseService
{
    public function __construct(
        protected DefaultWarehouseResolver $defaultWarehouseResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Warehouse>|LengthAwarePaginator
     */
    public function list(array $filters, ?int $perPage = null): Collection|LengthAwarePaginator
    {
        $activeOnly = filter_var($filters['active_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';

        $query = Warehouse::query()
            ->when($activeOnly, fn ($q) => $q->active())
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($sub) use ($search): void {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc');

        $columns = ['id', 'code', 'name', 'address', 'is_default', 'is_active'];

        if ($perPage !== null) {
            return $query->paginate(min(max($perPage, 1), 100), $columns);
        }

        return $query->get($columns);
    }

    public function findDefaultOrFirst(): ?Warehouse
    {
        return $this->defaultWarehouseResolver->findDefaultOrFirst();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $data): Warehouse {
            /** @var Warehouse $locked */
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

            $locked->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'],
                'phone' => $data['phone'] ?? null,
                'manager_name' => $data['manager_name'] ?? null,
                'description' => $data['description'] ?? null,
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $locked->is_active,
            ]);

            return $locked;
        });
    }
}
