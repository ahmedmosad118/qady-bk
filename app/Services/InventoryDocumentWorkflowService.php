<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentStatus;
use App\Models\InventoryDocument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryDocumentWorkflowService
{
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
     * Post an approved document.
     * Note: In this phase, posting sets status to POSTED and records poster/timestamp.
     * Does NOT update inventory balance or stock movements (prepared extension point).
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

            $locked->update([
                'status' => InventoryDocumentStatus::POSTED,
                'posted_by' => $user->id,
                'posted_at' => Carbon::now(),
            ]);

            // Extension point for future Stock Movements:
            // event(new \App\Events\InventoryDocumentPosted($locked));

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'poster']);
        });
    }

    /**
     * Reverse a posted document with a mandatory reason.
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

            $locked->update([
                'status' => InventoryDocumentStatus::REVERSED,
                'reversed_by' => $user->id,
                'reversed_at' => Carbon::now(),
                'reversal_reason' => $trimmedReason,
            ]);

            // Extension point for future Stock Movement reversals:
            // event(new \App\Events\InventoryDocumentReversed($locked));

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'reverser']);
        });
    }
}
