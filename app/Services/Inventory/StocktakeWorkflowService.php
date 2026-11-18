<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\StockAdjustmentDirection;
use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeScope;
use App\Enums\StocktakeStatus;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\StocktakeItemHistory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryDocumentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StocktakeWorkflowService
{
    public function __construct(
        protected InventoryDocumentService $documentService,
    ) {}

    /**
     * Start a draft stocktake:
     * 1. Locks the warehouse row to prevent concurrent stocktakes.
     * 2. Checks no other active stocktake exists for this warehouse.
     * 3. For FULL scope: discovers all active products + inactive products with non-zero stock,
     *    and bulk inserts items.
     * 4. Ensures at least 1 item is in the stocktake.
     * 5. Sets status to IN_PROGRESS.
     */
    public function start(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user): Stocktake {
            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($stocktake->warehouse_id);

            if (! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse' => [(string) __('stocktakes.errors.warehouse_inactive_start', ['name' => $warehouse->name])],
                ]);
            }

            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);

            if ($lockedStocktake->status !== StocktakeStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('stocktakes.errors.start_status', ['status' => $lockedStocktake->status->value])],
                ]);
            }

            // Ensure no other active stocktake exists for this warehouse
            $otherActive = Stocktake::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('id', '!=', $lockedStocktake->id)
                ->active()
                ->exists();

            if ($otherActive) {
                throw ValidationException::withMessages([
                    'warehouse_id' => [(string) __('stocktakes.errors.active_stocktake_exists')],
                ]);
            }

            // If FULL scope: auto-populate all in-scope products
            if ($lockedStocktake->scope === StocktakeScope::FULL) {
                // Active products
                $activeProductIds = Product::query()
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all();

                // Inactive products that have non-zero stock in this warehouse
                $inactiveWithStockIds = Product::query()
                    ->where('is_active', false)
                    ->whereHas('warehouseStocks', function ($q) use ($warehouse) {
                        $q->where('warehouse_id', $warehouse->id)
                            ->where('quantity', '!=', 0);
                    })
                    ->pluck('id')
                    ->all();

                $allProductIds = array_values(array_unique(array_merge($activeProductIds, $inactiveWithStockIds)));

                if (empty($allProductIds)) {
                    throw ValidationException::withMessages([
                        'scope' => [(string) __('stocktakes.errors.scope_empty')],
                    ]);
                }

                // Check which are already in items
                $existingProductIds = $lockedStocktake->items()->pluck('product_id')->all();
                $toInsert = array_diff($allProductIds, $existingProductIds);

                if (! empty($toInsert)) {
                    $now = Carbon::now();
                    $insertRows = array_map(fn ($prodId) => [
                        'stocktake_id' => $lockedStocktake->id,
                        'product_id' => $prodId,
                        'system_quantity' => null,
                        'counted_quantity' => null,
                        'count_status' => StocktakeItemStatus::PENDING->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $toInsert);

                    StocktakeItem::query()->insert($insertRows);
                }
            }

            // Validate that stocktake has items
            if ($lockedStocktake->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('stocktakes.errors.no_items_in_scope')],
                ]);
            }

            $lockedStocktake->update([
                'status' => StocktakeStatus::IN_PROGRESS,
                'started_by' => $user->id,
                'started_at' => Carbon::now(),
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedStocktake->id,
                'stocktake_item_id' => null,
                'action' => 'started',
                'previous_status' => StocktakeStatus::DRAFT->value,
                'new_status' => StocktakeStatus::IN_PROGRESS->value,
                'reason' => (string) __('stocktakes.history.started'),
                'user_id' => $user->id,
            ]);

            return $lockedStocktake->fresh(['warehouse', 'starter', 'items.product']);
        });
    }

    /**
     * Submit an in-progress or rejected stocktake for approval:
     * 1. Requires status to be in_progress or rejected.
     * 2. Verifies ALL items are in COUNTED status with non-null quantities.
     * 3. Sets status to PENDING_APPROVAL.
     */
    public function submit(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user): Stocktake {
            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()
                ->lockForUpdate()
                ->findOrFail($stocktake->id);

            if (! in_array($lockedStocktake->status, [StocktakeStatus::IN_PROGRESS, StocktakeStatus::REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('stocktakes.errors.submit_status', ['status' => $lockedStocktake->status->value])],
                ]);
            }

            $totalItems = $lockedStocktake->items()->count();
            if ($totalItems === 0) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('stocktakes.errors.no_items')],
                ]);
            }

            // Check if any items are pending or counting
            $uncountedCount = $lockedStocktake->items()
                ->where('count_status', '!=', StocktakeItemStatus::COUNTED)
                ->count();

            if ($uncountedCount > 0) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('stocktakes.errors.uncounted_remaining', ['count' => $uncountedCount])],
                ]);
            }

            // Check for null counted quantities
            $nullCount = $lockedStocktake->items()
                ->whereNull('counted_quantity')
                ->count();

            if ($nullCount > 0) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('stocktakes.errors.unsaved_counts')],
                ]);
            }

            $previousStatus = $lockedStocktake->status->value;

            $lockedStocktake->update([
                'status' => StocktakeStatus::PENDING_APPROVAL,
                'submitted_by' => $user->id,
                'submitted_at' => Carbon::now(),
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedStocktake->id,
                'stocktake_item_id' => null,
                'action' => 'submitted',
                'previous_status' => $previousStatus,
                'new_status' => StocktakeStatus::PENDING_APPROVAL->value,
                'reason' => (string) __('stocktakes.history.submitted'),
                'user_id' => $user->id,
            ]);

            return $lockedStocktake->fresh(['warehouse', 'submitter', 'items.product']);
        });
    }

    /**
     * Approve a pending stocktake:
     * 1. Calculates variances (counted - system).
     * 2. If NO variances exist anywhere: transitions directly to COMPLETED.
     * 3. If positive variances exist: creates draft Stock Adjustment (increase).
     * 4. If negative variances exist: creates draft Stock Adjustment (decrease).
     * 5. Sets status to APPROVED (awaiting adjustment posting).
     */
    public function approve(Stocktake $stocktake, User $user): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user): Stocktake {
            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()
                ->lockForUpdate()
                ->findOrFail($stocktake->id);

            if ($lockedStocktake->status !== StocktakeStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('stocktakes.errors.approve_status', ['status' => $lockedStocktake->status->value])],
                ]);
            }

            $items = $lockedStocktake->items()->get();

            $increases = [];
            $decreases = [];

            foreach ($items as $item) {
                $variance = $item->getVariance();
                if ($variance === null) {
                    throw ValidationException::withMessages([
                        'items' => [(string) __('stocktakes.errors.no_variance', ['name' => (string) $item->product?->name])],
                    ]);
                }

                $cmp = bccomp($variance, '0.000', 3);
                if ($cmp > 0) {
                    $increases[] = [
                        'product_id' => $item->product_id,
                        'quantity' => (float) $variance,
                        'notes' => (string) __('stocktakes.notes.surplus_item', ['name' => (string) $item->product?->name]),
                    ];
                } elseif ($cmp < 0) {
                    $absVariance = bcmul($variance, '-1', 3);
                    $decreases[] = [
                        'product_id' => $item->product_id,
                        'quantity' => (float) $absVariance,
                        'notes' => (string) __('stocktakes.notes.shortage_item', ['name' => (string) $item->product?->name]),
                    ];
                }
            }

            $now = Carbon::now();

            // Scenario A: No variances exist at all
            if (empty($increases) && empty($decreases)) {
                $lockedStocktake->update([
                    'status' => StocktakeStatus::COMPLETED,
                    'approved_by' => $user->id,
                    'approved_at' => $now,
                    'completed_by' => $user->id,
                    'completed_at' => $now,
                ]);

                StocktakeItemHistory::query()->create([
                    'stocktake_id' => $lockedStocktake->id,
                    'stocktake_item_id' => null,
                    'action' => 'approved_and_completed',
                    'previous_status' => StocktakeStatus::PENDING_APPROVAL->value,
                    'new_status' => StocktakeStatus::COMPLETED->value,
                    'reason' => (string) __('stocktakes.history.approved_completed'),
                    'user_id' => $user->id,
                ]);

                return $lockedStocktake->fresh(['warehouse', 'approver', 'completer', 'inventoryDocuments']);
            }

            // Scenario B: Variances exist, create draft adjustment documents linked to stocktake
            // 1. Positive adjustments (Increase)
            if (! empty($increases)) {
                $this->documentService->create([
                    'document_type' => InventoryDocumentType::STOCK_ADJUSTMENT->value,
                    'document_date' => $lockedStocktake->stocktake_date->toDateString(),
                    'destination_warehouse_id' => $lockedStocktake->warehouse_id,
                    'source_warehouse_id' => null,
                    'adjustment_direction' => StockAdjustmentDirection::INCREASE->value,
                    'referenceable_type' => 'stocktake',
                    'referenceable_id' => $lockedStocktake->id,
                    'notes' => (string) __('stocktakes.notes.surplus_document', ['number' => $lockedStocktake->stocktake_number]),
                    'items' => $increases,
                ], $user);
            }

            // 2. Negative adjustments (Decrease)
            if (! empty($decreases)) {
                $this->documentService->create([
                    'document_type' => InventoryDocumentType::STOCK_ADJUSTMENT->value,
                    'document_date' => $lockedStocktake->stocktake_date->toDateString(),
                    'source_warehouse_id' => $lockedStocktake->warehouse_id,
                    'destination_warehouse_id' => null,
                    'adjustment_direction' => StockAdjustmentDirection::DECREASE->value,
                    'referenceable_type' => 'stocktake',
                    'referenceable_id' => $lockedStocktake->id,
                    'notes' => (string) __('stocktakes.notes.shortage_document', ['number' => $lockedStocktake->stocktake_number]),
                    'items' => $decreases,
                ], $user);
            }

            $lockedStocktake->update([
                'status' => StocktakeStatus::APPROVED,
                'approved_by' => $user->id,
                'approved_at' => $now,
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedStocktake->id,
                'stocktake_item_id' => null,
                'action' => 'approved',
                'previous_status' => StocktakeStatus::PENDING_APPROVAL->value,
                'new_status' => StocktakeStatus::APPROVED->value,
                'reason' => (string) __('stocktakes.history.approved'),
                'user_id' => $user->id,
            ]);

            return $lockedStocktake->fresh(['warehouse', 'approver', 'inventoryDocuments']);
        });
    }

    /**
     * Reject a pending stocktake:
     * Requires mandatory rejection reason.
     */
    public function reject(Stocktake $stocktake, User $user, string $reason): Stocktake
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => [(string) __('stocktakes.errors.reject_reason_required')],
            ]);
        }

        return DB::transaction(function () use ($stocktake, $user, $trimmedReason): Stocktake {
            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()
                ->lockForUpdate()
                ->findOrFail($stocktake->id);

            if ($lockedStocktake->status !== StocktakeStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('stocktakes.errors.reject_status', ['status' => $lockedStocktake->status->value])],
                ]);
            }

            $previousStatus = $lockedStocktake->status->value;

            $lockedStocktake->update([
                'status' => StocktakeStatus::REJECTED,
                'rejected_by' => $user->id,
                'rejected_at' => Carbon::now(),
                'rejection_reason' => $trimmedReason,
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedStocktake->id,
                'stocktake_item_id' => null,
                'action' => 'rejected',
                'previous_status' => $previousStatus,
                'new_status' => StocktakeStatus::REJECTED->value,
                'reason' => $trimmedReason,
                'user_id' => $user->id,
            ]);

            return $lockedStocktake->fresh(['warehouse', 'rejecter']);
        });
    }

    /**
     * Cancel a stocktake (allowed for draft, in_progress, pending_approval):
     * If items are in COUNTING state, unlocks them by setting status back to pending.
     */
    public function cancel(Stocktake $stocktake, User $user, ?string $reason = null): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $user, $reason): Stocktake {
            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()
                ->lockForUpdate()
                ->findOrFail($stocktake->id);

            if (! in_array($lockedStocktake->status, [
                StocktakeStatus::DRAFT,
                StocktakeStatus::IN_PROGRESS,
                StocktakeStatus::PENDING_APPROVAL,
                StocktakeStatus::REJECTED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('stocktakes.errors.cancel_status', ['status' => $lockedStocktake->status->value])],
                ]);
            }

            // Release any items currently locked in counting
            $countingItems = $lockedStocktake->items()
                ->where('count_status', StocktakeItemStatus::COUNTING)
                ->get();

            foreach ($countingItems as $item) {
                $item->update([
                    'count_status' => StocktakeItemStatus::PENDING,
                    'system_quantity' => null,
                    'count_started_by' => null,
                    'count_started_at' => null,
                ]);
            }

            $previousStatus = $lockedStocktake->status->value;

            $lockedStocktake->update([
                'status' => StocktakeStatus::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => Carbon::now(),
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedStocktake->id,
                'stocktake_item_id' => null,
                'action' => 'cancelled',
                'previous_status' => $previousStatus,
                'new_status' => StocktakeStatus::CANCELLED->value,
                'reason' => $reason ?? (string) __('stocktakes.history.cancelled'),
                'user_id' => $user->id,
            ]);

            return $lockedStocktake->fresh(['warehouse', 'canceller']);
        });
    }

    /**
     * Check if a stocktake should be marked COMPLETED after an adjustment document was posted:
     * If stocktake is APPROVED and ALL linked adjustment documents are POSTED, marks it COMPLETED.
     */
    public function checkAndComplete(int $stocktakeId, User $user): bool
    {
        return DB::transaction(function () use ($stocktakeId, $user): bool {
            /** @var Stocktake|null $stocktake */
            $stocktake = Stocktake::query()
                ->lockForUpdate()
                ->find($stocktakeId);

            if (! $stocktake || $stocktake->status !== StocktakeStatus::APPROVED) {
                return false;
            }

            $docs = $stocktake->inventoryDocuments()->get();
            if ($docs->isEmpty()) {
                return false;
            }

            // All docs must be posted
            $unpostedCount = $docs->where('status', '!=', InventoryDocumentStatus::POSTED)->count();
            if ($unpostedCount > 0) {
                return false;
            }

            $now = Carbon::now();
            $stocktake->update([
                'status' => StocktakeStatus::COMPLETED,
                'completed_by' => $user->id,
                'completed_at' => $now,
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $stocktake->id,
                'stocktake_item_id' => null,
                'action' => 'completed',
                'previous_status' => StocktakeStatus::APPROVED->value,
                'new_status' => StocktakeStatus::COMPLETED->value,
                'reason' => (string) __('stocktakes.history.completed'),
                'user_id' => $user->id,
            ]);

            return true;
        });
    }
}
