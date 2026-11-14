<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'code',
    'name',
    'description',
    'address',
    'phone',
    'manager_name',
    'is_default',
    'is_active',
])]
class Warehouse extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope query to only active warehouses.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query to the default warehouse.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * Inventory documents where this warehouse is the source.
     */
    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(InventoryDocument::class, 'source_warehouse_id');
    }

    /**
     * Inventory documents where this warehouse is the destination.
     */
    public function destinationDocuments(): HasMany
    {
        return $this->hasMany(InventoryDocument::class, 'destination_warehouse_id');
    }

    /**
     * Stock movements associated with this warehouse.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'warehouse_id');
    }

    /**
     * Stock balances held in this warehouse.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(WarehouseStock::class, 'warehouse_id');
    }

    /**
     * Stocktakes performed on this warehouse.
     */
    public function stocktakes(): HasMany
    {
        return $this->hasMany(Stocktake::class, 'warehouse_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Warehouse $warehouse): void {
            if ($warehouse->stockMovements()->exists()) {
                throw new \LogicException('Cannot delete warehouse that has historical stock movements.');
            }
            if ($warehouse->stocktakes()->exists()) {
                throw new \LogicException('Cannot delete warehouse that has associated stocktakes.');
            }
        });
    }
}
