<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Repositories\PurchaseOrderRepository;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PurchaseOrderService
{
    public function __construct(
        protected PurchaseOrderRepository $repository,
        protected PurchaseDocumentNumberService $numberService,
        protected PurchaseDocumentCalculator $calculator,
        protected PurchaseSupplierResolver $supplierResolver,
        protected PurchaseLineWriter $lineWriter,
        protected PurchaseAttachmentService $attachmentService,
    ) {}

    /**
     * Calculate items, expenses, and totals via the shared pure calculator.
     *
     * `subtotal` is the gross items total before any discount (same meaning as on purchase invoices):
     * total = subtotal - item discounts - order discount + additional_expenses_total.
     *
     * @param  array<int, array<string, mixed>>  $rawItems
     * @param  array<int, array<string, mixed>>  $rawExpenses
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     expenses: array<int, array<string, mixed>>,
     *     subtotal: string,
     *     discount_value: string,
     *     discount_amount: string,
     *     additional_expenses_total: string,
     *     total: string
     * }
     */
    public function calculateOrderData(
        array $rawItems,
        ?string $discountType = null,
        mixed $discountValue = null,
        array $rawExpenses = []
    ): array {
        $products = Product::query()
            ->whereIn('id', $this->calculator->productIds($rawItems))
            ->get()
            ->keyBy('id');

        $calc = $this->calculator->calculate(
            $rawItems,
            $products,
            $discountType,
            $discountValue,
            $rawExpenses,
            unitName: 'قطعة',
        );

        return [
            'items' => $calc['items'],
            'expenses' => $calc['expenses'],
            'subtotal' => $calc['subtotal'],
            'discount_value' => $calc['discount_value'],
            'discount_amount' => $calc['discount_amount'],
            'additional_expenses_total' => $calc['additional_expenses_total'],
            'total' => $calc['total'],
        ];
    }

    /**
     * Create a new purchase order.
     *
     * @param  array<string, mixed>  $data
     */
    public function createOrder(array $data, User $user): PurchaseOrder
    {
        $trackedFiles = [];

        try {
            return DB::transaction(function () use ($data, $user, &$trackedFiles): PurchaseOrder {
                $supplier = $this->supplierResolver->resolveActive((int) ($data['supplier_id'] ?? 0));

                $calc = $this->calculateOrderData(
                    $data['items'],
                    $data['discount_type'] ?? null,
                    $data['discount_value'] ?? null,
                    $data['expenses'] ?? []
                );

                $orderDate = ! empty($data['order_date']) ? Carbon::parse($data['order_date']) : Carbon::today();

                /** @var PurchaseOrder $order */
                $order = PurchaseOrder::query()->create([
                    'order_number' => $this->numberService->placeholder(),
                    'supplier_id' => $supplier->id,
                    'order_date' => $orderDate->toDateString(),
                    'expected_delivery_date' => ! empty($data['expected_delivery_date'])
                        ? Carbon::parse($data['expected_delivery_date'])->toDateString()
                        : null,
                    'status' => PurchaseOrderStatus::DRAFT,
                    'notes' => $data['notes'] ?? null,
                    'subtotal' => $calc['subtotal'],
                    'discount_type' => $data['discount_type'] ?? null,
                    'discount_value' => $calc['discount_value'],
                    'discount_amount' => $calc['discount_amount'],
                    'additional_expenses_total' => $calc['additional_expenses_total'],
                    'total' => $calc['total'],
                    'created_by' => $user->id,
                ]);

                $this->numberService->assignOrderNumber($order);

                $this->lineWriter->insertOrderItems($order, $calc['items']);
                $this->lineWriter->insertOrderExpenses($order, $calc['expenses']);

                if (($data['attachment'] ?? null) instanceof UploadedFile) {
                    $order->update($this->attachmentService->storeFile($order, 'orders', $data['attachment'], $trackedFiles));
                }

                return $this->repository->findWithRelations($order->id);
            });
        } catch (Throwable $e) {
            $this->attachmentService->deleteStoredFiles($trackedFiles);
            throw $e;
        }
    }

    /**
     * Update an existing draft purchase order.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateOrder(PurchaseOrder $order, array $data, User $user): PurchaseOrder
    {
        $trackedFiles = [];

        try {
            return DB::transaction(function () use ($order, $data, &$trackedFiles): PurchaseOrder {
                /** @var PurchaseOrder $lockedOrder */
                $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);

                if (! $lockedOrder->isDraft()) {
                    throw ValidationException::withMessages([
                        'status' => [(string) __('purchases.errors.order_not_editable')],
                    ]);
                }

                $supplier = $this->supplierResolver->resolveActive((int) ($data['supplier_id'] ?? 0));

                $calc = $this->calculateOrderData(
                    $data['items'],
                    $data['discount_type'] ?? null,
                    $data['discount_value'] ?? null,
                    $data['expenses'] ?? []
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
                    'discount_value' => $calc['discount_value'],
                    'discount_amount' => $calc['discount_amount'],
                    'additional_expenses_total' => $calc['additional_expenses_total'],
                    'total' => $calc['total'],
                ]);

                // Replace line items & additional expenses
                $lockedOrder->items()->delete();
                $this->lineWriter->insertOrderItems($lockedOrder, $calc['items']);

                $lockedOrder->expenses()->delete();
                $this->lineWriter->insertOrderExpenses($lockedOrder, $calc['expenses']);

                $oldFilesToDelete = [];
                $newFile = ($data['attachment'] ?? null) instanceof UploadedFile ? $data['attachment'] : null;
                $removeAttachment = (bool) ($data['remove_attachment'] ?? false);
                $this->attachmentService->applyAttachmentUpdate(
                    $lockedOrder,
                    'orders',
                    $newFile,
                    $removeAttachment,
                    $trackedFiles,
                    $oldFilesToDelete,
                );
                $this->attachmentService->schedulePhysicalDeletion($oldFilesToDelete);

                return $this->repository->findWithRelations($lockedOrder->id);
            });
        } catch (Throwable $e) {
            $this->attachmentService->deleteStoredFiles($trackedFiles);
            throw $e;
        }
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
                    'status' => [(string) __('purchases.errors.order_delete_not_draft')],
                ]);
            }

            $attachmentTarget = $this->attachmentService->captureTarget($lockedOrder);

            $lockedOrder->items()->delete();
            $lockedOrder->expenses()->delete();
            $deleted = (bool) $lockedOrder->delete();

            if ($attachmentTarget !== null) {
                $this->attachmentService->schedulePhysicalDeletion([$attachmentTarget]);
            }

            return $deleted;
        });
    }

    /**
     * Stream the attached file for a purchase order.
     */
    public function streamAttachment(PurchaseOrder $order, bool $download = false): StreamedResponse
    {
        return $this->attachmentService->streamResponse($order, $download);
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
                    'status' => [(string) __('purchases.errors.order_already_confirmed')],
                ]);
            }

            if ($lockedOrder->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('purchases.errors.order_confirm_cancelled')],
                ]);
            }

            if ($lockedOrder->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => [(string) __('purchases.errors.order_no_items')],
                ]);
            }

            $this->supplierResolver->assertUsable($lockedOrder->supplier);

            // Mark converted atomically
            $lockedOrder->status = PurchaseOrderStatus::CONVERTED;
            $lockedOrder->confirmed_at = Carbon::now();
            $lockedOrder->confirmed_by = $user->id;
            $lockedOrder->save();

            // Note: Stock quantity is NOT modified here per business rules.
            // When the purchase invoice module is introduced, this is the exact integration point
            // to generate the purchase invoice.

            return $this->repository->findWithRelations($lockedOrder->id);
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
                    'status' => [(string) __('purchases.errors.order_cancel_confirmed')],
                ]);
            }

            if ($lockedOrder->isCancelled()) {
                throw ValidationException::withMessages([
                    'status' => [(string) __('purchases.errors.order_already_cancelled')],
                ]);
            }

            if (! empty($reason)) {
                $prefix = $lockedOrder->notes ? $lockedOrder->notes."\n" : '';
                $lockedOrder->notes = $prefix.__('purchases.messages.cancellation_reason_note', ['reason' => $reason]);
            }

            $lockedOrder->status = PurchaseOrderStatus::CANCELLED;
            $lockedOrder->save();

            return $this->repository->findWithRelations($lockedOrder->id);
        });
    }
}
