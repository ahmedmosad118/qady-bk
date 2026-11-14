<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\PosReturn;
use App\Models\PosReturnItem;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryDocumentNumberService;
use App\Services\InventoryDocumentWorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosReturnService
{
    public function __construct(
        protected PosNumberService $numberService,
        protected InventoryDocumentNumberService $invDocNumberService,
        protected InventoryDocumentWorkflowService $invWorkflowService,
        protected PosShiftService $shiftService,
    ) {}

    /**
     * Process a return against an existing POS sale invoice.
     *
     * @param array<string, mixed> $data
     */
    public function processReturn(array $data, User $user): PosReturn
    {
        return DB::transaction(function () use ($data, $user): PosReturn {
            // 1. Lock and validate shift
            /** @var PosShift $shift */
            $shift = PosShift::query()->lockForUpdate()->findOrFail($data['shift_id']);
            if ($shift->isClosed()) {
                throw ValidationException::withMessages([
                    'shift_id' => ['الوردية المحددة مغلقة. لا يمكن إجراء عمليات إرجاع على وردية مغلقة.'],
                ]);
            }

            // 2. Lock original sale
            /** @var PosSale $sale */
            $sale = PosSale::query()
                ->lockForUpdate()
                ->with(['items.product', 'paymentMethod'])
                ->findOrFail($data['pos_sale_id']);

            // 3. Resolve destination warehouse (default active warehouse or warehouse where original sale issued)
            $warehouseId = $data['warehouse_id'] ?? null;
            if ($warehouseId) {
                $warehouse = Warehouse::query()->where('is_active', true)->findOrFail($warehouseId);
            } else {
                // If original sale had an inventory document, use its source warehouse
                $origDoc = $sale->inventoryDocuments()->first();
                $origWhId = $origDoc?->source_warehouse_id;

                if ($origWhId) {
                    $warehouse = Warehouse::query()->where('is_active', true)->find($origWhId);
                }

                if (! isset($warehouse) || ! $warehouse) {
                    $warehouse = Warehouse::query()->where('is_default', true)->where('is_active', true)->first()
                        ?: Warehouse::query()->where('is_active', true)->firstOrFail();
                }
            }

            // 4. Validate return items
            $rawItems = $data['items'] ?? [];
            if (empty($rawItems)) {
                throw ValidationException::withMessages([
                    'items' => ['يجب تحديد صنف واحد على الأقل للإرجاع.'],
                ]);
            }

            $saleItemsById = $sale->items->keyBy('id');
            $processedItems = [];
            $totalRefundAmount = '0.00';

            foreach ($rawItems as $raw) {
                $saleItemId = (int) $raw['pos_sale_item_id'];
                $requestedQty = (float) $raw['quantity'];

                if ($requestedQty <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['الكمية المرتجعة يجب أن تكون أكبر من الصفر.'],
                    ]);
                }

                /** @var PosSaleItem|null $saleItem */
                $saleItem = $saleItemsById->get($saleItemId);
                if (! $saleItem) {
                    throw ValidationException::withMessages([
                        'items' => ["سطر الفاتورة المعرف بـ {$saleItemId} لا ينتمي إلى هذه الفاتورة."],
                    ]);
                }

                // Check remaining returnable quantity across all prior returns
                $alreadyReturned = (float) PosReturnItem::query()
                    ->where('pos_sale_item_id', $saleItem->id)
                    ->sum('quantity');

                $originalQty = (float) $saleItem->quantity;
                $remainingQty = max(0.0, $originalQty - $alreadyReturned);

                if ($requestedQty > $remainingQty) {
                    $prodName = $saleItem->product?->name ?? 'المنتج';
                    throw ValidationException::withMessages([
                        'items' => [
                            "الكمية المطلوبة لإرجاع '{$prodName}' ({$requestedQty}) تتجاوز الكمية المتاحة للإرجاع ({$remainingQty}). المباع: {$originalQty}، المرتجع سابقاً: {$alreadyReturned}."
                        ],
                    ]);
                }

                // Calculate pro-rated net paid amount and discount for the returned quantity
                // Net total paid for line = total - allocated_invoice_discount
                $netLineTotal = bcsub((string) $saleItem->total, (string) $saleItem->allocated_invoice_discount, 4);
                $origQtyStr = (string) $saleItem->quantity;

                // Net paid unit price = netLineTotal / originalQty
                $netPaidUnitPrice = bcdiv($netLineTotal, $origQtyStr, 6);
                $reqQtyStr = number_format($requestedQty, 3, '.', '');

                $lineRefund = number_format((float) bcmul($netPaidUnitPrice, $reqQtyStr, 4), 2, '.', '');

                // Pro-rated discount for returned line = (discount_amount + allocated_invoice_discount) * (reqQty / origQty)
                $totalLineDiscount = bcadd((string) $saleItem->discount_amount, (string) $saleItem->allocated_invoice_discount, 4);
                $lineAllocatedDiscount = number_format((float) bcmul(bcdiv($totalLineDiscount, $origQtyStr, 6), $reqQtyStr, 4), 2, '.', '');

                $totalRefundAmount = bcadd($totalRefundAmount, $lineRefund, 2);

                $processedItems[] = [
                    'pos_sale_item_id' => $saleItem->id,
                    'product_id' => $saleItem->product_id,
                    'quantity' => $reqQtyStr,
                    'unit_price' => (string) $saleItem->unit_price,
                    'allocated_discount' => $lineAllocatedDiscount,
                    'refund_amount' => $lineRefund,
                ];
            }

            // 5. Create POS Return record
            $returnNumber = $this->numberService->generateReturnNumber();

            /** @var PosReturn $posReturn */
            $posReturn = PosReturn::query()->create([
                'return_number' => $returnNumber,
                'pos_sale_id' => $sale->id,
                'shift_id' => $shift->id,
                'payment_method_id' => $sale->payment_method_id, // Must match original payment method!
                'total_returned' => $totalRefundAmount,
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'returned_at' => Carbon::now(),
            ]);

            // 6. Create POS Return Items
            foreach ($processedItems as $itemRow) {
                PosReturnItem::query()->create([
                    'pos_return_id' => $posReturn->id,
                    'pos_sale_item_id' => $itemRow['pos_sale_item_id'],
                    'product_id' => $itemRow['product_id'],
                    'quantity' => $itemRow['quantity'],
                    'unit_price' => $itemRow['unit_price'],
                    'allocated_discount' => $itemRow['allocated_discount'],
                    'refund_amount' => $itemRow['refund_amount'],
                ]);
            }

            // 7. If original sale was cash ('نقدي'), record cash_return transaction in the current shift
            if ($sale->paymentMethod?->name === 'نقدي') {
                $tx = new PosShiftTransaction();
                $tx->shift_id = $shift->id;
                $tx->type = PosShiftTransaction::TYPE_CASH_RETURN;
                $tx->amount = $totalRefundAmount;
                $tx->pos_sale_id = null;
                $tx->pos_return_id = $posReturn->id;
                $tx->name = "مرتجع نقدي - فاتورة {$sale->invoice_number} - إشعار مرتجع {$posReturn->return_number}";
                $tx->description = null;
                $tx->created_by = $user->id;
                $tx->created_at = Carbon::now();
                $tx->save();

                // Refresh expected cash
                $shift->update([
                    'expected_cash' => $this->shiftService->calculateExpectedCash($shift),
                ]);
            }

            // 8. Create Inventory Return document and post it
            $docNumber = $this->invDocNumberService->generate(InventoryDocumentType::INVENTORY_RETURN);

            /** @var InventoryDocument $invDoc */
            $invDoc = InventoryDocument::query()->create([
                'document_number' => $docNumber,
                'document_type' => InventoryDocumentType::INVENTORY_RETURN,
                'referenceable_type' => 'pos_return',
                'referenceable_id' => $posReturn->id,
                'source_warehouse_id' => null,
                'destination_warehouse_id' => $warehouse->id,
                'adjustment_direction' => null,
                'return_direction' => InventoryReturnDirection::INBOUND,
                'status' => InventoryDocumentStatus::APPROVED,
                'document_date' => Carbon::now()->toDateString(),
                'notes' => "إرجاع مخزني تلقائي لمرتجع نقطة البيع رقم: {$posReturn->return_number}",
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
            // This creates INBOUND stock movements, applies row locking, and updates warehouse_stocks.
            $this->invWorkflowService->post($invDoc, $user);

            return $posReturn->load(['items.product', 'items.saleItem', 'paymentMethod', 'sale', 'shift', 'creator', 'inventoryDocuments']);
        });
    }

    /**
     * Inspect returnable items for an existing sale invoice.
     *
     * @return array<string, mixed>
     */
    public function getReturnableItems(PosSale $sale): array
    {
        $sale->load(['items.product', 'paymentMethod', 'customer', 'returns.items']);

        $itemsSummary = [];
        foreach ($sale->items as $item) {
            $alreadyReturned = (float) PosReturnItem::query()
                ->where('pos_sale_item_id', $item->id)
                ->sum('quantity');

            $soldQty = (float) $item->quantity;
            $remainingQty = max(0.0, $soldQty - $alreadyReturned);

            $netLineTotal = bcsub((string) $item->total, (string) $item->allocated_invoice_discount, 4);
            $netPaidUnitPrice = bcdiv($netLineTotal, (string) $item->quantity, 4);

            $itemsSummary[] = [
                'pos_sale_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'sku' => $item->product?->sku,
                'barcode' => $item->product?->barcode,
                'sold_quantity' => $soldQty,
                'already_returned_quantity' => $alreadyReturned,
                'remaining_returnable_quantity' => $remainingQty,
                'original_unit_price' => (string) $item->original_unit_price,
                'sold_unit_price' => (string) $item->unit_price,
                'net_paid_unit_price' => number_format((float) $netPaidUnitPrice, 2, '.', ''),
                'can_return' => $remainingQty > 0,
            ];
        }

        return [
            'sale' => [
                'id' => $sale->id,
                'invoice_number' => $sale->invoice_number,
                'sold_at' => $sale->sold_at?->toIso8601String(),
                'payment_method' => $sale->paymentMethod?->name,
                'customer' => $sale->customer?->name,
                'subtotal' => (string) $sale->subtotal,
                'items_discount_total' => (string) $sale->items_discount_total,
                'invoice_discount_amount' => (string) $sale->invoice_discount_amount,
                'grand_total' => (string) $sale->grand_total,
            ],
            'items' => $itemsSummary,
        ];
    }
}
