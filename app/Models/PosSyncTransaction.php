<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_uuid',
    'shift_id',
    'pos_sale_id',
    'payload',
    'status',
    'conflict_reason',
    'synced_at',
])]
class PosSyncTransaction extends Model
{
    use HasFactory;

    public const STATUS_SYNCED = 'synced';
    public const STATUS_CONFLICT = 'conflict';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * Associated shift.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    /**
     * Associated POS sale (if successfully created).
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }
}
