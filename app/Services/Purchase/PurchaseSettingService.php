<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Enums\PurchaseExpensesTreatment;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Validation\ValidationException;

class PurchaseSettingService
{
    public const SETTING_TYPE = 'purchase';

    public function __construct(
        protected SettingService $settingService,
    ) {}

    /**
     * Retrieve the system-wide purchase settings record.
     */
    public function getSettings(): Setting
    {
        return $this->settingService->getByType(self::SETTING_TYPE, [
            'expenses_treatment' => PurchaseExpensesTreatment::SEPARATE_EXPENSE->value,
        ]);
    }

    /**
     * Resolve the configured expenses treatment enum.
     */
    public function getExpensesTreatment(): PurchaseExpensesTreatment
    {
        $setting = $this->getSettings();
        $value = $setting->data['expenses_treatment'] ?? PurchaseExpensesTreatment::SEPARATE_EXPENSE->value;

        return PurchaseExpensesTreatment::tryFrom((string) $value)
            ?? PurchaseExpensesTreatment::SEPARATE_EXPENSE;
    }

    /**
     * Validate and update purchase settings.
     *
     * @param array<string, mixed> $data
     */
    public function updateSettings(array $data): Setting
    {
        $treatment = $data['expenses_treatment'] ?? null;

        if (! is_string($treatment) || ! in_array($treatment, PurchaseExpensesTreatment::values(), true)) {
            throw ValidationException::withMessages([
                'expenses_treatment' => [(string) __('purchases.errors.invalid_expenses_treatment')],
            ]);
        }

        return $this->settingService->updateByType(self::SETTING_TYPE, [
            'expenses_treatment' => $treatment,
        ]);
    }
}
