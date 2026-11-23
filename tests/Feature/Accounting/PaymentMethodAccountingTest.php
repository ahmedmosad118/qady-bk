<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountTypeCode;
use App\Enums\PaymentMethodType;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\PaymentMethod;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PaymentMethodAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionsTableSeeder::class,
            AccountingSeeder::class,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo([
            'accounting.settings.view',
            'accounting.settings.update',
            'pos.sell',
        ]);
    }

    public function test_index_returns_only_active_bank_cash_accounts(): void
    {
        $bankCashType = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();
        $expenseType = AccountType::query()->where('code', AccountTypeCode::Expenses->value)->firstOrFail();

        $activeBankCash = Account::query()->where('code', '1101')->firstOrFail();

        $inactive = Account::query()->create([
            'account_type_id' => $bankCashType->id,
            'code' => '9902',
            'name' => 'خزينة معطلة',
            'is_active' => false,
        ]);

        $expenseAcc = Account::query()->create([
            'account_type_id' => $expenseType->id,
            'code' => '9951',
            'name' => 'مصروفات عامة',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/payment-methods')
            ->assertOk()
            ->assertJsonPath('success', true);

        foreach (PaymentMethodType::values() as $type) {
            $compatibleIds = collect($response->json("compatible_accounts.{$type}"))->pluck('id')->all();
            $this->assertContains($activeBankCash->id, $compatibleIds);
            $this->assertNotContains($inactive->id, $compatibleIds);
            $this->assertNotContains($expenseAcc->id, $compatibleIds);
        }
    }

    public function test_can_create_and_update_payment_method_with_active_bank_cash_account(): void
    {
        $cashAccount = Account::query()->where('code', '1101')->firstOrFail();
        $bankAccount = Account::query()->where('code', '1102')->firstOrFail();

        $createResponse = $this->actingAs($this->admin)
            ->postJson('/api/payment-methods', [
                'name' => 'تحويل بنكي',
                'type' => PaymentMethodType::BANK_TRANSFER->value,
                'account_id' => $bankAccount->id,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.account_id', $bankAccount->id)
            ->assertJsonPath('data.account.id', $bankAccount->id);

        $paymentMethodId = (int) $createResponse->json('data.id');

        $this->actingAs($this->admin)
            ->putJson("/api/payment-methods/{$paymentMethodId}", [
                'account_id' => $cashAccount->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $paymentMethodId)
            ->assertJsonPath('data.account_id', $cashAccount->id)
            ->assertJsonPath('data.account.code', '1101');

        $this->actingAs($this->admin)
            ->getJson('/api/pos/payment-methods')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'type', 'account_id', 'account'],
                ],
            ]);
    }

    public function test_rejects_missing_inactive_or_non_bank_cash_account(): void
    {
        $bankCashType = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();
        $currentAssetsType = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();

        $inactiveBankCash = Account::query()->create([
            'account_type_id' => $bankCashType->id,
            'code' => '9902',
            'name' => 'صندوق معطل',
            'is_active' => false,
        ]);

        $nonBankCash = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '9903',
            'name' => 'المخزون',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/payment-methods', [
                'name' => 'نقدي',
                'type' => PaymentMethodType::CASH->value,
                'account_id' => 999999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);

        $this->actingAs($this->admin)
            ->postJson('/api/payment-methods', [
                'name' => 'نقدي',
                'type' => PaymentMethodType::CASH->value,
                'account_id' => $inactiveBankCash->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);

        $this->actingAs($this->admin)
            ->postJson('/api/payment-methods', [
                'name' => 'فيزا',
                'type' => PaymentMethodType::VISA->value,
                'account_id' => $nonBankCash->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);

        $pm = PaymentMethod::query()->create([
            'name' => 'نقدي',
            'type' => PaymentMethodType::CASH->value,
            'account_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/payment-methods/{$pm->id}", [
                'account_id' => $nonBankCash->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id']);
    }
}
