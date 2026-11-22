<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeVarianceDirection;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'stocktake_id',
    'product_id',
    'system_quantity',
    'counted_quantity',
    'count_status',
    'count_started_by',
    'count_started_at',
    'counted_by',
    'counted_at',
    'notes',
])]
class StocktakeItem extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'count_status' => StocktakeItemStatus::class,
            'system_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'count_started_at' => 'datetime',
            'counted_at' => 'datetime',
        ];
    }

    /**
     * Parent stocktake document.
     */
    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class, 'stocktake_id');
    }

    /**
     * Associated product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * User who initiated counting for this line.
     */
    public function countStartedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_started_by');
    }

    /**
     * User who recorded the final physical count.
     */
    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /**
     * History log of count attempts/modifications for this item.
     */
    public function histories(): HasMany
    {
        return $this->hasMany(StocktakeItemHistory::class, 'stocktake_item_id');
    }

    /**
     * Calculate variance: counted_quantity - system_quantity.
     * Returns null if counting is not completed or quantities are null.
     */
    public function getVariance(): ?string
    {
        if ($this->counted_quantity === null || $this->system_quantity === null) {
            return null;
        }

        return Decimal::sub((string) $this->counted_quantity, (string) $this->system_quantity, Decimal::QUANTITY_SCALE);
    }

    /**
     * Determine variance direction: increase, decrease, or exact.
     */
    public function getVarianceDirection(): ?StocktakeVarianceDirection
    {
        $variance = $this->getVariance();
        if ($variance === null) {
            return null;
        }

        $cmp = Decimal::comp($variance, '0.000', Decimal::QUANTITY_SCALE);
        if ($cmp > 0) {
            return StocktakeVarianceDirection::INCREASE;
        }
        if ($cmp < 0) {
            return StocktakeVarianceDirection::DECREASE;
        }

        return StocktakeVarianceDirection::EXACT;
    }

    public function isPending(): bool
    {
        return $this->count_status === StocktakeItemStatus::PENDING;
    }

    public function isCounting(): bool
    {
        return $this->count_status === StocktakeItemStatus::COUNTING;
    }

    public function isCounted(): bool
    {
        return $this->count_status === StocktakeItemStatus::COUNTED;
    }
}
