<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StocktakeScope;
use App\Enums\StocktakeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

#[Fillable([
    'stocktake_number',
    'warehouse_id',
    'scope',
    'status',
    'stocktake_date',
    'notes',
    'created_by',
    'started_by',
    'started_at',
    'submitted_by',
    'submitted_at',
    'approved_by',
    'approved_at',
    'rejected_by',
    'rejected_at',
    'rejection_reason',
    'cancelled_by',
    'cancelled_at',
    'completed_by',
    'completed_at',
])]
class Stocktake extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => StocktakeScope::class,
            'status' => StocktakeStatus::class,
            'stocktake_date' => 'date',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Prevent hard deletion after creation; cancellation must be used instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (Stocktake $stocktake): void {
            throw new LogicException('لا يمكن حذف مستند الجرد المخزني؛ استخدم الإلغاء بدلاً من الحذف للحفاظ على السجل التاريخي.');
        });
    }

    /**
     * Items in this stocktake.
     */
    public function items(): HasMany
    {
        return $this->hasMany(StocktakeItem::class, 'stocktake_id');
    }

    /**
     * Target warehouse being counted.
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    /**
     * User who created the stocktake.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who started the stocktake.
     */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * User who submitted the stocktake for approval.
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /**
     * User who approved the stocktake.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * User who rejected the stocktake.
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * User who cancelled the stocktake.
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * User who marked the stocktake completed.
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Generated adjustment documents linked to this stocktake.
     */
    public function inventoryDocuments(): MorphMany
    {
        return $this->morphMany(
            InventoryDocument::class,
            'referenceable'
        );
    }

    /**
     * Audit and counting history entries.
     */
    public function histories(): HasMany
    {
        return $this->hasMany(StocktakeItemHistory::class, 'stocktake_id');
    }

    /**
     * Scope for active stocktakes that lock the warehouse from other stocktakes.
     * (in_progress, pending_approval, approved, rejected).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            StocktakeStatus::IN_PROGRESS,
            StocktakeStatus::PENDING_APPROVAL,
            StocktakeStatus::APPROVED,
            StocktakeStatus::REJECTED,
        ]);
    }

    public function isDraft(): bool
    {
        return $this->status === StocktakeStatus::DRAFT;
    }

    public function isInProgress(): bool
    {
        return $this->status === StocktakeStatus::IN_PROGRESS;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === StocktakeStatus::PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === StocktakeStatus::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === StocktakeStatus::REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === StocktakeStatus::CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->status === StocktakeStatus::COMPLETED;
    }
}
