<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\Auth;

class InventoryDocumentObserver
{
    public function __construct(
        protected StockMovementService $stockMovementService,
    ) {}

    /**
     * Handle the InventoryDocument "updated" event.
     * Ensures idempotent stock movements posting when document transitions to POSTED.
     */
    public function updated(InventoryDocument $document): void
    {
        if ($document->wasChanged('status') && $document->status === InventoryDocumentStatus::POSTED) {
            // Idempotency: If movements have already been posted by posting service, do not duplicate
            if ($document->stockMovements()->exists()) {
                return;
            }

            $userId = (int) ($document->posted_by ?? Auth::id() ?? $document->created_by ?? 1);

            // Ensure document status allows movement creation
            $originalStatus = $document->status;
            $document->status = InventoryDocumentStatus::APPROVED;

            try {
                $this->stockMovementService->postDocumentMovements($document, $userId);
            } finally {
                $document->status = $originalStatus;
            }
        }
    }
}
