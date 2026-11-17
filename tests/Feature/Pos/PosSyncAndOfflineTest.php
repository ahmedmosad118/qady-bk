<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosSyncTransaction;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosShiftService;
use App\Services\Pos\PosSyncService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
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
        $this->user->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($this->user);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->shift = $this->shiftService->openShift($this->user, 500.00);

        $this->product = Product::factory()->create([
            'name' => 'زاد المعاد',
            'barcode' => '6221000000005',
            'selling_price' => 300.00,
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

    public function test_offline_sync_to_closed_shift_records_conflict_and_does_not_hijack_open_shift(): void
    {
        // 1. Close current shift
        $this->shiftService->closeShift($this->shift, $this->user, 500.00);
        $this->assertTrue($this->shift->fresh()->isClosed());

        // 2. Open another brand new shift
        $newShift = $this->shiftService->openShift($this->user, 200.00);
        $this->assertTrue($newShift->isOpen());

        $uuid = (string) Str::uuid();
        $payload = [
            'client_uuid' => $uuid,
            'shift_id' => $this->shift->id, // points to old closed shift
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/pos/sync/sales', $payload);
        $response->assertStatus(409);
        $response->assertJsonPath('status', 'conflict');

        // Verify sale was NOT created in newShift
        $this->assertEquals(0, PosSale::query()->count());
        $this->assertEquals(0, $newShift->sales()->count());

        // Verify conflict recorded preserving original shift_id
        $syncTx = PosSyncTransaction::query()->where('client_uuid', $uuid)->first();
        $this->assertNotNull($syncTx);
        $this->assertEquals(PosSyncTransaction::STATUS_CONFLICT, $syncTx->status);
        $this->assertStringContainsString('مغلقة', $syncTx->conflict_reason);
    }

    public function test_offline_sync_consolidates_duplicate_fractional_quantities_and_detects_stock_conflict_precisely(): void
    {
        // Product has 5.000 in stock. Duplicate lines 2.750 + 2.500 = 5.250 (> 5.000) -> must conflict
        $conflictUuid = (string) Str::uuid();
        $conflictResp = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => $conflictUuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '2.750'],
                ['product_id' => $this->product->id, 'quantity' => '2.500'],
            ],
        ]);
        $conflictResp->assertStatus(409);
        $conflictResp->assertJsonPath('status', 'conflict');

        // Exact fractional lines 2.250 + 2.750 = 5.000 (== 5.000) -> must sync cleanly
        $okUuid = (string) Str::uuid();
        $okResp = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => $okUuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '2.250'],
                ['product_id' => $this->product->id, 'quantity' => '2.750'],
            ],
        ]);
        $okResp->assertStatus(201);
        $okResp->assertJsonPath('status', 'synced');
        $okResp->assertJsonPath('data.grand_total', '1500.00');

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('0.000', (string) $stock->quantity);
    }

    public function test_offline_sync_handles_missing_product_and_missing_default_warehouse_as_conflicts_without_movements(): void
    {
        // 1. Missing product ID -> conflict (409), no PosSale, no StockMovement
        $missingProdUuid = (string) Str::uuid();
        $missingResp = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => $missingProdUuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => 999999, 'quantity' => 1],
            ],
        ]);

        $missingResp->assertStatus(409);
        $missingResp->assertJsonPath('status', 'conflict');
        $this->assertSame(0, PosSale::query()->count());
        $this->assertSame(0, StockMovement::query()->count());

        // 2. Non-default active warehouse exists, default warehouse disabled -> must NOT fallback!
        $secondaryWarehouse = Warehouse::query()->create([
            'code' => 'SEC-WH',
            'name' => 'Secondary Warehouse',
            'address' => 'Alexandria',
            'is_default' => false,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $secondaryWarehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '50.000',
        ]);

        // Even if client sends warehouse_id of secondary warehouse, sync ignores it and uses default warehouse
        Warehouse::query()->where('id', $this->warehouse->id)->update(['is_default' => false]);

        $noDefaultWhUuid = (string) Str::uuid();
        $noDefaultResp = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => $noDefaultWhUuid,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $noDefaultResp->assertStatus(409);
        $noDefaultResp->assertJsonPath('status', 'conflict');
        $this->assertSame(0, PosSale::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_offline_sync_revalidates_server_prices_and_permissions_and_ignores_client_totals(): void
    {
        // Client sends fake grand_total / subtotal and unpermitted price override
        Gate::define('pos.override_price', fn () => false);

        $blockedResp = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => (string) Str::uuid(),
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'subtotal' => '10.00',
            'grand_total' => '10.00',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 250.00],
            ],
        ]);
        $blockedResp->assertForbidden();
        $this->assertSame(0, PosSyncTransaction::query()->count());

        // When unit_price is omitted (or matches server price 300.00), server calculates true grand_total (2 * 300 = 600.00)
        // ignoring any tampered client subtotal/grand_total fields
        $validResp = $this->postJson('/api/pos/sync/sales', [
            'client_uuid' => (string) Str::uuid(),
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'subtotal' => '1.00',
            'grand_total' => '1.00',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ]);
        $validResp->assertStatus(201);
        $validResp->assertJsonPath('data.grand_total', '600.00');
    }

    public function test_concurrent_offline_sync_requests_for_same_product_prevent_overselling(): void
    {
        // Stock is 5.000. Two separate offline transactions each request 4.000 units of the same product.
        // First succeeds (leaving 1.000); second must record conflict without creating a sale or stock movement.
        /** @var PosSyncService $syncService */
        $syncService = app(PosSyncService::class);

        $res1 = $syncService->syncOfflineSale([
            'client_uuid' => (string) Str::uuid(),
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => '4.000']],
        ], $this->user);

        $res2 = $syncService->syncOfflineSale([
            'client_uuid' => (string) Str::uuid(),
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => '4.000']],
        ], $this->user);

        $this->assertSame('synced', $res1['status']);
        $this->assertSame('conflict', $res2['status']);
        $this->assertSame(1, PosSale::query()->count());
        $this->assertSame(1, StockMovement::query()->count());

        $remainingStock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertSame('1.000', (string) $remainingStock);
    }

    public function test_offline_sync_query_count_remains_constant_for_1_10_and_50_products(): void
    {
        $products = Product::factory()->count(50)->create([
            'purchase_price' => 40.00,
            'selling_price' => 80.00,
        ]);

        foreach ($products as $product) {
            WarehouseStock::query()->create([
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $product->id,
                'quantity' => '100.000',
            ]);
        }

        $this->user->load(['roles', 'permissions']);
        app(\Spatie\Permission\PermissionRegistrar::class)->getPermissions();
        $queryCounts = [];

        foreach ([1, 10, 50] as $count) {
            $itemsPayload = $products->take($count)->map(fn (Product $p): array => [
                'product_id' => $p->id,
                'quantity' => '1.000',
                'unit_price' => '80.00',
            ])->values()->all();

            DB::flushQueryLog();
            DB::enableQueryLog();

            $response = $this->postJson('/api/pos/sync/sales', [
                'client_uuid' => (string) Str::uuid(),
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'items' => $itemsPayload,
            ]);

            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $response->assertStatus(201);
            $queryCounts[$count] = count($queries);
        }

        $this->assertSame(
            $queryCounts[1],
            $queryCounts[10],
            'Offline sync query count must not grow between 1 and 10 products.'
        );
        $this->assertSame(
            $queryCounts[10],
            $queryCounts[50],
            'Offline sync query count must not grow between 10 and 50 products.'
        );
    }
}
