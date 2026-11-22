<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\ExpenseStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\UserStatus;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_unprivileged_user_receives_standardized_403_json_across_protected_modules(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $expectedPayload = [
            'success' => false,
            'message' => (string) __('auth.unauthorized'),
        ];

        $getEndpoints = [
            '/api/users',
            '/api/products',
            '/api/product-categories',
            '/api/contacts',
            '/api/purchase-orders',
            '/api/purchase-invoices',
            '/api/settings/purchase',
            '/api/settings/accounting',
            '/api/accounting/accounts/tree',
            '/api/warehouses',
            '/api/inventory-documents',
            '/api/warehouse-stocks',
            '/api/stock-movements',
            '/api/stocktakes',
            '/api/pos/sales',
            '/api/pos/shifts',
            '/api/pos/returns',
            '/api/pos/dashboard/stats',
            '/api/expenses',
            '/api/expense-categories',
        ];

        foreach ($getEndpoints as $uri) {
            $this->getJson($uri)
                ->assertForbidden()
                ->assertExactJson($expectedPayload);
        }

        $this->postJson('/api/products', [])
            ->assertForbidden()
            ->assertExactJson($expectedPayload);
    }

    public function test_user_with_exact_permission_can_access_allowed_endpoint_and_is_denied_others(): void
    {
        $this->actingAsUserWithPermissions(['products.view']);

        $this->getJson('/api/products')->assertOk();

        $this->postJson('/api/products', [
            'name' => 'Unauthorized Book',
            'purchase_price' => 10,
            'selling_price' => 20,
        ])->assertForbidden();

        $this->deleteJson('/api/products/1')->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_pos_cashier_can_use_cross_module_lookups_without_admin_write_access(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $this->actingAsUserWithPermissions(['pos.sell']);

        $this->getJson('/api/warehouses/default')->assertOk();
        $this->getJson('/api/product-categories')->assertOk();
        $this->getJson('/api/contacts')->assertOk();
        $this->getJson('/api/pos/payment-methods')->assertOk();
        $this->getJson('/api/pos/products')->assertOk();

        // Cashier with pos.sell can open and close their own shift inside POS terminal
        $shiftRes = $this->postJson('/api/pos/shifts', ['opening_balance' => 100])->assertCreated();
        $shiftId = $shiftRes->json('data.id');
        $this->postJson("/api/pos/shifts/{$shiftId}/close", ['actual_cash' => 100])->assertOk();

        $this->postJson('/api/products', ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/product-categories', ['name' => 'Forbidden'])->assertForbidden();
        $this->postJson('/api/contacts', ['name' => 'Forbidden'])->assertForbidden();
        $this->getJson('/api/users')->assertForbidden();
    }

    public function test_super_admin_bypasses_permission_checks_but_still_respects_model_policy_state_guards(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/expenses')->assertOk();

        $category = ExpenseCategory::factory()->create();
        $postedExpense = Expense::factory()->create([
            'expense_category_id' => $category->id,
            'status' => ExpenseStatus::POSTED,
            'created_by' => $superAdmin->id,
        ]);

        $supplier = Contact::factory()->create([
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);
        $cancelledInvoice = PurchaseInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $superAdmin->id,
            'status' => PurchaseInvoiceStatus::CANCELLED,
        ]);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('expenses.update'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $postedExpense));

        $this->assertTrue(Gate::forUser($superAdmin)->allows('purchase_invoices.update'));
        $this->assertFalse(Gate::forUser($superAdmin)->allows('update', $cancelledInvoice));
    }

    public function test_pos_sale_granular_discount_and_price_override_permissions_are_enforced(): void
    {
        $this->seed(WarehouseSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();

        $product = Product::factory()->create([
            'name' => 'كتاب الأصول',
            'purchase_price' => 50.00,
            'selling_price' => 100.00,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '20.000',
        ]);

        $cashier = $this->actingAsUserWithPermissions(['pos.sell', 'pos_shifts.manage']);
        $shift = app(PosShiftService::class)->openShift($cashier, 200.00);

        // Attempt price override without pos.override_price -> 403
        $this->postJson('/api/pos/sales', [
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 80.00],
            ],
        ])->assertForbidden();

        // Attempt invoice discount without pos.apply_invoice_discount -> 403
        $this->postJson('/api/pos/sales', [
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 10.00,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ])->assertForbidden();

        // Attempt item discount without pos.apply_item_discount -> 403
        $this->postJson('/api/pos/sales', [
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'discount_type' => 'fixed',
                    'discount_value' => 5.00,
                ],
            ],
        ])->assertForbidden();

        // Normal sale at catalog price with no discounts succeeds -> 201
        $this->postJson('/api/pos/sales', [
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100.00],
            ],
        ])->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_auth_login_and_me_return_is_super_admin_roles_and_permissions(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        $superAdmin = User::factory()->create([
            'email' => 'boss@kady.sys',
            'status' => UserStatus::ACTIVE,
            'password' => Hash::make('SecretPass1'),
        ]);
        $superAdmin->assignRole('Super Admin');

        $loginSuper = $this->postJson('/api/auth/login', [
            'email' => 'boss@kady.sys',
            'password' => 'SecretPass1',
        ])->assertOk();

        $loginSuper->assertJsonPath('data.user.is_super_admin', true)
            ->assertJsonPath('data.user.roles.0', 'Super Admin');
        $this->assertCount(82, $loginSuper->json('data.user.permissions'));

        $cashier = User::factory()->create([
            'email' => 'cashier@kady.sys',
            'status' => UserStatus::ACTIVE,
            'password' => Hash::make('SecretPass1'),
        ]);
        $cashier->assignRole('POS Cashier');

        $loginCashier = $this->postJson('/api/auth/login', [
            'email' => 'cashier@kady.sys',
            'password' => 'SecretPass1',
        ])->assertOk();

        $loginCashier->assertJsonPath('data.user.is_super_admin', false)
            ->assertJsonPath('data.user.roles.0', 'POS Cashier');

        $cashierPermissions = $loginCashier->json('data.user.permissions');
        $this->assertContains('pos.sell', $cashierPermissions);
        $this->assertNotContains('users.view', $cashierPermissions);

        $cashierToken = (string) $loginCashier->json('data.token');
        $this->withToken($cashierToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.is_super_admin', false)
            ->assertJsonPath('data.user.roles.0', 'POS Cashier');
    }
}
