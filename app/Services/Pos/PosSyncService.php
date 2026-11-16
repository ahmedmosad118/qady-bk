<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosSyncTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSyncService
{
    public function __construct(
        protected PosSaleService $saleService,
    ) {}

    /**
     * Synchronize a local offline sale transaction idempotently.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     status: string,
     *     is_idempotent: bool,
     *     sale: ?PosSale,
     *     conflict_reason: ?string
     * }
     */
    public function syncOfflineSale(array $payload, User $user): array
    {
        $clientUuid = trim((string) ($payload['client_uuid'] ?? ''));
        if ($clientUuid === '') {
            throw ValidationException::withMessages([
                'client_uuid' => ['معرف العملية المحلي الفريد (UUID) مطلوب لإجراء المزامنة.'],
            ]);
        }

        // 1. Idempotency Check: check if sale already exists in official pos_sales
        $existingSale = PosSale::query()
            ->where('client_uuid', $clientUuid)
            ->with(['items.product', 'paymentMethod', 'customer', 'shift', 'creator', 'inventoryDocuments'])
            ->first();

        if ($existingSale) {
            PosSyncTransaction::query()->updateOrCreate(
                ['client_uuid' => $clientUuid],
                [
                    'shift_id' => $existingSale->shift_id,
                    'pos_sale_id' => $existingSale->id,
                    'payload' => $payload,
                    'status' => PosSyncTransaction::STATUS_SYNCED,
                    'conflict_reason' => null,
                    'synced_at' => Carbon::now(),
                ]
            );

            return [
                'status' => 'synced',
                'is_idempotent' => true,
                'sale' => $existingSale,
                'conflict_reason' => null,
            ];
        }

        // Check if already recorded as conflict
        $existingSync = PosSyncTransaction::query()
            ->where('client_uuid', $clientUuid)
            ->first();

        if ($existingSync && $existingSync->status === PosSyncTransaction::STATUS_CONFLICT) {
            return [
                'status' => 'conflict',
                'is_idempotent' => true,
                'sale' => null,
                'conflict_reason' => $existingSync->conflict_reason,
            ];
        }

        // 2. Validate shift
        $shiftId = isset($payload['shift_id']) ? (int) $payload['shift_id'] : null;
        if ($shiftId) {
            $shift = PosShift::query()->find($shiftId);
            if (! $shift) {
                $conflictReason = "الوردية المحددة للعملية (#{$shiftId}) غير موجودة على الخادم.";
                $this->recordConflict($clientUuid, null, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }

            if ($shift->isClosed()) {
                $conflictReason = "الوردية المحددة للعملية ({$shift->shift_number}) مغلقة بالفعل على الخادم. لا يمكن ترحيل مبيعات أوفلاين قديمة إلى وردية مغلقة.";
                $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }
        } else {
            // If shift_id was not provided in offline payload, resolve currently open shift
            $shift = PosShift::query()->open()->first();
            if (! $shift) {
                $conflictReason = 'لا توجد وردية مفتوحة على الخادم لاستقبال المزامنة.';
                $this->recordConflict($clientUuid, null, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }
            $payload['shift_id'] = $shift->id;
        }

        // 3. Resolve warehouse
        $warehouseId = $payload['warehouse_id'] ?? null;
        if ($warehouseId) {
            $warehouse = Warehouse::query()->where('is_active', true)->find($warehouseId);
        } else {
            $warehouse = Warehouse::query()->where('is_default', true)->where('is_active', true)->first()
                ?: Warehouse::query()->where('is_active', true)->first();
        }

        if (! $warehouse) {
            $conflictReason = 'لم يتم العثور على مخزن نشط لمعالجة الفاتورة.';
            $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

            return [
                'status' => 'conflict',
                'is_idempotent' => false,
                'sale' => null,
                'conflict_reason' => $conflictReason,
            ];
        }

        // 4. Pre-flight stock check to detect conflicts before writing to pos_sales
        $items = $payload['items'] ?? [];
        foreach ($items as $item) {
            $prodId = (int) $item['product_id'];
            $reqQty = (float) $item['quantity'];

            $stock = WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $prodId)
                ->first();

            $avail = $stock ? (float) $stock->quantity : 0.0;

            if ($reqQty > $avail) {
                $product = Product::query()->find($prodId);
                $prodName = $product?->name ?? "صنف #{$prodId}";
                $conflictReason = "تعارض مخزون: الرصيد غير كافٍ للمنتج '{$prodName}' في المخزن '{$warehouse->name}'. الرصيد المتاح: {$avail}، الكمية المطلوبة محلياً: {$reqQty}.";

                $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

                return [
                    'status' => 'conflict',
                    'is_idempotent' => false,
                    'sale' => null,
                    'conflict_reason' => $conflictReason,
                ];
            }
        }

        // 5. Attempt official sale creation (createSale is internally locked atomically on client_uuid)
        try {
            $sale = $this->saleService->createSale($payload, $user);

            // Record successful sync
            PosSyncTransaction::query()->updateOrCreate(
                ['client_uuid' => $clientUuid],
                [
                    'shift_id' => $shift->id,
                    'pos_sale_id' => $sale->id,
                    'payload' => $payload,
                    'status' => PosSyncTransaction::STATUS_SYNCED,
                    'conflict_reason' => null,
                    'synced_at' => Carbon::now(),
                ]
            );

            return [
                'status' => 'synced',
                'is_idempotent' => false,
                'sale' => $sale,
                'conflict_reason' => null,
            ];
        } catch (\Throwable $e) {
            // Check if concurrent idempotent request already completed the sale
            $existingSale = PosSale::query()->where('client_uuid', $clientUuid)->first();
            if ($existingSale) {
                return [
                    'status' => 'synced',
                    'is_idempotent' => true,
                    'sale' => $existingSale,
                    'conflict_reason' => null,
                ];
            }

            $conflictReason = $e->getMessage();
            $this->recordConflict($clientUuid, $shift->id, $payload, $conflictReason);

            return [
                'status' => 'conflict',
                'is_idempotent' => false,
                'sale' => null,
                'conflict_reason' => $conflictReason,
            ];
        }
    }

    /**
     * Record a sync conflict preserving the local operation payload.
     *
     * @param array<string, mixed> $payload
     */
    protected function recordConflict(string $uuid, ?int $shiftId, array $payload, string $reason): void
    {
        try {
            PosSyncTransaction::query()->updateOrCreate(
                ['client_uuid' => $uuid],
                [
                    'shift_id' => $shiftId,
                    'pos_sale_id' => null,
                    'payload' => $payload,
                    'status' => PosSyncTransaction::STATUS_CONFLICT,
                    'conflict_reason' => $reason,
                    'synced_at' => null,
                ]
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to record POS sync conflict: '.$e->getMessage(), [
                'client_uuid' => $uuid,
                'shift_id' => $shiftId,
            ]);
        }
    }

    /**
     * Get offline bootstrap dataset (products, barcodes, prices, stock, payment methods).
     *
     * @return array<string, mixed>
     */
    public function getBootstrapData(?int $warehouseId = null): array
    {
        $warehouse = $warehouseId
            ? Warehouse::query()->where('is_active', true)->find($warehouseId)
            : (Warehouse::query()->where('is_default', true)->where('is_active', true)->first()
                ?: Warehouse::query()->where('is_active', true)->first());

        $whId = $warehouse?->id;

        $products = Product::query()
            ->active()
            ->with(['category:id,name', 'warehouseStocks' => function ($q) use ($whId): void {
                if ($whId) {
                    $q->where('warehouse_id', $whId);
                }
            }])
            ->get()
            ->map(function (Product $p) use ($whId): array {
                $stock = $p->warehouseStocks->firstWhere('warehouse_id', $whId);
                $available = $stock ? (float) $stock->quantity : 0.0;

                return [
                    'id' => $p->id,
                    'category_id' => $p->category_id,
                    'name' => $p->name,
                    'barcode' => $p->barcode,
                    'selling_price' => (string) $p->selling_price,
                    'purchase_price' => (string) $p->purchase_price,
                    'category' => $p->category?->name,
                    'current_stock' => $available,
                    'stock_quantity' => $available,
                ];
            });

        $paymentMethods = PaymentMethod::query()
            ->active()
            ->get(['id', 'name']);

        $openShifts = PosShift::query()
            ->open()
            ->with('openedBy:id,name')
            ->get(['id', 'shift_number', 'opened_at', 'opened_by', 'opening_balance'])
            ->map(fn (PosShift $s): array => [
                'id' => $s->id,
                'shift_number' => $s->shift_number,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'opened_by' => $s->opened_by,
                'opened_by_name' => $s->openedBy?->name ?? 'غير محدد',
                'opening_balance' => (string) $s->opening_balance,
            ]);

        return [
            'warehouse' => $warehouse ? [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
            ] : null,
            'products' => $products,
            'payment_methods' => $paymentMethods,
            'open_shifts' => $openShifts,
            'server_time' => Carbon::now()->toIso8601String(),
        ];
    }
}
