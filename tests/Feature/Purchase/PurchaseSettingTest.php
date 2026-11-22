<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Enums\PurchaseExpensesTreatment;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsSuperAdmin();
    }

    public function test_can_get_and_update_purchase_expenses_treatment_setting(): void
    {
        // Initial fetch initializes purchase settings record in settings table
        $getRes = $this->getJson('/api/settings/purchase');

        $getRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'purchase')
            ->assertJsonPath('data.expenses_treatment', PurchaseExpensesTreatment::SEPARATE_EXPENSE->value);

        $this->assertDatabaseHas('settings', [
            'type' => 'purchase',
        ]);

        // Update to landed_cost
        $putRes = $this->putJson('/api/settings/purchase', [
            'expenses_treatment' => PurchaseExpensesTreatment::LANDED_COST->value,
        ]);

        $putRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'purchase')
            ->assertJsonPath('data.expenses_treatment', PurchaseExpensesTreatment::LANDED_COST->value)
            ->assertJsonPath('data.data.expenses_treatment', PurchaseExpensesTreatment::LANDED_COST->value);

        $setting = Setting::query()->where('type', 'purchase')->firstOrFail();
        $this->assertSame(PurchaseExpensesTreatment::LANDED_COST->value, $setting->data['expenses_treatment']);

        // Update back to separate_expense
        $this->putJson('/api/settings/purchase', [
            'expenses_treatment' => PurchaseExpensesTreatment::SEPARATE_EXPENSE->value,
        ])->assertOk()
            ->assertJsonPath('data.expenses_treatment', PurchaseExpensesTreatment::SEPARATE_EXPENSE->value);
    }

    public function test_rejects_invalid_expenses_treatment_value(): void
    {
        $this->putJson('/api/settings/purchase', [
            'expenses_treatment' => 'invalid_option',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['expenses_treatment']);

        $this->putJson('/api/settings/purchase', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expenses_treatment']);
    }
}
