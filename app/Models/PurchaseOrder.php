<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'order_number',
    'supplier_id',
    'order_date',
    'expected_delivery_date',
    'status',
    'notes',
    'subtotal',
    'discount_type',
    'discount_value',
    'discount_amount',
    'total',
    'confirmed_at',
    'confirmed_by',
    'created_by',
])]
class PurchaseOrder extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d',
            'expected_delivery_date' => 'date:Y-m-d',
            'status' => PurchaseOrderStatus::class,
            'discount_type' => DiscountType::class,
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * The supplier this purchase order was placed with.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /**
     * Line items on this purchase order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    /**
     * User who created the order.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who confirmed/converted the order.
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Scope to draft orders.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PurchaseOrderStatus::DRAFT);
    }

    /**
     * Scope to converted orders.
     */
    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('status', PurchaseOrderStatus::CONVERTED);
    }

    /**
     * Scope to cancelled orders.
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', PurchaseOrderStatus::CANCELLED);
    }

    /**
     * Search orders by order number, supplier name or notes.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('order_number', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhereHas('supplier', function (Builder $supplierQuery) use ($term): void {
                    $supplierQuery->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('tax_number', 'like', "%{$term}%");
                });
        });
    }

    public function isDraft(): bool
    {
        return $this->status === PurchaseOrderStatus::DRAFT;
    }

    public function isConverted(): bool
    {
        return $this->status === PurchaseOrderStatus::CONVERTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseOrderStatus::CANCELLED;
    }
}
