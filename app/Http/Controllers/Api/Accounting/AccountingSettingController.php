<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\UpdateAccountingSettingRequest;
use App\Services\Accounting\AccountingSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;

class AccountingSettingController extends Controller implements HasMiddleware
{
    public function __construct(
        protected AccountingSettingService $accountingSettingService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:accounting.settings.update', only: ['update']),
        ];
    }

    /**
     * Display current accounting settings, key definitions, and compatible accounts per key.
     */
    public function show(): JsonResponse
    {
        Gate::authorize('accounting.settings.view');

        $setting = $this->accountingSettingService->getSettings();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $setting->id,
                'type' => $setting->type,
                'data' => $setting->data,
                'expenses_treatment' => $this->accountingSettingService->resolveExpensesTreatment()->value,
                'default_purchase_journal_id' => $this->accountingSettingService->resolveDefaultPurchaseJournalId(),
                'purchase_journals' => $this->accountingSettingService->getPurchaseJournals(),
                'items' => $this->accountingSettingService->getStructuredSettings(),
                'compatible_accounts' => $this->accountingSettingService->getCompatibleAccountsByKey(),
            ],
        ]);
    }

    /**
     * Update accounting settings.
     */
    public function update(UpdateAccountingSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $setting = $this->accountingSettingService->updateSettings($validated['settings']);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.settings_updated'),
            'data' => [
                'id' => $setting->id,
                'type' => $setting->type,
                'data' => $setting->data,
                'expenses_treatment' => $this->accountingSettingService->resolveExpensesTreatment()->value,
                'default_purchase_journal_id' => $this->accountingSettingService->resolveDefaultPurchaseJournalId(),
                'purchase_journals' => $this->accountingSettingService->getPurchaseJournals(),
                'items' => $this->accountingSettingService->getStructuredSettings(),
                'compatible_accounts' => $this->accountingSettingService->getCompatibleAccountsByKey(),
            ],
        ]);
    }
}
