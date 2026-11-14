<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Inventory\StockMovementService;
use App\Services\Inventory\StocktakeWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryDocumentWorkflowService
{
    public function __construct(
        protected StockMovementService $stockMovementService,
        protected StocktakeWorkflowService $stocktakeWorkflowService,
    ) {}
    /**
     * Submit a draft or rejected document for approval.
     */
    public function submit(InventoryDocument $document, User $user): InventoryDocument
    {
        return DB::transaction(function () use ($document, $user): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! in_array($locked->status, [InventoryDocumentStatus::DRAFT, InventoryDocumentStatus::REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_submit_status', ['status' => $locked->status->value])],
                ]);
            }

            if ($locked->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => [__('inventory.errors.document_has_no_items')],
                ]);
            }

            $locked->update([
                'status' => InventoryDocumentStatus::PENDING_APPROVAL,
                'reviewed_by' => $user->id,
                'reviewed_at' => Carbon::now(),
            ]);

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'reviewer', 'approver', 'poster']);
        });
    }

    /**
     * Approve a pending document.
     */
    public function approve(InventoryDocument $document, User $user): InventoryDocument
    {
        return DB::transaction(function () use ($document, $user): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== InventoryDocumentStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_approve_status', ['status' => $locked->status->value])],
                ]);
            }

            $locked->update([
                'status' => InventoryDocumentStatus::APPROVED,
                'approved_by' => $user->id,
                'approved_at' => Carbon::now(),
            ]);

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'approver', 'poster']);
        });
    }

    /**
     * Reject a pending document with a mandatory reason.
     */
    public function reject(InventoryDocument $document, User $user, string $reason): InventoryDocument
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => [__('inventory.errors.rejection_reason_required')],
            ]);
        }

        return DB::transaction(function () use ($document, $user, $trimmedReason): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== InventoryDocumentStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_reject_status', ['status' => $locked->status->value])],
                ]);
            }

            $locked->update([
                'status' => InventoryDocumentStatus::REJECTED,
                'rejected_by' => $user->id,
                'rejected_at' => Carbon::now(),
                'rejection_reason' => $trimmedReason,
            ]);

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'rejecter']);
        });
    }

    /**
     * Cancel a draft or pending document.
     */
    public function cancel(InventoryDocument $document, User $user): InventoryDocument
    {
        return DB::transaction(function () use ($document, $user): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (! in_array($locked->status, [InventoryDocumentStatus::DRAFT, InventoryDocumentStatus::PENDING_APPROVAL], true)) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_cancel_status', ['status' => $locked->status->value])],
                ]);
            }

            $locked->update([
                'status' => InventoryDocumentStatus::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => Carbon::now(),
            ]);

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'canceller']);
        });
    }

    /**
     * Post an approved document:
     * Generates all historical stock movements, checks stock availability,
     * updates warehouse stock balances, and marks status as POSTED.
     */
    public function post(InventoryDocument $document, User $user): InventoryDocument
    {
        return DB::transaction(function () use ($document, $user): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== InventoryDocumentStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_post_status', ['status' => $locked->status->value])],
                ]);
            }

            // Create stock movements, lock stocks, and enforce non-negative balances
            $this->stockMovementService->postDocumentMovements($locked, $user->id);

            $locked->update([
                'status' => InventoryDocumentStatus::POSTED,
                'posted_by' => $user->id,
                'posted_at' => Carbon::now(),
            ]);

            // If this document is an adjustment referencing a stocktake, check if all adjustments are posted to complete the stocktake
            if ($locked->referenceable_type && in_array($locked->referenceable_type, ['stocktake', Stocktake::class], true) && $locked->referenceable_id) {
                $this->stocktakeWorkflowService->checkAndComplete((int) $locked->referenceable_id, $user);
            }

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'poster', 'stockMovements']);
        });
    }

    /**
     * Reverse a posted document with a mandatory reason:
     * Generates reversal movements (inverting direction), checks stock availability
     * against inverted movements, updates balances, and marks status as REVERSED.
     */
    public function reverse(InventoryDocument $document, User $user, string $reason): InventoryDocument
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'reversal_reason' => [__('inventory.errors.reversal_reason_required')],
            ]);
        }

        return DB::transaction(function () use ($document, $user, $trimmedReason): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== InventoryDocumentStatus::POSTED) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_reverse_status', ['status' => $locked->status->value])],
                ]);
            }

            // Generate reversal movements, lock stocks, and enforce non-negative balances
            $this->stockMovementService->reverseDocumentMovements($locked, $user->id);

            $locked->update([
                'status' => InventoryDocumentStatus::REVERSED,
                'reversed_by' => $user->id,
                'reversed_at' => Carbon::now(),
                'reversal_reason' => $trimmedReason,
            ]);

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'reverser', 'stockMovements']);
        });
    }
}
