<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\CancelPurchaseInvoiceRequest;
use App\Http\Requests\Purchase\StorePurchaseInvoiceFromOrderRequest;
use App\Http\Requests\Purchase\StorePurchaseInvoiceRequest;
use App\Http\Requests\Purchase\UpdatePurchaseInvoiceRequest;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Repositories\PurchaseInvoiceRepository;
use App\Services\Purchase\PurchaseInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        protected PurchaseInvoiceRepository $repository,
        protected PurchaseInvoiceService $purchaseInvoiceService,
    ) {}

    /**
     * Display a paginated listing of purchase invoices.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'supplier_id' => $request->input('supplier_id'),
            'purchase_order_id' => $request->input('purchase_order_id'),
            'status' => $request->input('status'),
            'invoice_date_from' => $request->input('invoice_date_from'),
            'invoice_date_to' => $request->input('invoice_date_to'),
            'due_date_from' => $request->input('due_date_from'),
            'due_date_to' => $request->input('due_date_to'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->repository->paginate($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Store a newly created purchase invoice (direct or referencing a confirmed purchase order).
     */
    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->purchaseInvoiceService->createInvoice(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.invoice_created'),
            'data' => $invoice,
        ], 201);
    }

    /**
     * Create a purchase invoice from an existing confirmed purchase order.
     */
    public function storeFromPurchaseOrder(
        StorePurchaseInvoiceFromOrderRequest $request,
        PurchaseOrder $purchase_order
    ): JsonResponse {
        $invoice = $this->purchaseInvoiceService->createFromPurchaseOrder(
            $purchase_order,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.invoice_created_from_order'),
            'data' => $invoice,
        ], 201);
    }

    /**
     * Display the specified purchase invoice with details.
     */
    public function show(PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $invoice = $this->repository->findWithRelations($purchase_invoice->id);

        return response()->json([
            'success' => true,
            'data' => $invoice,
        ]);
    }

    /**
     * Update the specified draft purchase invoice and synchronize its draft goods receipt.
     */
    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $invoice = $this->purchaseInvoiceService->updateInvoice(
            $purchase_invoice,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.invoice_updated'),
            'data' => $invoice,
        ]);
    }

    /**
     * Soft-delete a draft purchase invoice that has not affected inventory.
     */
    public function destroy(PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $this->purchaseInvoiceService->deleteInvoice($purchase_invoice);

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.invoice_deleted'),
        ]);
    }

    /**
     * Cancel a draft purchase invoice and its linked unposted goods receipt.
     */
    public function cancel(CancelPurchaseInvoiceRequest $request, PurchaseInvoice $purchase_invoice): JsonResponse
    {
        $reason = $request->validated('reason');
        $invoice = $this->purchaseInvoiceService->cancelInvoice(
            $purchase_invoice,
            $request->user(),
            is_string($reason) ? $reason : null
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.invoice_cancelled'),
            'data' => $invoice,
        ]);
    }
}
