<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\UpdatePurchaseSettingRequest;
use App\Services\Purchase\PurchaseSettingService;
use Illuminate\Http\JsonResponse;

class PurchaseSettingController extends Controller
{
    public function __construct(
        protected PurchaseSettingService $purchaseSettingService,
    ) {}

    /**
     * Display the current purchase settings.
     */
    public function show(): JsonResponse
    {
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
