<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PurchaseInvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use LogicException;

#[Fillable([
    'invoice_number',
    'supplier_id',
    'purchase_order_id',
    'invoice_date',
    'due_date',
    'subtotal',
    'discount_type',
    'discount_value',
    'discount_amount',
    'additional_expenses_total',
    'total',
    'notes',
    'status',
    'created_by',
])]
class PurchaseInvoice extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'additional_expenses_total' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => PurchaseInvoiceStatus::class,
        ];
    }

    /**
     * The supplier associated with this purchase invoice.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /**
     * Optional originating purchase order.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * Line items on this purchase invoice.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class, 'purchase_invoice_id');
    }

    /**
     * Additional expenses on this purchase invoice.
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceExpense::class, 'purchase_invoice_id');
    }

    /**
     * User who created the purchase invoice.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Inventory documents linked to this purchase invoice.
     */
    public function inventoryDocuments(): MorphMany
    {
        return $this->morphMany(InventoryDocument::class, 'referenceable');
    }

    /**
     * Linked Goods Receipt inventory document.
     */
    public function goodsReceipt(): MorphOne
    {
        return $this->morphOne(InventoryDocument::class, 'referenceable')
            ->where('document_type', InventoryDocumentType::GOODS_RECEIPT->value);
    }

    /**
     * Scope to draft invoices.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', PurchaseInvoiceStatus::DRAFT);
    }

    /**
     * Scope to cancelled invoices.
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', PurchaseInvoiceStatus::CANCELLED);
    }

    /**
     * Search invoices by invoice number, notes, or supplier fields.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('invoice_number', 'like', "%{$term}%")
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
        return $this->status === PurchaseInvoiceStatus::DRAFT;
    }

    public function isCancelled(): bool
    {
        return $this->status === PurchaseInvoiceStatus::CANCELLED;
    }

    /**
     * Check whether the linked Goods Receipt has already been posted or affected inventory.
     */
    public function hasPostedGoodsReceipt(): bool
    {
        return $this->inventoryDocuments()
            ->where('document_type', InventoryDocumentType::GOODS_RECEIPT->value)
            ->where(function (Builder $query): void {
                $query->whereIn('status', [
                    InventoryDocumentStatus::POSTED->value,
                    InventoryDocumentStatus::REVERSED->value,
                ])->orWhereHas('stockMovements');
            })
            ->exists();
    }

    /**
     * An invoice is editable/cancellable only while draft and before its Goods Receipt is posted.
     */
    public function isEditable(): bool
    {
        return $this->isDraft() && ! $this->hasPostedGoodsReceipt();
    }

    /**
     * An invoice is locked once cancelled or once its Goods Receipt has affected inventory.
     */
    public function isLocked(): bool
    {
        return ! $this->isEditable();
    }

    protected static function booted(): void
    {
        static::creating(function (PurchaseInvoice $invoice): void {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = 'TMP-' . Str::uuid()->toString();
            }
        });

        static::created(function (PurchaseInvoice $invoice): void {
            if (empty($invoice->invoice_number) || str_starts_with((string) $invoice->invoice_number, 'TMP-')) {
                $invoice->forceFill([
                    'invoice_number' => sprintf('PIN-%s-%05d', now()->format('Y'), $invoice->id),
                ])->saveQuietly();
            }
        });

        static::deleting(function (PurchaseInvoice $invoice): void {
            if ($invoice->hasPostedGoodsReceipt()) {
                throw new LogicException('Cannot delete a purchase invoice that has already affected inventory.');
            }
        });
    }
}
