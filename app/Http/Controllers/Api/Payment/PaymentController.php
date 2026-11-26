<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Payment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Requests\Payment\UpdatePaymentRequest;
use App\Http\Resources\Payment\PaymentResource;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'payment_number' => $request->input('payment_number'),
            'payment_type' => $request->input('payment_type'),
            'related_type' => $request->input('related_type'),
            'contact_id' => $request->input('contact_id'),
            'account_id' => $request->input('account_id'),
            'status' => $request->input('status'),
            'payment_date_from' => $request->input('payment_date_from'),
            'payment_date_to' => $request->input('payment_date_to'),
            'payment_method_id' => $request->input('payment_method_id'),
            'journal_id' => $request->input('journal_id'),
            'purchase_invoice_id' => $request->input('purchase_invoice_id'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->paymentService->paginate($filters, $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => PaymentResource::collection($paginated->items())->resolve($request),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $payment = $this->paymentService->create($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.generic_created'),
            'data' => (new PaymentResource($payment))->resolve($request),
        ], 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $details = $this->paymentService->getDetails($payment);

        return response()->json([
            'success' => true,
            'data' => (new PaymentResource($details))->resolve($request),
        ]);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): JsonResponse
    {
        $updated = $this->paymentService->update($payment, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.generic_updated'),
            'data' => (new PaymentResource($updated))->resolve($request),
        ]);
    }

    public function post(Request $request, Payment $payment): JsonResponse
    {
        $posted = $this->paymentService->post($payment, $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.generic_posted'),
            'data' => (new PaymentResource($posted))->resolve($request),
        ]);
    }

    public function cancel(Request $request, Payment $payment): JsonResponse
    {
        $cancelled = $this->paymentService->cancel($payment, $request->user());

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.generic_cancelled'),
            'data' => (new PaymentResource($cancelled))->resolve($request),
        ]);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        $this->paymentService->delete($payment);

        return response()->json([
            'success' => true,
            'message' => (string) __('payments.messages.generic_deleted'),
        ]);
    }
}
