<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosSyncTransaction;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosSyncAndOfflineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PosShift $shift;
    protected PaymentMethod $cashMethod;
    protected Product $product;
    protected PosShiftService $shiftService;

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
        $this->shift = $this->shiftService->openShift($this->user, 500.00);

        $this->product = Product::factory()->create([
            'name' => 'زاد المعاد',
            'sku' => 'BK-ZAAD-001',
            'barcode' => '6221000000005',
            'selling_price' => 300.00,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '5.000',
        ]);
    }

    public function test_can_retrieve_offline_bootstrap_catalog(): void
    {
        $response = $this->getJson('/api/pos/sync/bootstrap');
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.products'));
        $this->assertNotEmpty($response->json('data.payment_methods'));
        $this->assertNotEmpty($response->json('data.open_shifts'));
    }

    public function test_offline_sync_creates_sale_idempotently(): void
    {
        $uuid = (string) Str::uuid();

        $payload = [
            'client_uuid' => $uuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ];

        // 1. Initial sync
        $response1 = $this->postJson('/api/pos/sync/sales', $payload);
        $response1->assertCreated();
        $response1->assertJsonPath('success', true);
        $response1->assertJsonPath('status', 'synced');
        $response1->assertJsonPath('is_idempotent', false);

        $invoiceId = $response1->json('data.id');

        // Verify stock deducted to 3
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('3.000', (string) $stock->quantity);

        // 2. Duplicate sync with same UUID (network retry)
        $response2 = $this->postJson('/api/pos/sync/sales', $payload);
        $response2->assertOk();
        $response2->assertJsonPath('success', true);
        $response2->assertJsonPath('status', 'synced');
        $response2->assertJsonPath('is_idempotent', true);
        $response2->assertJsonPath('data.id', $invoiceId);

        // Verify only 1 sale row exists and stock remains 3 (not deducted again!)
        $this->assertEquals(1, PosSale::query()->count());
        $stock->refresh();
        $this->assertEquals('3.000', (string) $stock->quantity);
    }

    public function test_offline_sync_conflict_handling_preserves_payload_and_blocks_negative_stock(): void
    {
        $uuid = (string) Str::uuid();

        // Product has only 5 in stock; local transaction asks for 8
        $payload = [
            'client_uuid' => $uuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 8],
            ],
        ];

        $response = $this->postJson('/api/pos/sync/sales', $payload);
        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('status', 'conflict');

        // Verify no sale inserted into pos_sales
        $this->assertEquals(0, PosSale::query()->count());

        // Verify local transaction preserved in pos_sync_transactions with conflict reason
        $syncTx = PosSyncTransaction::query()->where('client_uuid', $uuid)->first();
        $this->assertNotNull($syncTx);
        $this->assertEquals(PosSyncTransaction::STATUS_CONFLICT, $syncTx->status);
        $this->assertNotNull($syncTx->conflict_reason);
        $this->assertNull($syncTx->pos_sale_id);

        // Verify stock untouched
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('5.000', (string) $stock->quantity);
    }
}
