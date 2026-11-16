<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PaymentMethod;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
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

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->cashMethod = PaymentMethod::query()->where('name', 'نقدي')->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->saleService = app(PosSaleService::class);
        $this->shift = $this->shiftService->openShift($this->user, 500.00);

        $this->product = Product::factory()->create([
            'name' => 'مختصر صحيح مسلم',
            'selling_price' => 100.00,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '10.000',
        ]);
    }

    public function test_price_override_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.override_price', fn () => false);

        $response = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 80.00, // overridden price
                ],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_item_discount_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.apply_item_discount', fn () => false);

        $response = $this->postJson('/api/pos/sales', [
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
        ]);

        $response->assertForbidden();
    }

    public function test_invoice_discount_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.apply_invoice_discount', fn () => false);

        $response = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 20.00,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_shift_management_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.manage_shifts', fn () => false);

        // Open shift
        $openResp = $this->postJson('/api/pos/shifts', ['opening_balance' => 100]);
        $openResp->assertForbidden();

        // Close shift
        $closeResp = $this->postJson("/api/pos/shifts/{$this->shift->id}/close", ['actual_cash' => 500]);
        $closeResp->assertForbidden();
    }

    public function test_shift_cash_transactions_are_blocked_when_gate_denies(): void
    {
        Gate::define('pos.manage_shift_cash', fn () => false);

        $response = $this->postJson("/api/pos/shifts/{$this->shift->id}/expenses", [
            'amount' => 50.00,
            'name' => 'مصروف',
        ]);

        $response->assertForbidden();
    }

    public function test_returns_are_blocked_when_gate_denies(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ], $this->user);

        $saleItem = $sale->items()->first();

        Gate::define('pos.process_returns', fn () => false);

        $response = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ]);

        $response->assertForbidden();
    }

    public function test_offline_sync_price_override_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.override_price', fn () => false);

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

    public function test_offline_sync_item_discount_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.apply_item_discount', fn () => false);

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

    public function test_offline_sync_invoice_discount_is_blocked_when_gate_denies(): void
    {
        Gate::define('pos.apply_invoice_discount', fn () => false);

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
}
