<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Payment;

use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\OutstandingSupplierInvoicesRequest;
use App\Http\Requests\Payment\StoreSupplierPaymentRequest;
use App\Http\Requests\Payment\UpdateSupplierPaymentRequest;
use App\Http\Resources\Payment\SupplierPaymentResource;
use App\Models\Payment;
use App\Services\Payment\SupplierPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierPaymentController extends Controller
{
    public function __construct(
        protected SupplierPaymentService $supplierPaymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'payment_number' => $request->input('payment_number'),
            'contact_id' => $request->input('contact_id'),
            'supplier_id' => $request->input('supplier_id'),
            'status' => $request->input('status'),
            'payment_date' => $request->input('payment_date'),
            'payment_date_from' => $request->input('payment_date_from'),
            'payment_date_to' => $request->input('payment_date_to'),
            'payment_method_id' => $request->input('payment_method_id'),
            'journal_id' => $request->input('journal_id'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->supplierPaymentService->paginate($filters, $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => SupplierPaymentResource::collection($paginated->items())->resolve($request),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request): JsonResponse
    {
        $payment = $this->supplierPaymentService->create($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.created'),
            'data' => (new SupplierPaymentResource($payment))->resolve($request),
        ], 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->assertSupplierPayment($payment);

        $details = $this->supplierPaymentService->getDetails($payment);

        return response()->json([
            'success' => true,
            'data' => (new SupplierPaymentResource($details))->resolve($request),
        ]);
    }

    public function update(UpdateSupplierPaymentRequest $request, Payment $payment): JsonResponse
    {
        $this->assertSupplierPayment($payment);

        $updated = $this->supplierPaymentService->update($payment, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.updated'),
            'data' => (new SupplierPaymentResource($updated))->resolve($request),
        ]);
    }

    public function post(Request $request, Payment $payment): JsonResponse
    {
        $this->assertSupplierPayment($payment);

        $posted = $this->supplierPaymentService->post($payment, $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.posted'),
            'data' => (new SupplierPaymentResource($posted))->resolve($request),
        ]);
    }

    public function cancel(Request $request, Payment $payment): JsonResponse
    {
        $this->assertSupplierPayment($payment);

        $cancelled = $this->supplierPaymentService->cancel($payment, $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.cancelled'),
            'data' => (new SupplierPaymentResource($cancelled))->resolve($request),
        ]);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->assertSupplierPayment($payment);

        $this->supplierPaymentService->delete($payment);

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.deleted'),
        ]);
    }

    public function outstandingInvoices(OutstandingSupplierInvoicesRequest $request): JsonResponse
    {
        $invoices = $this->supplierPaymentService->getOutstandingInvoicesForSupplier(
            (int) $request->validated('supplier_id'),
        );

        return response()->json([
            'success' => true,
            'data' => $invoices,
        ]);
    }

    private function assertSupplierPayment(Payment $payment): void
    {
        if ($payment->payment_type !== PaymentType::SEND || $payment->related_type !== PaymentRelatedType::SUPPLIER) {
            abort(404);
        }
    }
}
