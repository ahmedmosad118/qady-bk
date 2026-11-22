<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\AccountPurpose;
use App\Enums\AccountType;
use App\Enums\PaymentMethodType;
use App\Models\ChartOfAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentMethodAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
    }

    public function test_seeder_links_default_payment_methods_and_preserves_customizations(): void
    {
        $cashAccount = ChartOfAccount::query()->where('code', '1001')->firstOrFail();
        $bankAccount = ChartOfAccount::query()->where('code', '1002')->firstOrFail();

        $cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $visaMethod = PaymentMethod::query()->where('type', PaymentMethodType::VISA)->firstOrFail();
        $bankMethod = PaymentMethod::query()->where('type', PaymentMethodType::BANK_TRANSFER)->firstOrFail();

        $this->assertSame($cashAccount->id, $cashMethod->chart_of_account_id);
        $this->assertSame($bankAccount->id, $visaMethod->chart_of_account_id);
        $this->assertSame($bankAccount->id, $bankMethod->chart_of_account_id);

        // Customize cash payment method mapping & name
        $altCash = ChartOfAccount::query()->create([
            'code' => '1011',
            'name' => 'صندوق المعرض',
            'account_type' => AccountType::ASSET->value,
            'account_purpose' => AccountPurpose::CASH->value,
        ]);

        $this->putJson("/api/payment-methods/{$cashMethod->id}", [
            'name' => 'خزينة المعرض',
            'chart_of_account_id' => $altCash->id,
        ])->assertOk()
            ->assertJsonPath('data.chart_of_account_id', $altCash->id);

        // Re-run seeders -> customized mapping & name preserved, no duplicates created
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $this->assertSame(3, PaymentMethod::query()->count());
        $cashMethod->refresh();
        $this->assertSame('خزينة المعرض', $cashMethod->name);
        $this->assertSame($altCash->id, $cashMethod->chart_of_account_id);
    }

    public function test_pos_and_management_endpoints_return_linked_account_info(): void
    {
        $this->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'type',
                        'chart_of_account_id',
                        'chart_of_account' => ['id', 'code', 'name', 'account_type', 'account_purpose'],
                    ],
                ],
                'compatible_accounts' => ['cash', 'visa', 'bank_transfer'],
            ]);

        $this->getJson('/api/pos/payment-methods')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'type', 'chart_of_account_id', 'chart_of_account'],
                ],
            ]);
    }

    public function test_validates_account_compatibility_by_payment_method_type(): void
    {
        $cashAccount = ChartOfAccount::query()->where('code', '1001')->firstOrFail();
        $bankAccount = ChartOfAccount::query()->where('code', '1002')->firstOrFail();
        $generalAsset = ChartOfAccount::query()->where('code', '1003')->firstOrFail();
        $expenseAccount = ChartOfAccount::query()->where('code', '5001')->firstOrFail();

        // Cash rejects bank, general, and expense accounts
        foreach ([$bankAccount->id, $generalAsset->id, $expenseAccount->id] as $badId) {
            $this->postJson('/api/payment-methods', [
                'name' => 'نقدي غير متوافق',
                'type' => PaymentMethodType::CASH->value,
                'chart_of_account_id' => $badId,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['chart_of_account_id']);
        }

        // Visa rejects cash, general, and expense accounts
        foreach ([$cashAccount->id, $generalAsset->id, $expenseAccount->id] as $badId) {
            $this->postJson('/api/payment-methods', [
                'name' => 'فيزا غير متوافقة',
                'type' => PaymentMethodType::VISA->value,
                'chart_of_account_id' => $badId,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['chart_of_account_id']);
        }

        // Bank transfer rejects cash account
        $this->postJson('/api/payment-methods', [
            'name' => 'تحويل غير متوافق',
            'type' => PaymentMethodType::BANK_TRANSFER->value,
            'chart_of_account_id' => $cashAccount->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['chart_of_account_id']);

        // Valid creations succeed
        $newCashPm = $this->postJson('/api/payment-methods', [
            'name' => 'صندوق إضافي',
            'type' => PaymentMethodType::CASH->value,
            'chart_of_account_id' => $cashAccount->id,
        ])->assertCreated()
            ->assertJsonPath('data.chart_of_account_id', $cashAccount->id)
            ->json('data');

        // Changing type to visa without updating cash account -> rejected
        $this->putJson("/api/payment-methods/{$newCashPm['id']}", [
            'type' => PaymentMethodType::VISA->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['chart_of_account_id']);

        // Updating both type and compatible bank account -> succeeds and preserves ID
        $this->putJson("/api/payment-methods/{$newCashPm['id']}", [
            'type' => PaymentMethodType::BANK_TRANSFER->value,
            'chart_of_account_id' => $bankAccount->id,
        ])->assertOk()
            ->assertJsonPath('data.id', $newCashPm['id'])
            ->assertJsonPath('data.type', PaymentMethodType::BANK_TRANSFER->value)
            ->assertJsonPath('data.chart_of_account_id', $bankAccount->id);
    }

    public function test_unauthorized_user_cannot_create_or_update_payment_methods(): void
    {
        $cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $cashAccount = ChartOfAccount::query()->where('code', '1001')->firstOrFail();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/payment-methods')->assertForbidden();
        $this->postJson('/api/payment-methods', [
            'name' => 'Unauthorized',
            'type' => PaymentMethodType::CASH->value,
            'chart_of_account_id' => $cashAccount->id,
        ])->assertForbidden();
        $this->putJson("/api/payment-methods/{$cashMethod->id}", [
            'name' => 'Unauthorized Update',
        ])->assertForbidden();
    }
}
