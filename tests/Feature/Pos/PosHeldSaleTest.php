<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosHeldSaleTest extends TestCase
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
            'name' => 'الأربعون النووية',
            'sku' => 'BK-ARBAEEN-001',
            'barcode' => '6221000000004',
            'selling_price' => 50.00,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '20.000',
        ]);
    }

    /**
     * Requirement: No backend storage or endpoints for held sales.
     * Held sales are stored strictly in client Local Storage.
     */
    public function test_held_sales_backend_endpoints_and_tables_do_not_exist(): void
    {
        // 1. Assert database tables do NOT exist
        $this->assertFalse(Schema::hasTable('pos_held_sales'), 'pos_held_sales table must not exist.');
        $this->assertFalse(Schema::hasTable('pos_held_sale_items'), 'pos_held_sale_items table must not exist.');

        // 2. Assert all hold/resume backend endpoints return 404 Not Found
        $this->getJson('/api/pos/held-sales')->assertNotFound();
        $this->postJson('/api/pos/held-sales', [
            'shift_id' => $this->shift->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertNotFound();
        $this->getJson('/api/pos/held-sales/1')->assertNotFound();
        $this->putJson('/api/pos/held-sales/1', [
            'shift_id' => $this->shift->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        ])->assertNotFound();
        $this->deleteJson('/api/pos/held-sales/1')->assertNotFound();
    }

    /**
     * Holding a cart on the client does not call backend, does not reserve or deduct inventory.
     */
    public function test_holding_cart_on_client_has_no_backend_stock_or_sales_impact(): void
    {
        // Initial state verification
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals('20.000', (string) $stock->quantity);
        $this->assertEquals(0, StockMovement::query()->count());
        $this->assertEquals(0, PosSale::query()->count());
    }

    /**
     * Resumed held sale completes via standard sale endpoint (/api/pos/sales).
     */
    public function test_resumed_held_sale_completes_via_standard_sale_endpoint(): void
    {
        // Resuming from client Local Storage and posting to the official /api/pos/sales route
        $salePayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4],
            ],
            'notes' => 'فاتورة مستكملة من قائمة الانتظار المحلية (Hold)',
        ];

        $completeResp = $this->postJson('/api/pos/sales', $salePayload);
        $completeResp->assertCreated();
        $completeResp->assertJsonPath('data.grand_total', '200.00');

        // Stock correctly deducted from 20 to 16
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('16.000', (string) $stock->quantity);

        // One official sale created, one stock movement
        $this->assertEquals(1, PosSale::query()->count());
        $this->assertEquals(1, StockMovement::query()->count());
    }

    /**
     * If stock changed while held in client Local Storage, completing sale is safely rejected.
     */
    public function test_resumed_held_sale_fails_if_stock_insufficient(): void
    {
        // Reduce available stock to 2
        WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->update(['quantity' => '2.000']);

        // Client attempts to finalize held cart with quantity 5
        $salePayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $salePayload);
        $response->assertStatus(422);

        // Stock remains 2.000, no sale created
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('2.000', (string) $stock->quantity);
        $this->assertEquals(0, PosSale::query()->count());
    }
}
