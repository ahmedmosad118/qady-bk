<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\UpdatePurchaseSettingRequest;
use App\Services\Purchase\PurchaseSettingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;

class PurchaseSettingController extends Controller implements HasMiddleware
{
    public function __construct(
        protected PurchaseSettingService $purchaseSettingService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchase_settings.update', only: ['update']),
        ];
    }

    /**
     * Display the current purchase settings.
     */
    public function show(): JsonResponse
    {
        if (! Gate::any(['purchase_settings.view', 'purchase_invoices.create', 'purchase_invoices.update'])) {
            throw new AuthorizationException;
        }

        $setting = $this->purchaseSettingService->getSettings();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $setting->id,
                'type' => $setting->type,
                'data' => $setting->data,
                'expenses_treatment' => $this->purchaseSettingService->getExpensesTreatment()->value,
            ],
        ]);
    }

    /**
     * Update the purchase settings.
     */
    public function update(UpdatePurchaseSettingRequest $request): JsonResponse
    {
        $setting = $this->purchaseSettingService->updateSettings($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('purchases.messages.settings_updated'),
            'data' => [
                'id' => $setting->id,
                'type' => $setting->type,
                'data' => $setting->data,
                'expenses_treatment' => $this->purchaseSettingService->getExpensesTreatment()->value,
            ],
        ]);
    }
}
