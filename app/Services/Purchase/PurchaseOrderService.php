<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\DiscountType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderNumberService $numberService,
    ) {}

    /**
     * Paginate purchase orders with filtering and eager loading.
     *
     * @param array<string, mixed> $filters
     */
    public function paginateOrders(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = PurchaseOrder::query()
            ->with([
                'supplier:id,name,phone,mobile,tax_number',
                'creator:id,name',
                'confirmer:id,name',
                'purchaseInvoices:id,invoice_number,purchase_order_id,status,total,invoice_date',
            ])
            ->withCount('items')
            ->withSum('items', 'discount_amount');

        if (! empty($filters['search'])) {
            $query->search((string) $filters['search']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['order_date_from'])) {
            $query->whereDate('order_date', '>=', $filters['order_date_from']);
        }

        if (! empty($filters['order_date_to'])) {
            $query->whereDate('order_date', '<=', $filters['order_date_to']);
        }

        if (! empty($filters['delivery_date_from'])) {
            $query->whereDate('expected_delivery_date', '>=', $filters['delivery_date_from']);
        }

        if (! empty($filters['delivery_date_to'])) {
            $query->whereDate('expected_delivery_date', '<=', $filters['delivery_date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'id';
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSorts = ['id', 'order_number', 'order_date', 'expected_delivery_date', 'total', 'status', 'created_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'id';
        }

        return $query->orderBy($sortBy, $sortOrder)->paginate($perPage);
    }

    /**
     * Find single purchase order with details.
     */
    public function getOrder(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()
            ->with([
                'supplier',
                'items.product',
                'creator:id,name,email',
                'confirmer:id,name,email',
                'purchaseInvoices:id,invoice_number,purchase_order_id,status,total,invoice_date',
            ])
            ->findOrFail($id);
    }

    /**
     * Consolidate duplicate products and calculate item/order totals.
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @return array{items: array<int, array<string, mixed>>, subtotal: string, discount_amount: string, total: string}
     */
    public function calculateOrderData(
        array $rawItems,
        ?string $discountType = null,
        float|int|string|null $discountValue = null
    ): array {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['يجب إضافة صنف واحد على الأقل في أمر الشراء.'],
            ]);
        }

        // 1. Consolidate duplicate products: sum quantities
        /** @var array<int, array<string, mixed>> $consolidated */
        $consolidated = [];
        foreach ($rawItems as $item) {
            $productId = (int) $item['product_id'];
            $qty = (string) $item['quantity'];
            $unitPrice = number_format((float) $item['unit_price'], 2, '.', '');
            $itemDiscType = $item['discount_type'] ?? null;
            $itemDiscVal = isset($item['discount_value']) && $item['discount_value'] !== '' ? (float) $item['discount_value'] : null;

            if (isset($consolidated[$productId])) {
                // Sum quantity
                $consolidated[$productId]['quantity'] = bcadd(
                    (string) $consolidated[$productId]['quantity'],
                    $qty,
                    3
                );
                // Keep latest price or discounts if provided
                if ($unitPrice > 0) {
                    $consolidated[$productId]['unit_price'] = $unitPrice;
                }
                if ($itemDiscType !== null) {
                    $consolidated[$productId]['discount_type'] = $itemDiscType;
                    $consolidated[$productId]['discount_value'] = $itemDiscVal;
                }
            } else {
                $consolidated[$productId] = [
                    'product_id' => $productId,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'discount_type' => $itemDiscType,
                    'discount_value' => $itemDiscVal,
                ];
            }
        }

        // 2. Fetch products to make snapshots and calculate lines
        $productIds = array_keys($consolidated);
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $processedItems = [];
        $orderSubtotal = '0.00';

        foreach ($consolidated as $productId => $row) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["المنتج رقم {$productId} غير موجود."],
                ]);
            }

            $qtyStr = number_format((float) $row['quantity'], 3, '.', '');
            $unitPriceStr = number_format((float) $row['unit_price'], 2, '.', '');

            // Line subtotal = quantity * unit_price
            $lineSubtotal = bcmul($qtyStr, $unitPriceStr, 2);

            // Item discount
            $discType = $row['discount_type'];
            $discVal = $row['discount_value'];
            $discAmount = '0.00';

            if ($discType === DiscountType::PERCENTAGE->value && $discVal !== null) {
                $safePct = max(0.0, min(100.0, (float) $discVal));
                $discAmount = bcdiv(bcmul($lineSubtotal, (string) $safePct, 4), '100', 2);
            } elseif ($discType === DiscountType::FIXED->value && $discVal !== null) {
                $safeFixed = max(0.0, (float) $discVal);
                $discAmount = number_format(min((float) $lineSubtotal, $safeFixed), 2, '.', '');
            }

            // Ensure discount does not exceed line subtotal
            if (bccomp($discAmount, $lineSubtotal, 2) > 0) {
                $discAmount = $lineSubtotal;
            }

            $lineTotal = bcsub($lineSubtotal, $discAmount, 2);
            $orderSubtotal = bcadd($orderSubtotal, $lineTotal, 2);

            $snapshot = [
                'name' => $product->name,
                'barcode' => $product->barcode,
                'unit_name' => 'قطعة',
            ];

            $processedItems[] = [
                'product_id' => $product->id,
                'product_snapshot' => $snapshot,
                'quantity' => $qtyStr,
                'unit_price' => $unitPriceStr,
                'discount_type' => $discType,
                'discount_value' => $discVal !== null ? number_format((float) $discVal, 2, '.', '') : null,
                'discount_amount' => $discAmount,
                'line_total' => $lineTotal,
            ];
        }

        // 3. Invoice-level discount
        $orderDiscAmount = '0.00';
        $orderDiscVal = $discountValue !== null && $discountValue !== '' ? (float) $discountValue : null;

        if ($discountType === DiscountType::PERCENTAGE->value && $orderDiscVal !== null) {
            $safePct = max(0.0, min(100.0, $orderDiscVal));
            $orderDiscAmount = bcdiv(bcmul($orderSubtotal, (string) $safePct, 4), '100', 2);
        } elseif ($discountType === DiscountType::FIXED->value && $orderDiscVal !== null) {
            $safeFixed = max(0.0, $orderDiscVal);
            $orderDiscAmount = number_format(min((float) $orderSubtotal, $safeFixed), 2, '.', '');
        }

        if (bccomp($orderDiscAmount, $orderSubtotal, 2) > 0) {
            $orderDiscAmount = $orderSubtotal;
        }

        $orderTotal = bcsub($orderSubtotal, $orderDiscAmount, 2);

        return [
            'items' => $processedItems,
            'subtotal' => $orderSubtotal,
            'discount_amount' => $orderDiscAmount,
            'total' => $orderTotal,
        ];
    }

    /**
     * Create a new purchase order.
     *
     * @param array<string, mixed> $data
     */
    public function createOrder(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user): PurchaseOrder {
            // Verify supplier exists and is active supplier
            $supplier = Contact::query()->where('id', $data['supplier_id'])->where('is_supplier', true)->first();
            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['المورد المحدد غير صالح أو غير مسجل كمورد.'],
                ]);
            }

            $calc = $this->calculateOrderData(
                $data['items'],
                $data['discount_type'] ?? null,
                $data['discount_value'] ?? null
            );

            $orderDate = ! empty($data['order_date']) ? Carbon::parse($data['order_date']) : Carbon::today();
            $orderNumber = $this->numberService->generateOrderNumber($orderDate);

            $order = PurchaseOrder::query()->create([
                'order_number' => $orderNumber,
                'supplier_id' => $supplier->id,
                'order_date' => $orderDate->toDateString(),
                'expected_delivery_date' => ! empty($data['expected_delivery_date'])
                    ? Carbon::parse($data['expected_delivery_date'])->toDateString()
                    : null,
                'status' => PurchaseOrderStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $calc['subtotal'],
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => isset($data['discount_value']) && $data['discount_value'] !== ''
                    ? number_format((float) $data['discount_value'], 2, '.', '')
                    : '0.00',
                'discount_amount' => $calc['discount_amount'],
                'total' => $calc['total'],
                'created_by' => $user->id,
            ]);

            foreach ($calc['items'] as $itemRow) {
                $order->items()->create($itemRow);
            }

            return $this->getOrder($order->id);
        });
    }

    /**
     * Update an existing draft purchase order.
     *
     * @param array<string, mixed> $data
     */
    public function updateOrder(PurchaseOrder $order, array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            // Lock order for update
            /** @var PurchaseOrder $lockedOrder */
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $lockedOrder->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن تعديل أمر شراء بعد تحويله لفاتورة أو إلغائه.'],
                ]);
            }

            // Verify supplier
            $supplier = Contact::query()->where('id', $data['supplier_id'])->where('is_supplier', true)->first();
            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['المورد المحدد غير صالح أو غير مسجل كمورد.'],
                ]);
            }

            $calc = $this->calculateOrderData(
                $data['items'],
                $data['discount_type'] ?? null,
                $data['discount_value'] ?? null
            );

            $orderDate = ! empty($data['order_date']) ? Carbon::parse($data['order_date']) : Carbon::today();

            $lockedOrder->update([
                'supplier_id' => $supplier->id,
                'order_date' => $orderDate->toDateString(),
                'expected_delivery_date' => ! empty($data['expected_delivery_date'])
                    ? Carbon::parse($data['expected_delivery_date'])->toDateString()
                    : null,
                'notes' => $data['notes'] ?? null,
                'subtotal' => $calc['subtotal'],
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => isset($data['discount_value']) && $data['discount_value'] !== ''
                    ? number_format((float) $data['discount_value'], 2, '.', '')
                    : '0.00',
                'discount_amount' => $calc['discount_amount'],
                'total' => $calc['total'],
            ]);

            // Replace line items
            $lockedOrder->items()->delete();
            foreach ($calc['items'] as $itemRow) {
                $lockedOrder->items()->create($itemRow);
            }

            return $this->getOrder($lockedOrder->id);
        });
    }

    /**
     * Delete an existing draft purchase order.
     */
    public function deleteOrder(PurchaseOrder $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            /** @var PurchaseOrder $lockedOrder */
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if (! $lockedOrder->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن حذف أمر شراء غير مسودة.'],
                ]);
            }

            $lockedOrder->items()->delete();
            return (bool) $lockedOrder->delete();
        });
    }

    /**
     * Confirm / Convert a purchase order to purchase invoice.
     * Prevents duplicate conversion atomically.
     */
    public function confirmOrder(PurchaseOrder $order, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user): PurchaseOrder {
            /** @var PurchaseOrder $lockedOrder */
            $lockedOrder = PurchaseOrder::query()
                ->with(['items', 'supplier'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($lockedOrder->isConverted()) {
                throw ValidationException::withMessages([
                    'status' => ['أمر الشراء تم تحويله وتأكيده مسبقاً.'],
                ]);
            }

            if ($lockedOrder->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن تأكيد أمر شراء تم إلغاؤه.'],
                ]);
            }

            if ($lockedOrder->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => ['أمر الشراء لا يحتوي على أصناف صالحة للتأكيد.'],
                ]);
            }

            if (! $lockedOrder->supplier || ! $lockedOrder->supplier->is_supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => ['المورد المرتبط بأمر الشراء غير صالح.'],
                ]);
            }

            // Mark converted atomically
            $lockedOrder->status = PurchaseOrderStatus::CONVERTED;
            $lockedOrder->confirmed_at = Carbon::now();
            $lockedOrder->confirmed_by = $user->id;
            $lockedOrder->save();

            // Note: Stock quantity is NOT modified here per business rules.
            // When the purchase invoice module is introduced, this is the exact integration point
            // to generate the purchase invoice.

            return $this->getOrder($lockedOrder->id);
        });
    }

    /**
     * Cancel a purchase order.
     */
    public function cancelOrder(PurchaseOrder $order, User $user, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason): PurchaseOrder {
            /** @var PurchaseOrder $lockedOrder */
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->isConverted()) {
                throw ValidationException::withMessages([
                    'status' => ['لا يمكن إلغاء أمر شراء تم تحويله بالفعل.'],
                ]);
            }

            if ($lockedOrder->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => ['أمر الشراء ملغي بالفعل.'],
                ]);
            }

            if (! empty($reason)) {
                $prefix = $lockedOrder->notes ? $lockedOrder->notes . "\n" : '';
                $lockedOrder->notes = $prefix . "[سبب الإلغاء: {$reason}]";
            }

            $lockedOrder->status = PurchaseOrderStatus::CANCELLED;
            $lockedOrder->save();

            return $this->getOrder($lockedOrder->id);
        });
    }
}
