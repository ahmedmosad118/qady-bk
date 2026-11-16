<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\DiscountType;
use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\PurchaseInvoiceRepository;
use App\Services\InventoryDocumentService;
use App\Services\InventoryDocumentWorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceService
{
    public function __construct(
        protected PurchaseInvoiceRepository $repository,
        protected PurchaseInvoiceNumberService $numberService,
        protected InventoryDocumentService $inventoryDocumentService,
        protected InventoryDocumentWorkflowService $inventoryDocumentWorkflowService,
    ) {}

    /**
     * Calculate line items, discounts, additional expenses, and final totals on the backend.
     *
     * Calculation order:
     * 1. Consolidate duplicate products by summing quantities.
     * 2. For each item: gross_line_total = quantity * unit_price.
     * 3. Calculate item discount (percentage or fixed) and line_total = gross_line_total - discount_amount.
     * 4. subtotal = sum of gross_line_total.
     * 5. net_lines_total = subtotal - sum(item discounts).
     * 6. Calculate invoice-level discount on net_lines_total.
     * 7. additional_expenses_total = sum of expenses.
     * 8. total = subtotal - item_discounts - invoice_discount + additional_expenses_total.
     *
     * @param array<int, array<string, mixed>> $rawItems
     * @param array<int, array<string, mixed>> $rawExpenses
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     expenses: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     items_discount_total: string,
     *     discount_value: string,
     *     discount_amount: string,
     *     additional_expenses_total: string,
     *     total: string
     * }
     */
    public function calculateInvoiceData(
        array $rawItems,
        ?string $discountType = null,
        float|int|string|null $discountValue = null,
        array $rawExpenses = []
    ): array {
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['At least one item must be added to the purchase invoice.'],
            ]);
        }

        // 1. Consolidate duplicate products into single lines by summing quantities.
        /** @var array<int, array<string, mixed>> $consolidated */
        $consolidated = [];
        foreach ($rawItems as $index => $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $rawQty = $item['quantity'] ?? null;
            $rawUnitPrice = $item['unit_price'] ?? null;

            if ($productId <= 0 || $rawQty === null || ! is_numeric($rawQty)) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['Invalid product or quantity data.'],
                ]);
            }

            $qtyStr = number_format((float) $rawQty, 3, '.', '');
            if (bccomp($qtyStr, '0.000', 3) <= 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => ['Item quantity must be greater than zero.'],
                ]);
            }

            if ($rawUnitPrice === null || ! is_numeric($rawUnitPrice)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => ['Unit price is required and must be numeric.'],
                ]);
            }

            $unitPriceStr = number_format((float) $rawUnitPrice, 2, '.', '');
            if (bccomp($unitPriceStr, '0.00', 2) < 0) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => ['Unit price cannot be negative.'],
                ]);
            }

            $itemDiscType = ! empty($item['discount_type']) ? (string) $item['discount_type'] : null;
            $itemDiscVal = isset($item['discount_value']) && $item['discount_value'] !== ''
                ? $item['discount_value']
                : null;

            if (isset($consolidated[$productId])) {
                $consolidated[$productId]['quantity'] = bcadd(
                    (string) $consolidated[$productId]['quantity'],
                    $qtyStr,
                    3
                );
            } else {
                $consolidated[$productId] = [
                    'product_id' => $productId,
                    'quantity' => $qtyStr,
                    'unit_price' => $unitPriceStr,
                    'discount_type' => $itemDiscType,
                    'discount_value' => $itemDiscVal,
                ];
            }
        }

        // 2. Load and validate all products.
        $productIds = array_keys($consolidated);
        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $processedItems = [];
        $subtotal = '0.00';
        $itemsDiscountTotal = '0.00';
        $netLinesTotal = '0.00';
        $lineIndex = 0;

        foreach ($consolidated as $productId => $row) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ["Product #{$productId} does not exist."],
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    'items' => ["Product '{$product->name}' is inactive and cannot be added to a purchase invoice."],
                ]);
            }

            $qtyStr = (string) $row['quantity'];
            $unitPriceStr = (string) $row['unit_price'];

            $grossLineTotal = $this->roundBc(bcmul($qtyStr, $unitPriceStr, 6), 2);

            [$itemDiscValStr, $itemDiscAmount] = $this->calculateDiscountAmount(
                $grossLineTotal,
                $row['discount_type'],
                $row['discount_value'],
                "items.{$lineIndex}.discount_value",
                'item discount'
            );

            $lineTotal = bcsub($grossLineTotal, $itemDiscAmount, 2);

            $subtotal = bcadd($subtotal, $grossLineTotal, 2);
            $itemsDiscountTotal = bcadd($itemsDiscountTotal, $itemDiscAmount, 2);
            $netLinesTotal = bcadd($netLinesTotal, $lineTotal, 2);

            $snapshot = [
                'name' => $product->name,
                'barcode' => $product->barcode,
                'unit_name' => 'piece',
            ];

            $processedItems[] = [
                'product_id' => $product->id,
                'product_snapshot' => $snapshot,
                'quantity' => $qtyStr,
                'unit_price' => $unitPriceStr,
                'discount_type' => $row['discount_type'],
                'discount_value' => $itemDiscValStr,
                'discount_amount' => $itemDiscAmount,
                'line_total' => $lineTotal,
            ];

            $lineIndex++;
        }

        // 3. Invoice-level discount (applied to net lines total = subtotal - item discounts).
        $normalizedDiscType = ! empty($discountType) ? $discountType : null;
        [$invoiceDiscValStr, $invoiceDiscAmount] = $this->calculateDiscountAmount(
            $netLinesTotal,
            $normalizedDiscType,
            $discountValue,
            'discount_value',
            'invoice discount'
        );

        // 4. Additional expenses.
        $processedExpenses = [];
        $additionalExpensesTotal = '0.00';

        foreach ($rawExpenses as $expIndex => $expense) {
            $name = trim((string) ($expense['name'] ?? ''));
            $rawAmount = $expense['amount'] ?? null;

            if ($name === '') {
                throw ValidationException::withMessages([
                    "expenses.{$expIndex}.name" => ['Additional expense name is required.'],
                ]);
            }

            if ($rawAmount === null || ! is_numeric($rawAmount)) {
                throw ValidationException::withMessages([
                    "expenses.{$expIndex}.amount" => ['Additional expense amount is required and must be numeric.'],
                ]);
            }

            $amountStr = number_format((float) $rawAmount, 2, '.', '');
            if (bccomp($amountStr, '0.00', 2) < 0) {
                throw ValidationException::withMessages([
                    "expenses.{$expIndex}.amount" => ['Additional expense amount cannot be negative.'],
                ]);
            }

            $additionalExpensesTotal = bcadd($additionalExpensesTotal, $amountStr, 2);
            $processedExpenses[] = [
                'name' => $name,
                'amount' => $amountStr,
                'notes' => isset($expense['notes']) && trim((string) $expense['notes']) !== ''
                    ? trim((string) $expense['notes'])
                    : null,
            ];
        }

        // 5. Final invoice total: subtotal - item discounts - invoice discount + additional expenses.
        $afterDiscounts = bcsub(bcsub($subtotal, $itemsDiscountTotal, 2), $invoiceDiscAmount, 2);
        $total = bcadd($afterDiscounts, $additionalExpensesTotal, 2);

        if (bccomp($total, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                'total' => ['Final invoice total cannot be negative.'],
            ]);
        }

        return [
            'items' => $processedItems,
            'expenses' => $processedExpenses,
            'subtotal' => $subtotal,
            'items_discount_total' => $itemsDiscountTotal,
            'discount_value' => $invoiceDiscValStr,
            'discount_amount' => $invoiceDiscAmount,
            'additional_expenses_total' => $additionalExpensesTotal,
            'total' => $total,
        ];
    }

    /**
     * Create a new draft Purchase Invoice (direct or from a confirmed Purchase Order)
     * and automatically create its linked Draft Goods Receipt inside one transaction.
     *
     * @param array<string, mixed> $data
     */
    public function createInvoice(array $data, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $user): PurchaseInvoice {
            $purchaseOrderId = ! empty($data['purchase_order_id']) ? (int) $data['purchase_order_id'] : null;

            if ($purchaseOrderId !== null) {
                $data = $this->hydrateFromPurchaseOrder($purchaseOrderId, $data);
            }

            $supplier = $this->resolveActiveSupplier((int) ($data['supplier_id'] ?? 0));
            $defaultWarehouse = $this->resolveDefaultActiveWarehouse();

            $calc = $this->calculateInvoiceData(
                $data['items'] ?? [],
                $data['discount_type'] ?? null,
                $data['discount_value'] ?? null,
                $data['expenses'] ?? []
            );

            $invoiceDate = Carbon::parse($data['invoice_date']);
            $dueDate = ! empty($data['due_date'])
                ? Carbon::parse($data['due_date'])
                : $invoiceDate->copy();

            if ($dueDate->lt($invoiceDate)) {
                throw ValidationException::withMessages([
                    'due_date' => ['Due date must be equal to or after the invoice date.'],
                ]);
            }

            $invoiceNumber = $this->resolveInvoiceNumberForCreate(
                $data['invoice_number'] ?? null,
                $invoiceDate
            );

            /** @var PurchaseInvoice $invoice */
            $invoice = PurchaseInvoice::query()->create([
                'invoice_number' => $invoiceNumber,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $purchaseOrderId,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => $calc['subtotal'],
                'discount_type' => ! empty($data['discount_type']) ? $data['discount_type'] : null,
                'discount_value' => $calc['discount_value'],
                'discount_amount' => $calc['discount_amount'],
                'additional_expenses_total' => $calc['additional_expenses_total'],
                'total' => $calc['total'],
                'notes' => $data['notes'] ?? null,
                'status' => PurchaseInvoiceStatus::DRAFT,
                'created_by' => $user->id,
            ]);

            foreach ($calc['items'] as $itemRow) {
                $invoice->items()->create($itemRow);
            }

            foreach ($calc['expenses'] as $expenseRow) {
                $invoice->expenses()->create($expenseRow);
            }

            $this->createLinkedGoodsReceipt($invoice, $defaultWarehouse, $user);

            return $this->repository->findWithRelations($invoice->id);
        });
    }

    /**
     * Create a Purchase Invoice directly from an existing confirmed Purchase Order.
     *
     * @param array<string, mixed> $data
     */
    public function createFromPurchaseOrder(PurchaseOrder $purchaseOrder, array $data, User $user): PurchaseInvoice
    {
        $invoiceDate = ! empty($data['invoice_date'])
            ? Carbon::parse($data['invoice_date'])->toDateString()
            : Carbon::today()->toDateString();

        $dueDate = ! empty($data['due_date'])
            ? Carbon::parse($data['due_date'])->toDateString()
            : $invoiceDate;

        $payload = array_merge($data, [
            'purchase_order_id' => $purchaseOrder->id,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
        ]);

        return $this->createInvoice($payload, $user);
    }

    /**
     * Update an existing draft Purchase Invoice and synchronize its linked Draft Goods Receipt.
     *
     * @param array<string, mixed> $data
     */
    public function updateInvoice(PurchaseInvoice $invoice, array $data, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $user): PurchaseInvoice {
            /** @var PurchaseInvoice $lockedInvoice */
            $lockedInvoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot update a cancelled purchase invoice.'],
                ]);
            }

            /** @var InventoryDocument|null $linkedGr */
            $linkedGr = InventoryDocument::query()
                ->whereIn('referenceable_type', ['purchase_invoice', PurchaseInvoice::class])
                ->where('referenceable_id', $lockedInvoice->id)
                ->where('document_type', InventoryDocumentType::GOODS_RECEIPT->value)
                ->lockForUpdate()
                ->first();

            if ($lockedInvoice->hasPostedGoodsReceipt()
                || ($linkedGr && in_array($linkedGr->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true))) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot update a purchase invoice after its linked goods receipt is posted.'],
                ]);
            }

            $supplier = $this->resolveActiveSupplier((int) ($data['supplier_id'] ?? 0));
            $defaultWarehouse = $this->resolveDefaultActiveWarehouse();

            $calc = $this->calculateInvoiceData(
                $data['items'] ?? [],
                $data['discount_type'] ?? null,
                $data['discount_value'] ?? null,
                $data['expenses'] ?? []
            );

            $invoiceDate = Carbon::parse($data['invoice_date']);
            $dueDate = ! empty($data['due_date'])
                ? Carbon::parse($data['due_date'])
                : $invoiceDate->copy();

            if ($dueDate->lt($invoiceDate)) {
                throw ValidationException::withMessages([
                    'due_date' => ['Due date must be equal to or after the invoice date.'],
                ]);
            }

            $invoiceNumber = $this->resolveInvoiceNumberForUpdate(
                $lockedInvoice,
                $data['invoice_number'] ?? null
            );

            $lockedInvoice->update([
                'invoice_number' => $invoiceNumber,
                'supplier_id' => $supplier->id,
                'invoice_date' => $invoiceDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'subtotal' => $calc['subtotal'],
                'discount_type' => ! empty($data['discount_type']) ? $data['discount_type'] : null,
                'discount_value' => $calc['discount_value'],
                'discount_amount' => $calc['discount_amount'],
                'additional_expenses_total' => $calc['additional_expenses_total'],
                'total' => $calc['total'],
                'notes' => $data['notes'] ?? null,
            ]);

            $lockedInvoice->items()->delete();
            foreach ($calc['items'] as $itemRow) {
                $lockedInvoice->items()->create($itemRow);
            }

            $lockedInvoice->expenses()->delete();
            foreach ($calc['expenses'] as $expenseRow) {
                $lockedInvoice->expenses()->create($expenseRow);
            }

            if ($linkedGr) {
                $this->inventoryDocumentService->syncForPurchaseInvoice(
                    $linkedGr,
                    $lockedInvoice,
                    $defaultWarehouse->id
                );
            } else {
                $this->createLinkedGoodsReceipt($lockedInvoice, $defaultWarehouse, $user);
            }

            return $this->repository->findWithRelations($lockedInvoice->id);
        });
    }

    /**
     * Cancel a Draft Purchase Invoice and automatically cancel its unposted Goods Receipt.
     */
    public function cancelInvoice(PurchaseInvoice $invoice, User $user, ?string $reason = null): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $user, $reason): PurchaseInvoice {
            /** @var PurchaseInvoice $lockedInvoice */
            $lockedInvoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => ['Purchase invoice is already cancelled.'],
                ]);
            }

            /** @var InventoryDocument|null $linkedGr */
            $linkedGr = InventoryDocument::query()
                ->whereIn('referenceable_type', ['purchase_invoice', PurchaseInvoice::class])
                ->where('referenceable_id', $lockedInvoice->id)
                ->where('document_type', InventoryDocumentType::GOODS_RECEIPT->value)
                ->lockForUpdate()
                ->first();

            if ($lockedInvoice->hasPostedGoodsReceipt()
                || ($linkedGr && in_array($linkedGr->status, [InventoryDocumentStatus::POSTED, InventoryDocumentStatus::REVERSED], true))) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot cancel a purchase invoice after its linked goods receipt is posted.'],
                ]);
            }

            if ($reason !== null && trim($reason) !== '') {
                $trimmed = trim($reason);
                $prefix = $lockedInvoice->notes ? $lockedInvoice->notes . "\n" : '';
                $lockedInvoice->notes = $prefix . "[Cancellation Reason: {$trimmed}]";
            }

            $lockedInvoice->status = PurchaseInvoiceStatus::CANCELLED;
            $lockedInvoice->save();

            if ($linkedGr) {
                $this->inventoryDocumentWorkflowService->cancelForPurchaseInvoice($linkedGr, $user);
            }

            return $this->repository->findWithRelations($lockedInvoice->id);
        });
    }

    /**
     * Soft-delete a Draft Purchase Invoice whose Goods Receipt has not affected inventory.
     */
    public function deleteInvoice(PurchaseInvoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice): bool {
            /** @var PurchaseInvoice $lockedInvoice */
            $lockedInvoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->hasPostedGoodsReceipt()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot delete a purchase invoice after its linked goods receipt is posted.'],
                ]);
            }

            if ($lockedInvoice->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot delete a cancelled purchase invoice; cancelled invoices must remain for audit history.'],
                ]);
            }

            /** @var InventoryDocument|null $linkedGr */
            $linkedGr = InventoryDocument::query()
                ->whereIn('referenceable_type', ['purchase_invoice', PurchaseInvoice::class])
                ->where('referenceable_id', $lockedInvoice->id)
                ->where('document_type', InventoryDocumentType::GOODS_RECEIPT->value)
                ->lockForUpdate()
                ->first();

            if ($linkedGr) {
                $this->inventoryDocumentService->deleteForPurchaseInvoice($linkedGr);
            }

            $lockedInvoice->items()->delete();
            $lockedInvoice->expenses()->delete();

            return (bool) $lockedInvoice->delete();
        });
    }

    /**
     * Copy supplier, items, and discounts from a confirmed Purchase Order when creating an invoice.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function hydrateFromPurchaseOrder(int $purchaseOrderId, array $data): array
    {
        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::query()
            ->with('items')
            ->lockForUpdate()
            ->find($purchaseOrderId);

        if (! $order) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['Selected purchase order does not exist.'],
            ]);
        }

        if ($order->status !== PurchaseOrderStatus::CONVERTED) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['Purchase invoices can only be created from a confirmed purchase order.'],
            ]);
        }

        $activeInvoiceExists = PurchaseInvoice::query()
            ->where('purchase_order_id', $order->id)
            ->where('status', '!=', PurchaseInvoiceStatus::CANCELLED->value)
            ->exists();

        if ($activeInvoiceExists) {
            throw ValidationException::withMessages([
                'purchase_order_id' => ['An active purchase invoice has already been created from this purchase order.'],
            ]);
        }

        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = $order->supplier_id;
        } elseif ((int) $data['supplier_id'] !== (int) $order->supplier_id) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Supplier must match the supplier on the referenced purchase order.'],
            ]);
        }

        if (empty($data['items'])) {
            $data['items'] = $order->items->map(fn ($poItem): array => [
                'product_id' => $poItem->product_id,
                'quantity' => $poItem->quantity,
                'unit_price' => $poItem->unit_price,
                'discount_type' => $poItem->discount_type?->value,
                'discount_value' => $poItem->discount_value ?? 0,
            ])->all();
        }

        if (! array_key_exists('discount_type', $data)) {
            $data['discount_type'] = $order->discount_type?->value;
            $data['discount_value'] = $order->discount_value;
        }

        if (! array_key_exists('notes', $data) && $order->notes !== null) {
            $data['notes'] = $order->notes;
        }

        return $data;
    }

    /**
     * Create the automatic Draft Goods Receipt linked to a Purchase Invoice.
     */
    protected function createLinkedGoodsReceipt(PurchaseInvoice $invoice, Warehouse $warehouse, User $user): InventoryDocument
    {
        $itemsPayload = $invoice->items()->get()->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => $item->quantity,
            'notes' => null,
        ])->all();

        return $this->inventoryDocumentService->create([
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => Carbon::parse($invoice->invoice_date)->toDateString(),
            'destination_warehouse_id' => $warehouse->id,
            'referenceable_type' => $invoice->getMorphClass(),
            'referenceable_id' => $invoice->id,
            'notes' => "Automatic goods receipt for purchase invoice: {$invoice->invoice_number}",
            'items' => $itemsPayload,
        ], $user);
    }

    /**
     * Validate and resolve an active supplier contact.
     */
    protected function resolveActiveSupplier(int $supplierId): Contact
    {
        /** @var Contact|null $supplier */
        $supplier = Contact::query()
            ->where('id', $supplierId)
            ->where('is_supplier', true)
            ->where('is_active', true)
            ->first();

        if (! $supplier) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Selected supplier is invalid, inactive, or not marked as a supplier.'],
            ]);
        }

        return $supplier;
    }

    /**
     * Resolve the system's active default warehouse.
     */
    protected function resolveDefaultActiveWarehouse(): Warehouse
    {
        /** @var Warehouse|null $warehouse */
        $warehouse = Warehouse::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (! $warehouse) {
            throw ValidationException::withMessages([
                'warehouse' => ['No active default warehouse exists in the system to create the goods receipt.'],
            ]);
        }

        return $warehouse;
    }

    /**
     * Resolve invoice number on create (use manual input or generate automatically).
     */
    protected function resolveInvoiceNumberForCreate(?string $rawNumber, Carbon $invoiceDate): string
    {
        $trimmed = $rawNumber !== null ? trim($rawNumber) : '';

        if ($trimmed === '') {
            return $this->numberService->generateInvoiceNumber($invoiceDate);
        }

        $exists = PurchaseInvoice::withTrashed()
            ->where('invoice_number', $trimmed)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'invoice_number' => ['Purchase invoice number is already in use.'],
            ]);
        }

        return $trimmed;
    }

    /**
     * Resolve invoice number on update while preserving uniqueness.
     */
    protected function resolveInvoiceNumberForUpdate(PurchaseInvoice $lockedInvoice, ?string $rawNumber): string
    {
        if ($rawNumber === null || trim($rawNumber) === '') {
            return $lockedInvoice->invoice_number;
        }

        $trimmed = trim($rawNumber);

        if ($trimmed !== $lockedInvoice->invoice_number) {
            $exists = PurchaseInvoice::withTrashed()
                ->where('invoice_number', $trimmed)
                ->where('id', '!=', $lockedInvoice->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'invoice_number' => ['Purchase invoice number is already in use.'],
                ]);
            }
        }

        return $trimmed;
    }

    /**
     * Validate and calculate a percentage or fixed discount against an applicable base amount.
     *
     * @return array{0: string, 1: string} [normalized_discount_value, discount_amount]
     */
    protected function calculateDiscountAmount(
        string $applicableAmount,
        ?string $discountType,
        float|int|string|null $discountValue,
        string $errorKey,
        string $label
    ): array {
        $hasValue = $discountValue !== null && $discountValue !== '';
        $valStr = $hasValue ? number_format((float) $discountValue, 2, '.', '') : '0.00';

        if ($hasValue && bccomp($valStr, '0.00', 2) < 0) {
            throw ValidationException::withMessages([
                $errorKey => ["{$label} cannot be negative."],
            ]);
        }

        if ($discountType === null) {
            if (bccomp($valStr, '0.00', 2) > 0) {
                throw ValidationException::withMessages([
                    $errorKey => ["A type is required for {$label} when a discount value is provided."],
                ]);
            }

            return ['0.00', '0.00'];
        }

        if ($discountType === DiscountType::PERCENTAGE->value) {
            if (bccomp($valStr, '100.00', 2) > 0) {
                throw ValidationException::withMessages([
                    $errorKey => ["{$label} percentage cannot exceed 100%."],
                ]);
            }

            $amount = $this->roundBc(bcdiv(bcmul($applicableAmount, $valStr, 6), '100', 6), 2);

            return [$valStr, $amount];
        }

        if ($discountType === DiscountType::FIXED->value) {
            if (bccomp($valStr, $applicableAmount, 2) > 0) {
                throw ValidationException::withMessages([
                    $errorKey => ["{$label} amount cannot exceed the applicable subtotal."],
                ]);
            }

            return [$valStr, $valStr];
        }

        throw ValidationException::withMessages([
            $errorKey => ["Invalid {$label} type."],
        ]);
    }

    /**
     * Decimal-safe half-up rounding using bcmath.
     */
    protected function roundBc(string $value, int $scale = 2): string
    {
        $factor = bcpow('10', (string) $scale, 0);
        $scaled = bcmul($value, $factor, $scale + 4);

        if (bccomp($scaled, '0', $scale + 4) >= 0) {
            $adjusted = bcadd($scaled, '0.5', $scale + 4);
        } else {
            $adjusted = bcsub($scaled, '0.5', $scale + 4);
        }

        $intPart = bcadd($adjusted, '0', 0);

        return bcdiv($intPart, $factor, $scale);
    }
}
