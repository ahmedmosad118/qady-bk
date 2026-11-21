<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use LogicException;

class InventoryDocumentObserver
{
    /**
     * Block direct document creation in POSTED or REVERSED status.
     */
    public function creating(InventoryDocument $document): void
    {
        if (in_array($document->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true)) {
            throw new LogicException(
                "لا يمكن إنشاء المستند {$document->document_number} مباشرة بحالة مرحّل أو معكوس."
            );
        }
    }

    /**
     * Guard against direct status updates to POSTED that bypass InventoryDocumentWorkflowService::post()
     * and StockMovementService::postDocumentMovements(), and protect structural fields on POSTED/REVERSED documents.
     */
    public function updating(InventoryDocument $document): void
    {
        $rawOriginal = $document->getRawOriginal('status');
        $originalStatus = $rawOriginal instanceof InventoryDocumentStatus
            ? $rawOriginal->value
            : (string) $rawOriginal;

        if (in_array($originalStatus, [InventoryDocumentStatus::POSTED->value, InventoryDocumentStatus::REVERSED->value], true)) {
            $structuralFields = [
                'document_type',
                'source_warehouse_id',
                'destination_warehouse_id',
                'adjustment_direction',
                'return_direction',
            ];

            if ($document->isDirty($structuralFields)) {
                throw new LogicException(
                    "لا يمكن تعديل البيانات الأساسية للمستند المرحّل أو المعكوس {$document->document_number}."
                );
            }
        }

        if (! $document->isDirty('status') || $document->status !== InventoryDocumentStatus::POSTED) {
            return;
        }

        if (! $document->stockMovements()->whereNull('reversal_of_id')->exists()) {
            throw new LogicException(
                "لا يمكن تغيير حالة المستند {$document->document_number} إلى مرحّل مباشرة دون تسجيل حركات المخزون عبر خدمة ترحيل المستندات."
            );
        }
    }

    /**
     * Block deleting POSTED or REVERSED documents.
     */
    public function deleting(InventoryDocument $document): void
    {
        $rawOriginal = $document->getRawOriginal('status');
        $originalStatus = $rawOriginal instanceof InventoryDocumentStatus
            ? $rawOriginal->value
            : (string) $rawOriginal;

        if (
            in_array($document->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true)
            || in_array($originalStatus, [InventoryDocumentStatus::POSTED->value, InventoryDocumentStatus::REVERSED->value], true)
        ) {
            throw new LogicException(
                "لا يمكن حذف المستند المرحّل أو المعكوس {$document->document_number}."
            );
        }
    }
}
