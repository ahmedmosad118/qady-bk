<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use App\Models\User;
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
     * Delete an inventory document (only draft).
     */
    public function delete(InventoryDocument $document): void
    {
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
                'quantity' => $item['quantity'],
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }
}
