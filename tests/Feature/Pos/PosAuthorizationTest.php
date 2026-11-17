<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PosShift $shift;
    protected PaymentMethod $cashMethod;
    protected Product $product;
    protected PosShiftService $shiftService;
    protected PosSaleService $saleService;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AdminUserSeeder::POS_PERMISSIONS as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        // User starts with ONLY basic selling permission (no discount, price_override, shift, expenses, or return)
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('pos.sell');
        Sanctum::actingAs($this->user);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->saleService = app(PosSaleService::class);
        $this->shift = $this->shiftService->openShift($this->user, 500.00);

        $this->product = Product::factory()->create([
            'name' => 'مختصر صحيح مسلم',
            'selling_price' => 100.00,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '10.000',
        ]);
    }

    public function test_unprivileged_user_is_denied_all_pos_operations_by_default(): void
    {
        $unprivilegedUser = User::factory()->create();
        Sanctum::actingAs($unprivilegedUser);

        $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => '00000000-1111-2222-3333-444444444444',
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertForbidden();

        $this->postJson('/api/pos/shifts', ['opening_balance' => 100])->assertForbidden();
        $this->postJson("/api/pos/shifts/{$this->shift->id}/close", ['actual_cash' => 500])->assertForbidden();
        $this->postJson("/api/pos/shifts/{$this->shift->id}/expenses", ['amount' => 50, 'name' => 'مصروف'])->assertForbidden();
        $this->postJson("/api/pos/shifts/{$this->shift->id}/withdrawals", ['amount' => 50, 'name' => 'سحب'])->assertForbidden();
        $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => 1,
            'items' => [['pos_sale_item_id' => 1, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_price_override_is_blocked_without_permission_and_allowed_when_granted(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 80.00,
                ],
            ],
        ];

        $this->postJson('/api/pos/sales', $payload)->assertForbidden();

        $this->user->givePermissionTo('pos.price_override');
        $this->postJson('/api/pos/sales', $payload)->assertCreated();
    }

    public function test_item_discount_is_blocked_without_permission_and_allowed_when_granted(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'discount_type' => 'fixed',
                    'discount_value' => 10.00,
                ],
            ],
        ];

        $this->postJson('/api/pos/sales', $payload)->assertForbidden();

        $this->user->givePermissionTo('pos.discount');
        $this->postJson('/api/pos/sales', $payload)->assertCreated();
    }

    public function test_invoice_discount_is_blocked_without_permission_and_allowed_when_granted(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 20.00,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ];

        $this->postJson('/api/pos/sales', $payload)->assertForbidden();

        $this->user->givePermissionTo('pos.discount');
        $this->postJson('/api/pos/sales', $payload)->assertCreated();
    }

    public function test_shift_management_is_blocked_without_permission_and_allowed_when_granted(): void
    {
        $this->postJson('/api/pos/shifts', ['opening_balance' => 100])->assertForbidden();
        $this->postJson("/api/pos/shifts/{$this->shift->id}/close", ['actual_cash' => 500])->assertForbidden();

        $this->user->givePermissionTo('pos.shift');

        $this->postJson('/api/pos/shifts', ['opening_balance' => 100])->assertCreated();
        $this->postJson("/api/pos/shifts/{$this->shift->id}/close", ['actual_cash' => 500])->assertOk();
    }

    public function test_shift_cash_transactions_are_blocked_without_permission_and_allowed_when_granted(): void
    {
        $this->postJson("/api/pos/shifts/{$this->shift->id}/expenses", [
            'amount' => 50.00,
            'name' => 'مصروف',
        ])->assertForbidden();

        $this->postJson("/api/pos/shifts/{$this->shift->id}/withdrawals", [
            'amount' => 50.00,
            'name' => 'سحب',
        ])->assertForbidden();

        $this->user->givePermissionTo('pos.expenses');

        $this->postJson("/api/pos/shifts/{$this->shift->id}/expenses", [
            'amount' => 50.00,
            'name' => 'مصروف',
        ])->assertCreated();

        $this->postJson("/api/pos/shifts/{$this->shift->id}/withdrawals", [
            'amount' => 50.00,
            'name' => 'سحب',
        ])->assertCreated();
    }

    public function test_returns_are_blocked_without_permission_and_allowed_when_granted(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ], $this->user);

        $saleItem = $sale->items()->firstOrFail();

        $returnPayload = [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ];

        $this->postJson('/api/pos/returns', $returnPayload)->assertForbidden();

        $this->user->givePermissionTo('pos.return');
        $this->postJson('/api/pos/returns', $returnPayload)->assertCreated();
    }

    public function test_offline_sync_price_override_is_blocked_without_permission(): void
    {
        $response = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => '11111111-2222-3333-4444-555555555555',
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 75.00,
                ],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_offline_sync_item_discount_is_blocked_without_permission(): void
    {
        $response = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => '22222222-3333-4444-5555-666666666666',
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'discount_type' => 'fixed',
                    'discount_value' => 15.00,
                ],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_offline_sync_invoice_discount_is_blocked_without_permission(): void
    {
        $response = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => '33333333-4444-5555-6666-777777777777',
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 20.00,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                ],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_role_based_permissions_authorize_assigned_pos_actions(): void
    {
        $cashierRole = Role::findOrCreate('cashier_supervisor', 'web');
        $cashierRole->givePermissionTo(['pos.sell', 'pos.discount', 'pos.return']);

        $roleUser = User::factory()->create();
        $roleUser->assignRole($cashierRole);
        Sanctum::actingAs($roleUser);

        // Allowed via role: sell + discount
        $saleResp = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 10.00,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ]);
        $saleResp->assertCreated();

        // Denied via role: price_override not granted to cashier_supervisor
        $overrideResp = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 50.00]],
        ]);
        $overrideResp->assertForbidden();
    }
}
