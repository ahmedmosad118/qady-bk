<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use LogicException;

class InventoryDocumentObserver
{
    /**
     * Guard against direct status updates to POSTED that bypass InventoryDocumentWorkflowService::post()
     * and StockMovementService::postDocumentMovements().
     */
    public function updating(InventoryDocument $document): void
    {
        if (! $document->isDirty('status') || $document->status !== InventoryDocumentStatus::POSTED) {
            return;
        }

        if ($document->relationLoaded('stockMovements') && $document->getRelation('stockMovements')->isNotEmpty()) {
            return;
        }

        if (! $document->stockMovements()->whereNull('reversal_of_id')->exists()) {
            throw new LogicException(
                "لا يمكن تغيير حالة المستند {$document->document_number} إلى مرحّل مباشرة دون تسجيل حركات المخزون عبر خدمة ترحيل المستندات."
            );
        }
    }
}
