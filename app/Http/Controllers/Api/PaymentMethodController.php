<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethod\UpdatePaymentMethodRequest;
use App\Models\PaymentMethod;
use App\Services\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PaymentMethodController extends Controller implements HasMiddleware
{
    public function __construct(
        protected PaymentMethodService $paymentMethodService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.PaymentMethod::class, only: ['store']),
            new Middleware('can:update,paymentMethod', only: ['update']),
        ];
    }

    /**
     * List all payment methods with linked accounts and compatible account options by type.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', PaymentMethod::class);

        return response()->json([
            'success' => true,
            'data' => $this->paymentMethodService->listAll(),
            'compatible_accounts' => $this->paymentMethodService->getCompatibleAccountsByType(),
        ]);
    }

    /**
     * Create a new payment method linked to a compatible Asset account.
     */
    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $paymentMethod = $this->paymentMethodService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.payment_method_created'),
            'data' => $paymentMethod,
        ], 201);
    }

    /**
     * Display a specific payment method with its linked account.
     */
    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('view', $paymentMethod);

        return response()->json([
            'success' => true,
            'data' => $paymentMethod->load('account:id,code,name,account_type_id,is_active'),
        ]);
    }

    /**
     * Update an existing payment method and validate account compatibility.
     */
    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $updated = $this->paymentMethodService->update($paymentMethod, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.payment_method_updated'),
            'data' => $updated,
        ]);
    }
}
