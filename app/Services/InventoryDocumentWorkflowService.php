<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InventoryDocumentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Models\InventoryDocument;
use App\Models\PurchaseInvoice;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Inventory\StockMovementService;
use App\Services\Inventory\StocktakeWorkflowService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class InventoryDocumentWorkflowService
{
    /**
     * Tracks document IDs currently executing an authoritative post/reverse lifecycle transition
     * so observers and movement services enforce a single entry point.
     *
     * @var array<int, InventoryDocumentStatus>
     */
    private static array $activeTransitions = [];

    public function __construct(
        protected StockMovementService $stockMovementService,
        protected StocktakeWorkflowService $stocktakeWorkflowService,
    ) {}

    /**
     * Determine whether the given document is currently transitioning via this service.
     */
    public static function isAuthoritativeTransitionActive(int $documentId, InventoryDocumentStatus $targetStatus): bool
    {
        return (self::$activeTransitions[$documentId] ?? null) === $targetStatus;
    }

    /**
     * Execute an authoritative status transition callback inside a scoped lifecycle guard.
     *
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    protected function runAuthoritativeTransition(int $documentId, InventoryDocumentStatus $targetStatus, callable $callback): mixed
    {
        if (isset(self::$activeTransitions[$documentId])) {
            throw new LogicException("Authoritative workflow transition is already in progress for document #{$documentId}.");
        }

        self::$activeTransitions[$documentId] = $targetStatus;

        try {
            return $callback();
        } finally {
            unset(self::$activeTransitions[$documentId]);
        }
    }
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

            if (in_array($locked->referenceable_type, ['purchase_invoice', PurchaseInvoice::class], true)) {
                $invoice = PurchaseInvoice::query()->find($locked->referenceable_id);
                if ($invoice && $invoice->status !== PurchaseInvoiceStatus::CANCELLED) {
                    throw ValidationException::withMessages([
                        'referenceable' => ['Cannot cancel a goods receipt linked to a purchase invoice directly; cancel the purchase invoice instead.'],
                    ]);
                }
            }

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
     * Cancel a Goods Receipt linked to a Purchase Invoice being cancelled.
     */
    public function cancelForPurchaseInvoice(InventoryDocument $document, User $user): InventoryDocument
    {
        return DB::transaction(function () use ($document, $user): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (in_array($locked->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true)
                || $locked->stockMovements()->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot cancel a purchase invoice after its linked goods receipt is posted.'],
                ]);
            }

            if ($locked->status === InventoryDocumentStatus::CANCELLED) {
                return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'canceller']);
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
    public function post(InventoryDocument $document, User $user, bool $loadRelations = true): InventoryDocument
    {
        return DB::transaction(function () use ($document, $user, $loadRelations): InventoryDocument {
            /** @var InventoryDocument $locked */
            $locked = InventoryDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($locked->status !== InventoryDocumentStatus::APPROVED) {
                throw ValidationException::withMessages([
                    'status' => [__('inventory.errors.cannot_post_status', ['status' => $locked->status->value])],
                ]);
            }

            $this->runAuthoritativeTransition($locked->id, InventoryDocumentStatus::POSTED, function () use ($locked, $user): void {
                // Create stock movements, lock stocks, and enforce non-negative balances
                $this->stockMovementService->postDocumentMovements($locked, $user->id);

                $locked->update([
                    'status' => InventoryDocumentStatus::POSTED,
                    'posted_by' => $user->id,
                    'posted_at' => Carbon::now(),
                ]);
            });

            // If this document is an adjustment referencing a stocktake, check if all adjustments are posted to complete the stocktake
            if ($locked->referenceable_type && in_array($locked->referenceable_type, ['stocktake', Stocktake::class], true) && $locked->referenceable_id) {
                $this->stocktakeWorkflowService->checkAndComplete((int) $locked->referenceable_id, $user);
            }

            if (! $loadRelations) {
                return $locked;
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

            $this->runAuthoritativeTransition($locked->id, InventoryDocumentStatus::REVERSED, function () use ($locked, $user, $trimmedReason): void {
                // Generate reversal movements, lock stocks, and enforce non-negative balances
                $this->stockMovementService->reverseDocumentMovements($locked, $user->id);

                $locked->update([
                    'status' => InventoryDocumentStatus::REVERSED,
                    'reversed_by' => $user->id,
                    'reversed_at' => Carbon::now(),
                    'reversal_reason' => $trimmedReason,
                ]);
            });

            return $locked->fresh(['items.product', 'sourceWarehouse', 'destinationWarehouse', 'creator', 'reverser', 'stockMovements']);
        });
    }
}
