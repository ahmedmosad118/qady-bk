<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PaymentMethod;
use App\Models\PosHeldSale;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosHeldSaleService;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    protected PosHeldSaleService $heldSaleService;
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
        $this->heldSaleService = app(PosHeldSaleService::class);
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

    public function test_holding_sale_does_not_deduct_inventory(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'reference_note' => 'طاولة رقم 3',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5, 'unit_price' => 50.00],
            ],
        ];

        $response = $this->postJson('/api/pos/held-sales', $payload);
        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.reference_note', 'طاولة رقم 3');
        $response->assertJsonPath('data.grand_total', '250.00');

        $this->assertDatabaseHas('pos_held_sales', [
            'reference_note' => 'طاولة رقم 3',
        ]);

        // Verify inventory is completely untouched (still 20.000)
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('20.000', (string) $stock->quantity);

        // Verify no official pos_sales record created
        $this->assertEquals(0, PosSale::query()->count());
    }

    public function test_can_update_and_delete_held_sale(): void
    {
        $held = $this->heldSaleService->holdSale([
            'shift_id' => $this->shift->id,
            'reference_note' => 'العميل خالد',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $this->user);

        // Update quantity to 4
        $updateResp = $this->putJson("/api/pos/held-sales/{$held->id}", [
            'reference_note' => 'العميل خالد - تعديل',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4],
            ],
        ]);

        $updateResp->assertOk();
        $updateResp->assertJsonPath('data.reference_note', 'العميل خالد - تعديل');
        $updateResp->assertJsonPath('data.grand_total', '200.00');

        // Delete held sale
        $delResp = $this->deleteJson("/api/pos/held-sales/{$held->id}");
        $delResp->assertOk();
        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held->id]);
    }

    public function test_can_resume_held_sale_and_complete_official_sale(): void
    {
        $held = $this->heldSaleService->holdSale([
            'shift_id' => $this->shift->id,
            'reference_note' => 'طلب معلق',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4],
            ],
        ], $this->user);

        // Complete sale
        $salePayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 4],
            ],
        ];

        $completeResp = $this->postJson('/api/pos/sales', $salePayload);
        $completeResp->assertCreated();

        // Discard held cart
        $this->heldSaleService->deleteHeldSale($held);

        // Stock now deducted from 20 to 16
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('16.000', (string) $stock->quantity);
        $this->assertDatabaseMissing('pos_held_sales', ['id' => $held->id]);
    }
}
