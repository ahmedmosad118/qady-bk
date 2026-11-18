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
use App\Support\Decimal;
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

            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()->lockForUpdate()->findOrFail($stocktake->id);

            if ($lockedStocktake->status !== StocktakeStatus::DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["لا يمكن بدء الجرد المخزني وهو في حالة '{$lockedStocktake->status->value}'. يجب أن يكون مسودة."],
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
                    'warehouse_id' => ['يوجد جرد مخزني نشط بالفعل لهذا المخزن لم يتم إغلاقه بعد. لا يمكن تشغيل أكثر من جرد نشط لنفس المخزن في آنٍ واحد.'],
                ]);
            }

            // If FULL scope: auto-populate all catalog products
            if ($lockedStocktake->scope === StocktakeScope::FULL) {
                $allProductIds = Product::query()
                    ->pluck('id')
                    ->all();

                if (empty($allProductIds)) {
                    throw ValidationException::withMessages([
                        'scope' => ['No products available to start a full stocktake.'],
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
                    'items' => ['لا يمكن بدء الجرد بدون إدراج أية منتجات في نطاق الجرد.'],
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
                'reason' => 'بدء تنفيذ الجرد وتثبيت نطاق المنتجات',
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
                    'status' => ["لا يمكن إرسال الجرد للاعتماد وهو في حالة '{$lockedStocktake->status->value}'."],
                ]);
            }

            $totalItems = $lockedStocktake->items()->count();
            if ($totalItems === 0) {
                throw ValidationException::withMessages([
                    'items' => ['مستند الجرد لا يحتوي على أية بنود.'],
                ]);
            }

            // Check if any items are pending or counting
            $uncountedCount = $lockedStocktake->items()
                ->where('count_status', '!=', StocktakeItemStatus::COUNTED)
                ->count();

            if ($uncountedCount > 0) {
                throw ValidationException::withMessages([
                    'items' => ["لا يمكن إرسال الجرد للاعتماد قبل تسجيل الكمية الفعلية لجميع المنتجات. متبقي {$uncountedCount} منتج لم يكتمل عده."],
                ]);
            }

            // Check for null counted quantities
            $nullCount = $lockedStocktake->items()
                ->whereNull('counted_quantity')
                ->count();

            if ($nullCount > 0) {
                throw ValidationException::withMessages([
                    'items' => ['توجد منتجات لم يتم حفظ كميتها الفعلية.'],
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
                'reason' => 'إرسال الجرد للاعتماد بعد اكتمال عد جميع الأصناف',
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
                    'status' => ["لا يمكن اعتماد الجرد وهو في حالة '{$lockedStocktake->status->value}'. يجب أن يكون قيد الاعتماد."],
                ]);
            }

            $items = $lockedStocktake->items()->get();

            $increases = [];
            $decreases = [];

            foreach ($items as $item) {
                $variance = $item->getVariance();
                if ($variance === null) {
                    throw ValidationException::withMessages([
                        'items' => ["المنتج '{$item->product?->name}' ليس له فارق محسوب."],
                    ]);
                }

                $cmp = Decimal::comp($variance, '0', Decimal::QUANTITY_SCALE);
                if ($cmp > 0) {
                    $increases[] = [
                        'product_id' => $item->product_id,
                        'quantity' => $variance,
                        'notes' => "Stocktake variance increase: {$item->product?->name}",
                    ];
                } elseif ($cmp < 0) {
                    $absVariance = Decimal::mul($variance, '-1', Decimal::QUANTITY_SCALE);
                    $decreases[] = [
                        'product_id' => $item->product_id,
                        'quantity' => $absVariance,
                        'notes' => "Stocktake variance decrease: {$item->product?->name}",
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
                    'reason' => 'اعتماد الجرد واكتماله فوراً لعدم وجود أية فروقات مخزنية',
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
                    'notes' => "تسوية زيادة ناتجة عن الجرد المخزني رقم {$lockedStocktake->stocktake_number}",
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
                    'notes' => "تسوية عجز/نقص ناتجة عن الجرد المخزني رقم {$lockedStocktake->stocktake_number}",
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
                'reason' => 'اعتماد نتائج الجرد وتوليد مسودات التسوية المخزنية بانتظار ترحيلها',
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
                'rejection_reason' => ['سبب رفض الجرد إجباري.'],
            ]);
        }

        return DB::transaction(function () use ($stocktake, $user, $trimmedReason): Stocktake {
            /** @var Stocktake $lockedStocktake */
            $lockedStocktake = Stocktake::query()
                ->lockForUpdate()
                ->findOrFail($stocktake->id);

            if ($lockedStocktake->status !== StocktakeStatus::PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => ["لا يمكن رفض الجرد وهو في حالة '{$lockedStocktake->status->value}'."],
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
                    'status' => ["لا يمكن إلغاء الجرد المخزني وهو في حالة '{$lockedStocktake->status->value}'."],
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
                'reason' => $reason ?? 'إلغاء مستند الجرد وتحرير أية أقفال جارية',
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
                'reason' => 'اكتمال ترحيل جميع مستندات التسوية المخزنية الناتجة بنجاح',
                'user_id' => $user->id,
            ]);

            return true;
        });
    }
}
