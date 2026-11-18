<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PurchaseInvoiceStatus;
use App\Models\InventoryDocument;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Support\Decimal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryDocumentService
{
    public function __construct(
        protected InventoryDocumentNumberService $numberService,
    ) {}

    /**
     * Create a new inventory document with its items.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $user): InventoryDocument
    {
        return DB::transaction(function () use ($data, $user): InventoryDocument {
            $docType = $data['document_type'];
            $docTypeValue = $docType instanceof InventoryDocumentType ? $docType->value : (string) $docType;
            $refType = $data['referenceable_type'] ?? null;
            $refId = isset($data['referenceable_id']) ? (int) $data['referenceable_id'] : null;

            if ($this->isPurchaseInvoiceReference($refType) && $docTypeValue === InventoryDocumentType::GOODS_RECEIPT->value && $refId) {
                $invoice = PurchaseInvoice::query()->lockForUpdate()->find($refId);
                if (! $invoice) {
                    throw ValidationException::withMessages([
                        'referenceable_id' => ['Referenced purchase invoice does not exist.'],
                    ]);
                }

                if ($invoice->status === PurchaseInvoiceStatus::CANCELLED) {
                    throw ValidationException::withMessages([
                        'referenceable_id' => ['Cannot create a goods receipt for a cancelled purchase invoice.'],
                    ]);
                }

                $alreadyExists = InventoryDocument::query()
                    ->whereIn('referenceable_type', ['purchase_invoice', PurchaseInvoice::class])
                    ->where('referenceable_id', $refId)
                    ->where('document_type', InventoryDocumentType::GOODS_RECEIPT->value)
                    ->where('status', '!=', InventoryDocumentStatus::CANCELLED->value)
                    ->exists();

                if ($alreadyExists) {
                    throw ValidationException::withMessages([
                        'referenceable_id' => ['A goods receipt is already linked to this purchase invoice.'],
                    ]);
                }
            }

            $docDate = isset($data['document_date']) ? Carbon::parse($data['document_date']) : Carbon::now();
            $docNumber = $this->numberService->generate($docType, $docDate);

            /** @var InventoryDocument $document */
            $document = InventoryDocument::query()->create([
                'document_number' => $docNumber,
                'document_type' => $docType,
                'status' => InventoryDocumentStatus::DRAFT,
                'document_date' => $docDate->toDateString(),
                'source_warehouse_id' => $data['source_warehouse_id'] ?? null,
                'destination_warehouse_id' => $data['destination_warehouse_id'] ?? null,
                'adjustment_direction' => $data['adjustment_direction'] ?? null,
                'return_direction' => $data['return_direction'] ?? null,
                'notes' => $data['notes'] ?? null,
                'write_off_reason' => $data['write_off_reason'] ?? null,
                'referenceable_type' => $data['referenceable_type'] ?? null,
                'referenceable_id' => $data['referenceable_id'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->syncItems($document, $data['items'] ?? []);

            return $document->load(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator']);
        });
    }

    /**
     * Update an existing draft or rejected inventory document and its items.
     *
     * @param array<string, mixed> $data
     */
    public function update(InventoryDocument $document, array $data, User $user): InventoryDocument
    {
        if ($this->isPurchaseInvoiceReference($document->referenceable_type)) {
            throw ValidationException::withMessages([
                'referenceable' => ['Cannot update a goods receipt linked to a purchase invoice directly; update the purchase invoice instead.'],
            ]);
        }

        if (! $document->isEditable()) {
            throw ValidationException::withMessages([
                'status' => [__('inventory.errors.cannot_edit_non_draft_document', [
                    'status' => $document->status->value,
                ])],
            ]);
        }

        return DB::transaction(function () use ($document, $data): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            $updatePayload = [
                'document_type' => $data['document_type'] ?? $locked->document_type,
                'document_date' => isset($data['document_date']) ? Carbon::parse($data['document_date'])->toDateString() : $locked->document_date,
                'source_warehouse_id' => $data['source_warehouse_id'] ?? null,
                'destination_warehouse_id' => $data['destination_warehouse_id'] ?? null,
                'adjustment_direction' => $data['adjustment_direction'] ?? null,
                'return_direction' => $data['return_direction'] ?? null,
                'notes' => $data['notes'] ?? null,
                'write_off_reason' => $data['write_off_reason'] ?? null,
                'referenceable_type' => $data['referenceable_type'] ?? null,
                'referenceable_id' => $data['referenceable_id'] ?? null,
            ];

            $locked->update($updatePayload);

            if (isset($data['items'])) {
                $this->syncItems($locked, $data['items']);
            }

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator']);
        });
    }

    /**
     * Synchronize a Goods Receipt document with its parent Purchase Invoice inside a transaction.
     */
    public function syncForPurchaseInvoice(InventoryDocument $document, PurchaseInvoice $invoice, int $warehouseId): InventoryDocument
    {
        return DB::transaction(function () use ($document, $invoice, $warehouseId): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (in_array($locked->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true)
                || $locked->stockMovements()->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot update a purchase invoice after its linked goods receipt is posted.'],
                ]);
            }

            if ($locked->status === InventoryDocumentStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot synchronize a goods receipt that is already cancelled.'],
                ]);
            }

            $locked->update([
                'status' => InventoryDocumentStatus::DRAFT,
                'document_date' => Carbon::parse($invoice->invoice_date)->toDateString(),
                'destination_warehouse_id' => $warehouseId,
                'notes' => "Automatic goods receipt for purchase invoice: {$invoice->invoice_number}",
                'reviewed_by' => null,
                'reviewed_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            $itemsData = $invoice->items()->get()->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'notes' => null,
            ])->all();

            $this->syncItems($locked, $itemsData);

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator']);
        });
    }

    /**
     * Delete an inventory document (only draft).
     */
    public function delete(InventoryDocument $document): void
    {
        if ($this->isPurchaseInvoiceReference($document->referenceable_type)) {
            throw ValidationException::withMessages([
                'referenceable' => ['Cannot delete a goods receipt linked to a purchase invoice directly.'],
            ]);
        }

        if ($document->status !== InventoryDocumentStatus::DRAFT) {
            throw ValidationException::withMessages([
                'status' => [__('inventory.errors.cannot_delete_non_draft')],
            ]);
        }

        DB::transaction(function () use ($document): void {
            $document->items()->delete();
            $document->delete();
        });
    }

    /**
     * Delete a linked Goods Receipt when its Draft Purchase Invoice is deleted.
     */
    public function deleteForPurchaseInvoice(InventoryDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (in_array($locked->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true)
                || $locked->stockMovements()->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot delete a purchase invoice after its linked goods receipt is posted.'],
                ]);
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }

    /**
     * Determine whether the given morph type refers to PurchaseInvoice.
     */
    protected function isPurchaseInvoiceReference(?string $referenceableType): bool
    {
        return in_array($referenceableType, ['purchase_invoice', PurchaseInvoice::class], true);
    }

    /**
     * Sync items on document.
     *
     * @param array<int, array<string, mixed>> $itemsData
     */
    protected function syncItems(InventoryDocument $document, array $itemsData): void
    {
        $document->items()->delete();

        foreach ($itemsData as $item) {
            $document->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => Decimal::fromNumeric($item['quantity'], Decimal::QUANTITY_SCALE),
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }
}
