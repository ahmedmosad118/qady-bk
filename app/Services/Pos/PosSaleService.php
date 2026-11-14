<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryDocumentNumberService;
use App\Services\InventoryDocumentWorkflowService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSaleService
{
    public function __construct(
        protected PosNumberService $numberService,
        protected InventoryDocumentNumberService $invDocNumberService,
        protected InventoryDocumentWorkflowService $invWorkflowService,
        protected PosShiftService $shiftService,
    ) {}

    /**
     * Complete a POS sale atomically with inventory deduction and shift transaction.
     *
     * @param array<string, mixed> $data
     */
    public function createSale(array $data, User $user): PosSale
    {
        return DB::transaction(function () use ($data, $user): PosSale {
            // 1. Lock and validate shift is open
            /** @var PosShift $shift */
            $shift = PosShift::query()->lockForUpdate()->findOrFail($data['shift_id']);
            if ($shift->isClosed()) {
                throw ValidationException::withMessages([
                    'shift_id' => ['الوردية المحددة مغلقة. لا يمكن إتمام عملية البيع على وردية مغلقة.'],
                ]);
            }

            // 2. Validate payment method
            /** @var PaymentMethod $paymentMethod */
            $paymentMethod = PaymentMethod::query()
                ->where('is_active', true)
                ->findOrFail($data['payment_method_id']);

            // 3. Resolve warehouse (specified or default active warehouse)
            $warehouseId = $data['warehouse_id'] ?? null;
            if ($warehouseId) {
                $warehouse = Warehouse::query()->where('is_active', true)->findOrFail($warehouseId);
            } else {
                $warehouse = Warehouse::query()->where('is_default', true)->where('is_active', true)->first()
                    ?: Warehouse::query()->where('is_active', true)->firstOrFail();
            }

            // 4. Validate customer if provided
            $customerId = $data['customer_id'] ?? null;
            if ($customerId) {
                Contact::query()->where('is_active', true)->findOrFail($customerId);
            }

            // 5. Consolidate and validate items
            $rawItems = $data['items'] ?? [];
            if (empty($rawItems)) {
                throw ValidationException::withMessages([
                    'items' => ['يجب إضافة صنف واحد على الأقل في الفاتورة.'],
                ]);
            }

            // Consolidate duplicates: if same product added multiple times, aggregate quantity
            $consolidated = [];
            foreach ($rawItems as $raw) {
                $pId = (int) $raw['product_id'];
                $qty = (float) $raw['quantity'];

                if ($qty <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['الكمية لكل صنف يجب أن تكون أكبر من الصفر.'],
                    ]);
                }

                if (! isset($consolidated[$pId])) {
                    $consolidated[$pId] = $raw;
                    $consolidated[$pId]['quantity'] = $qty;
                } else {
                    $consolidated[$pId]['quantity'] += $qty;
                }
            }

            // Prepare item records and calculate line totals
            $processedItems = [];
            $itemsSubtotalSum = '0.00';
            $itemsDiscountTotalSum = '0.00';
            $sumOfLineTotals = '0.00';

            foreach ($consolidated as $pId => $itemData) {
                /** @var Product $product */
                $product = Product::query()->findOrFail($pId);
                if (! $product->is_active) {
                    throw ValidationException::withMessages([
                        'items' => ["المنتج '{$product->name}' غير نشط ولا يمكن بيعه."],
                    ]);
                }

                $qty = (float) $itemData['quantity'];
                $qtyStr = number_format($qty, 3, '.', '');

                $originalPrice = (string) $product->selling_price;
                $unitPrice = isset($itemData['unit_price'])
                    ? number_format((float) $itemData['unit_price'], 2, '.', '')
                    : $originalPrice;

                if (bccomp($unitPrice, '0.00', 2) < 0) {
                    throw ValidationException::withMessages([
                        'items' => ["سعر البيع للصنف '{$product->name}' لا يمكن أن يكون سالبًا."],
                    ]);
                }

                // Line subtotal = quantity * unit_price
                $lineSubtotal = bcmul($qtyStr, $unitPrice, 2);

                // Item-level discount
                $discType = $itemData['discount_type'] ?? null;
                $discVal = isset($itemData['discount_value']) ? (float) $itemData['discount_value'] : null;
                $discAmount = '0.00';

                if ($discType === 'percentage' && $discVal !== null) {
                    if ($discVal < 0 || $discVal > 100) {
                        throw ValidationException::withMessages([
                            'items' => ['نسبة الخصم على الصنف يجب أن تكون بين 0 و 100.'],
                        ]);
                    }
                    $discAmount = bcdiv(bcmul($lineSubtotal, (string) $discVal, 4), '100', 2);
                } elseif ($discType === 'fixed' && $discVal !== null) {
                    if ($discVal < 0) {
                        throw ValidationException::withMessages([
                            'items' => ['قيمة الخصم على الصنف لا يمكن أن تكون سالبة.'],
                        ]);
                    }
                    $discAmount = number_format(min((float) $lineSubtotal, $discVal), 2, '.', '');
                }

                if (bccomp($discAmount, $lineSubtotal, 2) > 0) {
                    $discAmount = $lineSubtotal;
                }

                $lineTotal = bcsub($lineSubtotal, $discAmount, 2);

                $itemsSubtotalSum = bcadd($itemsSubtotalSum, $lineSubtotal, 2);
                $itemsDiscountTotalSum = bcadd($itemsDiscountTotalSum, $discAmount, 2);
                $sumOfLineTotals = bcadd($sumOfLineTotals, $lineTotal, 2);

                $snapshot = [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'barcodes' => $product->barcode ? [$product->barcode] : [],
                    'unit_name' => 'قطعة',
                ];

                $processedItems[] = [
                    'product_id' => $product->id,
                    'product' => $product,
                    'product_snapshot' => $snapshot,
                    'quantity' => $qtyStr,
                    'original_unit_price' => $originalPrice,
                    'unit_price' => $unitPrice,
                    'discount_type' => $discType,
                    'discount_value' => $discVal !== null ? number_format($discVal, 2, '.', '') : null,
                    'discount_amount' => $discAmount,
                    'subtotal' => $lineSubtotal,
                    'total' => $lineTotal,
                ];
            }

            // 6. Invoice-level discount
            $invDiscType = $data['invoice_discount_type'] ?? null;
            $invDiscVal = isset($data['invoice_discount_value']) ? (float) $data['invoice_discount_value'] : null;
            $invDiscAmount = '0.00';

            if ($invDiscType === 'percentage' && $invDiscVal !== null) {
                if ($invDiscVal < 0 || $invDiscVal > 100) {
                    throw ValidationException::withMessages([
                        'invoice_discount_value' => ['نسبة خصم الفاتورة يجب أن تكون بين 0 و 100.'],
                    ]);
                }
                $invDiscAmount = bcdiv(bcmul($sumOfLineTotals, (string) $invDiscVal, 4), '100', 2);
            } elseif ($invDiscType === 'fixed' && $invDiscVal !== null) {
                if ($invDiscVal < 0) {
                    throw ValidationException::withMessages([
                        'invoice_discount_value' => ['قيمة خصم الفاتورة لا يمكن أن تكون سالبة.'],
                    ]);
                }
                $invDiscAmount = number_format(min((float) $sumOfLineTotals, $invDiscVal), 2, '.', '');
            }

            if (bccomp($invDiscAmount, $sumOfLineTotals, 2) > 0) {
                $invDiscAmount = $sumOfLineTotals;
            }

            $grandTotal = bcsub($sumOfLineTotals, $invDiscAmount, 2);

            // 7. Accurately allocate invoice discount across items (Rule 11 for partial returns)
            $allocatedTotal = '0.00';
            $itemCount = count($processedItems);

            foreach ($processedItems as $index => &$itemRef) {
                if ($index === $itemCount - 1) {
                    // Last item takes remaining penny difference
                    $allocated = bcsub($invDiscAmount, $allocatedTotal, 2);
                } else {
                    if (bccomp($sumOfLineTotals, '0.00', 2) > 0) {
                        $ratio = bcdiv($itemRef['total'], $sumOfLineTotals, 6);
                        $allocated = number_format((float) bcmul($invDiscAmount, $ratio, 4), 2, '.', '');
                    } else {
                        $allocated = '0.00';
                    }
                    $allocatedTotal = bcadd($allocatedTotal, $allocated, 2);
                }
                $itemRef['allocated_invoice_discount'] = $allocated;
            }
            unset($itemRef);

            // 8. Generate invoice number & create POS Sale record
            $invoiceNumber = $this->numberService->generateInvoiceNumber();

            /** @var PosSale $sale */
            $sale = PosSale::query()->create([
                'invoice_number' => $invoiceNumber,
                'shift_id' => $shift->id,
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethod->id,
                'subtotal' => $itemsSubtotalSum,
                'items_discount_total' => $itemsDiscountTotalSum,
                'invoice_discount_type' => $invDiscType,
                'invoice_discount_value' => $invDiscVal !== null ? number_format($invDiscVal, 2, '.', '') : null,
                'invoice_discount_amount' => $invDiscAmount,
                'grand_total' => $grandTotal,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'sold_at' => Carbon::now(),
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            // 9. Create POS Sale Items
            foreach ($processedItems as $itemRow) {
                PosSaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $itemRow['product_id'],
                    'product_snapshot' => $itemRow['product_snapshot'],
                    'quantity' => $itemRow['quantity'],
                    'original_unit_price' => $itemRow['original_unit_price'],
                    'unit_price' => $itemRow['unit_price'],
                    'discount_type' => $itemRow['discount_type'],
                    'discount_value' => $itemRow['discount_value'],
                    'discount_amount' => $itemRow['discount_amount'],
                    'subtotal' => $itemRow['subtotal'],
                    'total' => $itemRow['total'],
                    'allocated_invoice_discount' => $itemRow['allocated_invoice_discount'],
                ]);
            }

            // 10. Record cash sale in shift if paid cash
            if ($paymentMethod->name === 'نقدي') {
                $tx = new PosShiftTransaction();
                $tx->shift_id = $shift->id;
                $tx->type = PosShiftTransaction::TYPE_CASH_SALE;
                $tx->amount = $grandTotal;
                $tx->pos_sale_id = $sale->id;
                $tx->pos_return_id = null;
                $tx->name = "مبيعات نقدية - فاتورة {$sale->invoice_number}";
                $tx->description = null;
                $tx->created_by = $user->id;
                $tx->created_at = Carbon::now();
                $tx->save();

                // Refresh expected cash
                $shift->update([
                    'expected_cash' => $this->shiftService->calculateExpectedCash($shift),
                ]);
            }

            // 11. Create Stock Issue inventory document and post it
            $docNumber = $this->invDocNumberService->generate(InventoryDocumentType::STOCK_ISSUE);

            /** @var InventoryDocument $invDoc */
            $invDoc = InventoryDocument::query()->create([
                'document_number' => $docNumber,
                'document_type' => InventoryDocumentType::STOCK_ISSUE,
                'referenceable_type' => 'pos_sale',
                'referenceable_id' => $sale->id,
                'source_warehouse_id' => $warehouse->id,
                'destination_warehouse_id' => null,
                'adjustment_direction' => null,
                'return_direction' => null,
                'status' => InventoryDocumentStatus::APPROVED,
                'document_date' => Carbon::now()->toDateString(),
                'notes' => "صرف مخزني تلقائي لفاتورة بيع نقطة البيع رقم: {$sale->invoice_number}",
                'created_by' => $user->id,
                'approved_by' => $user->id,
                'approved_at' => Carbon::now(),
            ]);

            foreach ($processedItems as $itemRow) {
                InventoryDocumentItem::query()->create([
                    'inventory_document_id' => $invDoc->id,
                    'product_id' => $itemRow['product_id'],
                    'quantity' => $itemRow['quantity'],
                    'notes' => null,
                ]);
            }

            // Post document using the current workflow posting service
            // This verifies stock availability, applies row locking, checks stocktake locks,
            // creates immutable stock_movements, and updates warehouse_stocks.
            $this->invWorkflowService->post($invDoc, $user);

            return $sale->load(['items.product', 'paymentMethod', 'customer', 'shift', 'creator', 'inventoryDocuments']);
        });
    }

    /**
     * Get sale by invoice number.
     */
    public function getSaleByInvoiceNumber(string $invoiceNumber): PosSale
    {
        return PosSale::query()
            ->where('invoice_number', $invoiceNumber)
            ->with(['items.product', 'paymentMethod', 'customer', 'shift', 'creator', 'returns'])
            ->firstOrFail();
    }

    /**
     * Get sale by ID with full relations.
     */
    public function getSaleDetails(PosSale $sale): PosSale
    {
        return $sale->load(['items.product', 'paymentMethod', 'customer', 'shift', 'creator', 'returns.items']);
    }

    /**
     * Search products for POS with available stock in warehouse.
     *
     * @return Collection<int, Product>
     */
    public function searchProducts(string $query, ?int $warehouseId = null): Collection
    {
        $term = trim($query);

        $whId = $warehouseId;
        if (! $whId) {
            $whId = Warehouse::query()->where('is_default', true)->value('id')
                ?: Warehouse::query()->where('is_active', true)->value('id');
        }

        return Product::query()
            ->active()
            ->when($term !== '', function ($q) use ($term): void {
                $q->where(function ($sub) use ($term): void {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%");
                });
            })
            ->with(['category:id,name', 'warehouseStocks' => function ($q) use ($whId): void {
                if ($whId) {
                    $q->where('warehouse_id', $whId);
                }
            }])
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    /**
     * Lookup product by exact barcode.
     */
    public function lookupBarcode(string $barcode, ?int $warehouseId = null): ?Product
    {
        $whId = $warehouseId;
        if (! $whId) {
            $whId = Warehouse::query()->where('is_default', true)->value('id')
                ?: Warehouse::query()->where('is_active', true)->value('id');
        }

        return Product::query()
            ->active()
            ->where('barcode', $barcode)
            ->with(['category:id,name', 'warehouseStocks' => function ($q) use ($whId): void {
                if ($whId) {
                    $q->where('warehouse_id', $whId);
                }
            }])
            ->first();
    }
}
