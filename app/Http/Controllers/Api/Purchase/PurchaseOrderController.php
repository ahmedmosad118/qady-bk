<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\CancelPurchaseOrderRequest;
use App\Http\Requests\Purchase\StorePurchaseOrderRequest;
use App\Http\Requests\Purchase\UpdatePurchaseOrderRequest;
use App\Models\PurchaseOrder;
use App\Repositories\PurchaseOrderRepository;
use App\Services\Purchase\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderController extends Controller implements HasMiddleware
{
    public function __construct(
        protected PurchaseOrderRepository $repository,
        protected PurchaseOrderService $purchaseOrderService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.PurchaseOrder::class, only: ['store']),
            new Middleware('can:update,purchase_order', only: ['update']),
            new Middleware('can:cancel,purchase_order', only: ['cancel']),
        ];
    }

    /**
     * Display a paginated listing of purchase orders.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PurchaseOrder::class);

        $filters = [
            'search' => $request->input('search'),
            'supplier_id' => $request->input('supplier_id'),
            'status' => $request->input('status'),
            'order_date_from' => $request->input('order_date_from'),
            'order_date_to' => $request->input('order_date_to'),
            'delivery_date_from' => $request->input('delivery_date_from'),
            'delivery_date_to' => $request->input('delivery_date_to'),
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
     * Store a newly created purchase order.
     */
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $order = $this->purchaseOrderService->createOrder(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.order_created'),
            'data' => $order,
        ], 201);
    }

    /**
     * Display the specified purchase order with details.
     */
    public function show(PurchaseOrder $purchase_order): JsonResponse
    {
        $this->authorize('view', $purchase_order);

        $order = $this->repository->findWithRelations($purchase_order->id);

        return response()->json([
            'success' => true,
            'data' => $order,
        ]);
    }

    /**
     * Update the specified purchase order in storage.
     */
    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $order = $this->purchaseOrderService->updateOrder(
            $purchase_order,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.order_updated'),
            'data' => $order,
        ]);
    }

    /**
     * Remove the specified draft purchase order from storage.
     */
    public function destroy(PurchaseOrder $purchase_order): JsonResponse
    {
        $this->authorize('delete', $purchase_order);

        $this->purchaseOrderService->deleteOrder($purchase_order);

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.order_deleted'),
        ]);
    }

    /**
     * Confirm / convert the purchase order.
     */
    public function confirm(Request $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $this->authorize('confirm', $purchase_order);

        $order = $this->purchaseOrderService->confirmOrder(
            $purchase_order,
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.order_confirmed'),
            'data' => $order,
        ]);
    }

    /**
     * Cancel the purchase order.
     */
    public function cancel(CancelPurchaseOrderRequest $request, PurchaseOrder $purchase_order): JsonResponse
    {
        $reason = $request->validated('reason');
        $order = $this->purchaseOrderService->cancelOrder(
            $purchase_order,
            $request->user(),
            is_string($reason) ? $reason : null
        );

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.order_cancelled'),
            'data' => $order,
        ]);
    }

    /**
     * Stream the purchase order's attached file inline.
     */
    public function viewAttachment(PurchaseOrder $purchase_order): StreamedResponse
    {
        $this->authorize('view', $purchase_order);

        return $this->purchaseOrderService->streamAttachment($purchase_order, false);
    }

    /**
     * Download the purchase order's attached file.
     */
    public function downloadAttachment(PurchaseOrder $purchase_order): StreamedResponse
    {
        $this->authorize('view', $purchase_order);

        return $this->purchaseOrderService->streamAttachment($purchase_order, true);
    }
}
