<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockMovementDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'inventory_document_id',
    'inventory_document_item_id',
    'product_id',
    'warehouse_id',
    'direction',
    'quantity',
    'movement_date',
    'reversal_of_id',
    'created_by',
])]
class StockMovement extends Model
{
    use HasFactory;

    /**
     * Stock movements only track created_at (no updated_at).
     */
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => StockMovementDirection::class,
            'quantity' => 'decimal:3',
            'movement_date' => 'date',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce strict immutability: movements cannot be modified or deleted once created.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Stock movements are immutable and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Stock movements are immutable and cannot be deleted.');
        });
    }

    /**
     * The parent inventory document.
     */
    public function inventoryDocument(): BelongsTo
    {
        return $this->belongsTo(InventoryDocument::class, 'inventory_document_id');
    }

    /**
     * The specific line item from the inventory document.
     */
    public function inventoryDocumentItem(): BelongsTo
    {
        return $this->belongsTo(InventoryDocumentItem::class, 'inventory_document_item_id');
    }

    /**
     * The product associated with this movement.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * The warehouse where this movement occurred.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * The user who triggered this movement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * If this is a reversal movement, reference to the original movement.
     */
    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * If this original movement was reversed, reference to the reversal movement.
     */
    public function reversalMovement(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
