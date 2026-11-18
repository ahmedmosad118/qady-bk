<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use App\Services\InventoryDocumentWorkflowService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Pure validation guard for InventoryDocument lifecycle integrity.
 *
 * Never mutates models or posts stock movements; verifies that protected transitions
 * (`POSTED` / `REVERSED`) occur strictly within an active InventoryDocumentWorkflowService
 * transaction backed by persisted stock_movements rows.
 */
class InventoryDocumentObserver
{
    /**
     * Re-entrancy guard keyed by document ID to prevent recursive observer execution.
     *
     * @var array<int, true>
     */
    private static array $validatingIds = [];

    /**
     * Block direct document creation in POSTED or REVERSED status.
     */
    public function creating(InventoryDocument $document): void
    {
        if (in_array($document->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true)) {
            throw new LogicException(
                "Cannot create inventory document {$document->document_number} directly with status '{$document->status->value}'."
            );
        }
    }

    /**
     * Guard against direct status updates or illegal lifecycle jumps that bypass
     * InventoryDocumentWorkflowService::post() / reverse().
     *
     * Intentionally ignores in-memory loaded relations so loaded and unloaded
     * model instances behave identically against authoritative database state.
     */
    public function updating(InventoryDocument $document): void
    {
        if (! $document->isDirty('status')) {
            return;
        }

        $docId = (int) $document->id;
        if ($docId > 0 && isset(self::$validatingIds[$docId])) {
            return;
        }

        if ($docId > 0) {
            self::$validatingIds[$docId] = true;
        }

        try {
            $rawOriginal = $document->getRawOriginal('status');
            $originalStatus = $rawOriginal instanceof InventoryDocumentStatus
                ? $rawOriginal->value
                : (string) $rawOriginal;

            $targetStatus = $document->status;

            if (in_array($originalStatus, [InventoryDocumentStatus::POSTED->value, InventoryDocumentStatus::REVERSED->value], true)) {
                $allowedReversal = $originalStatus === InventoryDocumentStatus::POSTED->value
                    && $targetStatus === InventoryDocumentStatus::REVERSED;

                if (! $allowedReversal) {
                    throw new LogicException(
                        "Cannot change document {$document->document_number} status from '{$originalStatus}' to '{$targetStatus->value}'."
                    );
                }
            }

            if ($targetStatus === InventoryDocumentStatus::POSTED) {
                $this->assertWorkflowBackedTransition(
                    $document,
                    $docId,
                    $originalStatus,
                    InventoryDocumentStatus::APPROVED,
                    InventoryDocumentStatus::POSTED,
                    isReversal: false
                );
            } elseif ($targetStatus === InventoryDocumentStatus::REVERSED) {
                $this->assertWorkflowBackedTransition(
                    $document,
                    $docId,
                    $originalStatus,
                    InventoryDocumentStatus::POSTED,
                    InventoryDocumentStatus::REVERSED,
                    isReversal: true
                );
            }
        } finally {
            if ($docId > 0) {
                unset(self::$validatingIds[$docId]);
            }
        }
    }

    protected function assertWorkflowBackedTransition(
        InventoryDocument $document,
        int $docId,
        string $originalStatus,
        InventoryDocumentStatus $requiredFromStatus,
        InventoryDocumentStatus $targetStatus,
        bool $isReversal
    ): void {
        if ($originalStatus !== $requiredFromStatus->value) {
            throw new LogicException(
                "Cannot transition document {$document->document_number} to '{$targetStatus->value}' from '{$originalStatus}'."
            );
        }

        $hasMovements = $isReversal
            ? $document->stockMovements()->whereNotNull('reversal_of_id')->exists()
            : $document->stockMovements()->whereNull('reversal_of_id')->exists();

        if (
            DB::transactionLevel() < 1
            || ! InventoryDocumentWorkflowService::isAuthoritativeTransitionActive($docId, $targetStatus)
            || ! $hasMovements
        ) {
            $action = $isReversal ? 'reverse' : 'post';
            throw new LogicException(
                "Cannot {$action} document {$document->document_number} directly outside InventoryDocumentWorkflowService."
            );
        }
    }
}
