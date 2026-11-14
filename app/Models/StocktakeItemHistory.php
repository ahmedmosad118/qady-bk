<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stocktake_id',
    'stocktake_item_id',
    'action',
    'previous_quantity',
    'new_quantity',
    'previous_status',
    'new_status',
    'reason',
    'user_id',
])]
class StocktakeItemHistory extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_quantity' => 'decimal:3',
            'new_quantity' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class, 'stocktake_id');
    }

    public function stocktakeItem(): BelongsTo
    {
        return $this->belongsTo(StocktakeItem::class, 'stocktake_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
