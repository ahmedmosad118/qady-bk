<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeScope;
use App\Enums\StocktakeStatus;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\StocktakeItemHistory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class StocktakeService
{
    public function __construct(
        protected StocktakeNumberService $numberService,
    ) {}

    /**
     * Create a new draft stocktake document.
     *
     * @param array<string, mixed> $data
     */
    public function createStocktake(array $data, User $user): Stocktake
    {
        return DB::transaction(function () use ($data, $user): Stocktake {
            $warehouseId = (int) $data['warehouse_id'];

            /** @var Warehouse $warehouse */
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            if (! $warehouse->is_active) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ["المخزن '{$warehouse->name}' غير نشط ولا يمكن إنشاء جرد عليه."],
                ]);
            }

            $scope = is_string($data['scope']) ? StocktakeScope::from($data['scope']) : $data['scope'];
            $stocktakeDate = isset($data['stocktake_date']) ? Carbon::parse($data['stocktake_date']) : Carbon::now();

            $stocktakeNumber = $this->numberService->generate($stocktakeDate);

            $stocktake = Stocktake::query()->create([
                'stocktake_number' => $stocktakeNumber,
                'warehouse_id' => $warehouseId,
                'scope' => $scope,
                'status' => StocktakeStatus::DRAFT,
                'stocktake_date' => $stocktakeDate->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            // If selected scope, validate and attach requested product items immediately
            if ($scope === StocktakeScope::SELECTED) {
                $productIds = $data['product_ids'] ?? [];
                if (empty($productIds)) {
                    throw ValidationException::withMessages([
                        'product_ids' => ['يجب اختيار منتج واحد على الأقل للجرد المحدد.'],
                    ]);
                }

                // Check for duplicates
                if (count($productIds) !== count(array_unique($productIds))) {
                    throw ValidationException::withMessages([
                        'product_ids' => ['لا يمكن تكرار الصنف داخل نفس مستند الجرد.'],
                    ]);
                }

                // Verify all products exist and are active
                $existingCount = Product::query()->whereIn('id', $productIds)->count();
                if ($existingCount !== count($productIds)) {
                    throw ValidationException::withMessages([
                        'product_ids' => ['بعض المنتجات المختارة غير موجودة في النظام.'],
                    ]);
                }

                $now = Carbon::now();
                $rows = array_map(fn ($prodId) => [
                    'stocktake_id' => $stocktake->id,
                    'product_id' => $prodId,
                    'system_quantity' => null,
                    'counted_quantity' => null,
                    'count_status' => StocktakeItemStatus::PENDING->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $productIds);

                StocktakeItem::query()->insert($rows);
            }

            // Record initial history
            StocktakeItemHistory::query()->create([
                'stocktake_id' => $stocktake->id,
                'stocktake_item_id' => null, // null indicates document-level event
                'action' => 'created',
                'new_status' => StocktakeStatus::DRAFT->value,
                'reason' => 'إنشاء مسودة الجرد',
                'user_id' => $user->id,
            ]);

            return $stocktake->fresh(['warehouse', 'creator', 'items.product']);
        });
    }

    /**
     * Start counting a single stocktake item:
     * 1. Locks WarehouseStock row (creates with 0 if absent).
     * 2. Verifies no other active stocktake item is currently counting this product in this warehouse.
     * 3. Captures current stock as system_quantity snapshot.
     * 4. Updates item status to counting, sets count_started_by and count_started_at.
     * 5. Appends to audit history.
     */
    public function startCounting(StocktakeItem $item, User $user): StocktakeItem
    {
        return DB::transaction(function () use ($item, $user): StocktakeItem {
            /** @var StocktakeItem $lockedItem */
            $lockedItem = StocktakeItem::query()
                ->with('stocktake')
                ->lockForUpdate()
                ->findOrFail($item->id);

            $stocktake = $lockedItem->stocktake;

            // Only allow starting count if stocktake is in_progress or rejected (resubmission)
            if (! in_array($stocktake->status, [StocktakeStatus::IN_PROGRESS, StocktakeStatus::REJECTED], true)) {
                throw ValidationException::withMessages([
                    'item' => ["لا يمكن بدء عد البند ومستند الجرد في حالة '{$stocktake->status->value}'."],
                ]);
            }

            if ($lockedItem->count_status === StocktakeItemStatus::COUNTING) {
                throw ValidationException::withMessages([
                    'item' => ['هذا المنتج قيد العد حالياً بالفعل.'],
                ]);
            }

            $warehouseId = $stocktake->warehouse_id;
            $productId = $lockedItem->product_id;

            // Ensure WarehouseStock row exists safely
            $this->ensureStockRowExists($warehouseId, $productId);

            /** @var WarehouseStock $stock */
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            // Check if any other stocktake has this same product in counting status in this warehouse
            $otherCountingExists = StocktakeItem::query()
                ->where('id', '!=', $lockedItem->id)
                ->where('product_id', $productId)
                ->where('count_status', StocktakeItemStatus::COUNTING)
                ->whereHas('stocktake', function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId)
                        ->whereIn('status', [
                            StocktakeStatus::IN_PROGRESS,
                            StocktakeStatus::PENDING_APPROVAL,
                            StocktakeStatus::APPROVED,
                            StocktakeStatus::REJECTED,
                        ]);
                })
                ->exists();

            if ($otherCountingExists) {
                throw ValidationException::withMessages([
                    'item' => ['يوجد جرد نشط آخر يقوم بعد هذا المنتج حالياً داخل نفس المخزن.'],
                ]);
            }

            $previousStatus = $lockedItem->count_status->value;
            $systemSnapshot = (string) $stock->quantity;

            $lockedItem->update([
                'system_quantity' => $systemSnapshot,
                'count_status' => StocktakeItemStatus::COUNTING,
                'count_started_by' => $user->id,
                'count_started_at' => Carbon::now(),
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $stocktake->id,
                'stocktake_item_id' => $lockedItem->id,
                'action' => 'start_counting',
                'previous_status' => $previousStatus,
                'new_status' => StocktakeItemStatus::COUNTING->value,
                'reason' => 'بدء العد والتقاط رصيد النظام وقفل حركة المنتج مؤقتاً',
                'user_id' => $user->id,
            ]);

            return $lockedItem->fresh(['product', 'stocktake', 'countStartedBy']);
        });
    }

    /**
     * Record actual counted physical quantity for an item:
     * 1. Requires item to be in counting status.
     * 2. Validates counted quantity >= 0.
     * 3. Sets counted_quantity, status = counted, counted_by, counted_at.
     * 4. Releases product lock upon transaction commit.
     * 5. Appends to audit history.
     */
    public function recordCount(StocktakeItem $item, User $user, string|float|int $countedQuantity, ?string $notes = null): StocktakeItem
    {
        return DB::transaction(function () use ($item, $user, $countedQuantity, $notes): StocktakeItem {
            /** @var StocktakeItem $lockedItem */
            $lockedItem = StocktakeItem::query()
                ->with('stocktake')
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedItem->count_status !== StocktakeItemStatus::COUNTING) {
                throw ValidationException::withMessages([
                    'item' => ["لا يمكن تسجيل الكمية للبند وهو في حالة '{$lockedItem->count_status->value}'. يجب بدء العد أولاً."],
                ]);
            }

            $qtyStr = number_format((float) $countedQuantity, 3, '.', '');
            if (bccomp($qtyStr, '0.000', 3) < 0) {
                throw ValidationException::withMessages([
                    'counted_quantity' => ['الكمية الفعلية لا يمكن أن تكون سالبة.'],
                ]);
            }

            $previousQuantity = $lockedItem->counted_quantity;
            $previousStatus = $lockedItem->count_status->value;

            $lockedItem->update([
                'counted_quantity' => $qtyStr,
                'count_status' => StocktakeItemStatus::COUNTED,
                'counted_by' => $user->id,
                'counted_at' => Carbon::now(),
                'notes' => $notes ?? $lockedItem->notes,
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedItem->stocktake_id,
                'stocktake_item_id' => $lockedItem->id,
                'action' => 'recorded_count',
                'previous_quantity' => $previousQuantity,
                'new_quantity' => $qtyStr,
                'previous_status' => $previousStatus,
                'new_status' => StocktakeItemStatus::COUNTED->value,
                'reason' => 'تسجيل الكمية الفعلية واكتمال عد المنتج وتحرير القفل',
                'user_id' => $user->id,
            ]);

            return $lockedItem->fresh(['product', 'stocktake', 'countedBy']);
        });
    }

    /**
     * Cancel an ongoing count on an item:
     * 1. Requires item to be in counting status.
     * 2. Resets to pending, nullifies snapshot system_quantity.
     * 3. Releases product lock.
     * 4. Logs audit trail with mandatory reason.
     */
    public function cancelCount(StocktakeItem $item, User $user, string $reason): StocktakeItem
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'reason' => ['سبب إلغاء العد إجباري.'],
            ]);
        }

        return DB::transaction(function () use ($item, $user, $trimmedReason): StocktakeItem {
            /** @var StocktakeItem $lockedItem */
            $lockedItem = StocktakeItem::query()
                ->with('stocktake')
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedItem->count_status !== StocktakeItemStatus::COUNTING) {
                throw ValidationException::withMessages([
                    'item' => ["لا يمكن إلغاء العد للبند وهو في حالة '{$lockedItem->count_status->value}'. يمكن إلغاء العد للبنود الجاري عدها فقط."],
                ]);
            }

            $previousStatus = $lockedItem->count_status->value;

            $lockedItem->update([
                'system_quantity' => null,
                'count_status' => StocktakeItemStatus::PENDING,
                'count_started_by' => null,
                'count_started_at' => null,
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $lockedItem->stocktake_id,
                'stocktake_item_id' => $lockedItem->id,
                'action' => 'cancel_count',
                'previous_status' => $previousStatus,
                'new_status' => StocktakeItemStatus::PENDING->value,
                'reason' => $trimmedReason,
                'user_id' => $user->id,
            ]);

            return $lockedItem->fresh(['product', 'stocktake']);
        });
    }

    /**
     * Recount an item (supported when stocktake is in_progress or rejected):
     * 1. Verifies stocktake is not completed.
     * 2. Captures new fresh system_quantity snapshot.
     * 3. Resets item into counting status.
     * 4. Logs old counted quantity and recount reason in audit trail.
     */
    public function recount(StocktakeItem $item, User $user, string $reason): StocktakeItem
    {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw ValidationException::withMessages([
                'reason' => ['سبب طلب إعادة العد إجباري.'],
            ]);
        }

        return DB::transaction(function () use ($item, $user, $trimmedReason): StocktakeItem {
            /** @var StocktakeItem $lockedItem */
            $lockedItem = StocktakeItem::query()
                ->with('stocktake')
                ->lockForUpdate()
                ->findOrFail($item->id);

            $stocktake = $lockedItem->stocktake;

            if ($stocktake->status === StocktakeStatus::COMPLETED) {
                throw ValidationException::withMessages([
                    'item' => ['لا يمكن إعادة العد بعد اكتمال مستند الجرد المخزني.'],
                ]);
            }

            if (! in_array($stocktake->status, [StocktakeStatus::IN_PROGRESS, StocktakeStatus::REJECTED], true)) {
                throw ValidationException::withMessages([
                    'item' => ["لا يمكن إعادة العد ومستند الجرد في حالة '{$stocktake->status->value}'."],
                ]);
            }

            $warehouseId = $stocktake->warehouse_id;
            $productId = $lockedItem->product_id;

            $this->ensureStockRowExists($warehouseId, $productId);

            /** @var WarehouseStock $stock */
            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            $previousQuantity = $lockedItem->counted_quantity;
            $previousStatus = $lockedItem->count_status->value;
            $newSystemSnapshot = (string) $stock->quantity;

            $lockedItem->update([
                'system_quantity' => $newSystemSnapshot,
                'counted_quantity' => null,
                'count_status' => StocktakeItemStatus::COUNTING,
                'count_started_by' => $user->id,
                'count_started_at' => Carbon::now(),
                'counted_by' => null,
                'counted_at' => null,
            ]);

            StocktakeItemHistory::query()->create([
                'stocktake_id' => $stocktake->id,
                'stocktake_item_id' => $lockedItem->id,
                'action' => 'recount',
                'previous_quantity' => $previousQuantity,
                'new_quantity' => null,
                'previous_status' => $previousStatus,
                'new_status' => StocktakeItemStatus::COUNTING->value,
                'reason' => $trimmedReason,
                'user_id' => $user->id,
            ]);

            return $lockedItem->fresh(['product', 'stocktake', 'countStartedBy']);
        });
    }

    /**
     * Safely ensures a WarehouseStock record exists for warehouse and product.
     */
    protected function ensureStockRowExists(int $warehouseId, int $productId): void
    {
        $exists = WarehouseStock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->exists();

        if (! $exists) {
            try {
                WarehouseStock::query()->firstOrCreate(
                    ['warehouse_id' => $warehouseId, 'product_id' => $productId],
                    ['quantity' => '0.000']
                );
            } catch (\Throwable) {
                // Record created concurrently; safe to continue
            }
        }
    }
}
