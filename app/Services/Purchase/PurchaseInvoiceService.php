<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\PurchaseInvoiceRepository;
use App\Services\Inventory\DefaultWarehouseResolver;
use App\Services\InventoryDocumentService;
use App\Services\InventoryDocumentWorkflowService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseInvoiceService
{
    /**
     * Attempts for transactions that lock the invoice and its goods receipt (InnoDB deadlock => retry).
     * Lock order everywhere: purchase invoice -> inventory document.
     */
    public const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        protected PurchaseInvoiceRepository $repository,
        protected InventoryDocumentService $inventoryDocumentService,
        protected InventoryDocumentWorkflowService $inventoryDocumentWorkflowService,
        protected PurchaseDocumentCalculator $calculator,
        protected PurchaseSupplierResolver $supplierResolver,
        protected PurchaseLineWriter $lineWriter,
        protected DefaultWarehouseResolver $defaultWarehouseResolver,
    ) {}

    /**
     * Calculate items, discounts, expenses and totals via the shared pure calculator.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @param  array<int, array<string, mixed>>  $rawExpenses
     * @return array<string, mixed>
     */
    protected function calculate(array $rawItems, ?string $discountType, mixed $discountValue, array $rawExpenses): array
    {
        $products = Product::query()
            ->whereIn('id', $this->calculator->productIds($rawItems))
            ->get()
            ->keyBy('id');

        return $this->calculator->calculate(
            $rawItems,
            $products,
            $discountType,
            $discountValue,
            $rawExpenses,
            unitName: 'piece',
        );
    }

    /**
     * Create a new draft Purchase Invoice (direct or from a confirmed Purchase Order)
     * and automatically create its linked Draft Goods Receipt inside one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createInvoice(array $data, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($data, $user): PurchaseInvoice {
            $purchaseOrderId = ! empty($data['purchase_order_id']) ? (int) $data['purchase_order_id'] : null;

            if ($purchaseOrderId !== null) {
                $data = $this->hydrateFromPurchaseOrder($purchaseOrderId, $data);
            }

            $supplier = $this->supplierResolver->resolveActive((int) ($data['supplier_id'] ?? 0));
            $defaultWarehouse = $this->resolveDefaultActiveWarehouse();

            $calc = $this->calculate(
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
                    'due_date' => [(string) __('purchases.errors.due_date_before_invoice')],
                ]);
            }

            /** @var PurchaseInvoice $invoice */
            $invoice = PurchaseInvoice::query()->create([
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

            $this->lineWriter->insertInvoiceItems($invoice, $calc['items']);
            $this->lineWriter->insertInvoiceExpenses($invoice, $calc['expenses']);

            $this->createLinkedGoodsReceipt($invoice, $defaultWarehouse, $user, $calc['items']);

            return $this->repository->findWithRelations($invoice->id);
        });
    }

    /**
     * Create a Purchase Invoice directly from an existing confirmed Purchase Order.
     *
     * @param  array<string, mixed>  $data
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
     * @param  array<string, mixed>  $data
     */
    public function updateInvoice(PurchaseInvoice $invoice, array $data, User $user): PurchaseInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $user): PurchaseInvoice {
            /** @var PurchaseInvoice $lockedInvoice */
            $lockedInvoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedInvoice->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('purchases.errors.invoice_update_cancelled')],
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
                    'status' => [(string) __('purchases.errors.invoice_update_posted')],
                ]);
            }

            $supplier = $this->supplierResolver->resolveActive((int) ($data['supplier_id'] ?? 0));
            $defaultWarehouse = $this->resolveDefaultActiveWarehouse();

            $calc = $this->calculate(
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
                    'due_date' => [(string) __('purchases.errors.due_date_before_invoice')],
                ]);
            }

            $lockedInvoice->update([
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
            $this->lineWriter->insertInvoiceItems($lockedInvoice, $calc['items']);

            $lockedInvoice->expenses()->delete();
            $this->lineWriter->insertInvoiceExpenses($lockedInvoice, $calc['expenses']);

            if ($linkedGr) {
                $this->inventoryDocumentService->syncForPurchaseInvoice(
                    $linkedGr,
                    $lockedInvoice,
                    $defaultWarehouse->id,
                    $this->goodsReceiptItems($calc['items'])
                );
            } else {
                $this->createLinkedGoodsReceipt($lockedInvoice, $defaultWarehouse, $user, $calc['items']);
            }

            return $this->repository->findWithRelations($lockedInvoice->id);
        }, self::TRANSACTION_ATTEMPTS);
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
                    'status' => [(string) __('purchases.errors.invoice_already_cancelled')],
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
                    'status' => [(string) __('purchases.errors.invoice_cancel_posted')],
                ]);
            }

            if ($reason !== null && trim($reason) !== '') {
                $trimmed = trim($reason);
                $prefix = $lockedInvoice->notes ? $lockedInvoice->notes."\n" : '';
                $lockedInvoice->notes = $prefix.__('purchases.messages.cancellation_reason_note', ['reason' => $trimmed]);
            }

            $lockedInvoice->status = PurchaseInvoiceStatus::CANCELLED;
            $lockedInvoice->save();

            if ($linkedGr) {
                $this->inventoryDocumentWorkflowService->cancelForPurchaseInvoice($linkedGr, $user);
            }

            return $this->repository->findWithRelations($lockedInvoice->id);
        }, self::TRANSACTION_ATTEMPTS);
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
                    'status' => [(string) __('purchases.errors.invoice_delete_posted')],
                ]);
            }

            if ($lockedInvoice->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('purchases.errors.invoice_delete_cancelled')],
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
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Copy supplier, items, and discounts from a confirmed Purchase Order when creating an invoice.
     *
     * @param  array<string, mixed>  $data
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
                'purchase_order_id' => [(string) __('purchases.errors.po_missing')],
            ]);
        }

        if ($order->status !== PurchaseOrderStatus::CONVERTED) {
            throw ValidationException::withMessages([
                'purchase_order_id' => [(string) __('purchases.errors.po_not_confirmed')],
            ]);
        }

        $activeInvoiceExists = PurchaseInvoice::query()
            ->where('purchase_order_id', $order->id)
            ->where('status', '!=', PurchaseInvoiceStatus::CANCELLED->value)
            ->exists();

        if ($activeInvoiceExists) {
            throw ValidationException::withMessages([
                'purchase_order_id' => [(string) __('purchases.errors.po_has_active_invoice')],
            ]);
        }

        if (empty($data['supplier_id'])) {
            $data['supplier_id'] = $order->supplier_id;
        } elseif ((int) $data['supplier_id'] !== (int) $order->supplier_id) {
            throw ValidationException::withMessages([
                'supplier_id' => [(string) __('purchases.errors.po_supplier_mismatch')],
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
     * Goods receipt lines built from the already-calculated invoice lines (no re-read from the DB).
     *
     * @param  array<int, array<string, mixed>>  $calcItems
     * @return array<int, array{product_id: int, quantity: string, notes: null}>
     */
    protected function goodsReceiptItems(array $calcItems): array
    {
        return array_map(static fn (array $item): array => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (string) $item['quantity'],
            'notes' => null,
        ], array_values($calcItems));
    }

    /**
     * Create the automatic Draft Goods Receipt linked to a Purchase Invoice.
     *
     * @param  array<int, array<string, mixed>>  $calcItems
     */
    protected function createLinkedGoodsReceipt(PurchaseInvoice $invoice, Warehouse $warehouse, User $user, array $calcItems): InventoryDocument
    {
        $itemsPayload = $this->goodsReceiptItems($calcItems);

        return $this->inventoryDocumentService->create([
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => Carbon::parse($invoice->invoice_date)->toDateString(),
            'destination_warehouse_id' => $warehouse->id,
            'referenceable_type' => $invoice->getMorphClass(),
            'referenceable_id' => $invoice->id,
            'notes' => __('purchases.messages.auto_goods_receipt_notes', ['number' => $invoice->invoice_number]),
            'items' => $itemsPayload,
        ], $user);
    }

    /**
     * Resolve the system's active default warehouse.
     */
    protected function resolveDefaultActiveWarehouse(): Warehouse
    {
        return $this->defaultWarehouseResolver->resolveOrFail('purchases.errors.no_default_warehouse', requireActive: true);
    }
}
