<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PaymentMethodType;
use App\Models\InventoryDocument;
use App\Models\PaymentMethod;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosReturnService;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PosShift $shift;
    protected PaymentMethod $cashMethod;
    protected Product $product;
    protected PosShiftService $shiftService;
    protected PosSaleService $saleService;
    protected PosReturnService $returnService;

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
        $this->saleService = app(PosSaleService::class);
        $this->returnService = app(PosReturnService::class);

        $this->shift = $this->shiftService->openShift($this->user, 500.00);

        $this->product = Product::factory()->create([
            'name' => 'تفسير ابن كثير',
            'barcode' => '9789771112223',
            'selling_price' => 150.00,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '20.000',
        ]);
    }

    public function test_sale_with_client_uuid_is_strictly_idempotent(): void
    {
        $uuid = (string) Str::uuid();

        $payload = [
            'client_uuid' => $uuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 150.00],
            ],
        ];

        // First attempt (normal checkout)
        $resp1 = $this->postJson('/api/pos/sales', $payload);
        $resp1->assertCreated();
        $saleId = $resp1->json('data.id');
        $invoiceNum = $resp1->json('data.invoice_number');

        // Verify stock deducted by 2 (20 -> 18)
        $stock1 = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals('18.000', (string) $stock1);

        // Verify counts after first request
        $this->assertEquals(1, PosSale::query()->count());
        $this->assertEquals(1, InventoryDocument::query()->where('document_type', InventoryDocumentType::GOODS_DELIVERY)->count());
        $this->assertEquals(1, StockMovement::query()->count());
        $this->assertEquals(1, PosShiftTransaction::query()->where('type', PosShiftTransaction::TYPE_CASH_SALE)->count());

        // Second attempt with exact same client_uuid (simulating client retry after timeout)
        $resp2 = $this->postJson('/api/pos/sales', $payload);
        $resp2->assertSuccessful();
        $this->assertEquals($saleId, $resp2->json('data.id'));
        $this->assertEquals($invoiceNum, $resp2->json('data.invoice_number'));

        // Verify NO duplicate records created
        $this->assertEquals(1, PosSale::query()->count(), 'PosSale must not duplicate');
        $this->assertEquals(1, InventoryDocument::query()->where('document_type', InventoryDocumentType::GOODS_DELIVERY)->count(), 'InventoryDocument must not duplicate');
        $this->assertEquals(1, StockMovement::query()->count(), 'StockMovements must not duplicate');
        $this->assertEquals(1, PosShiftTransaction::query()->where('type', PosShiftTransaction::TYPE_CASH_SALE)->count(), 'Shift transaction must not duplicate');

        // Verify stock remains 18 (not deducted twice!)
        $stock2 = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals('18.000', (string) $stock2);
    }

    public function test_return_with_client_uuid_is_strictly_idempotent(): void
    {
        // 1. Create original sale
        $sale = $this->saleService->createSale([
            'client_uuid' => (string) Str::uuid(),
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 150.00],
            ],
        ], $this->user);

        $saleItem = $sale->items()->firstOrFail();
        $returnUuid = (string) Str::uuid();

        $returnPayload = [
            'client_uuid' => $returnUuid,
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ];

        // First return attempt
        $resp1 = $this->postJson('/api/pos/returns', $returnPayload);
        $resp1->assertCreated();
        $returnId = $resp1->json('data.id');

        $this->assertEquals(1, PosReturn::query()->count());
        $this->assertEquals(1, PosShiftTransaction::query()->where('type', PosShiftTransaction::TYPE_CASH_RETURN)->count());
        $this->assertEquals(1, InventoryDocument::query()->where('document_type', InventoryDocumentType::GOODS_RECEIPT)->count());

        // Stock was 18, returned 1 -> 19
        $stockAfterReturn = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals('19.000', (string) $stockAfterReturn);

        // Second return attempt with exact same client_uuid
        $resp2 = $this->postJson('/api/pos/returns', $returnPayload);
        $resp2->assertSuccessful();
        $this->assertEquals($returnId, $resp2->json('data.id'));

        // Verify NO duplicate return, NO duplicate cash refund, NO duplicate stock increment
        $this->assertEquals(1, PosReturn::query()->count());
        $this->assertEquals(1, PosShiftTransaction::query()->where('type', PosShiftTransaction::TYPE_CASH_RETURN)->count());
        $this->assertEquals(1, InventoryDocument::query()->where('document_type', InventoryDocumentType::GOODS_RECEIPT)->count());

        $finalStock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $this->assertEquals('19.000', (string) $finalStock);
    }

    public function test_defective_item_cannot_be_returned_while_inactive_catalog_product_is_allowed(): void
    {
        $sale = $this->saleService->createSale([
            'client_uuid' => (string) Str::uuid(),
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $this->user);

        $saleItem = $sale->items()->firstOrFail();

        // 1. Deactivating product in catalog (is_active = false) does NOT block returning a previously sold item
        $this->product->update(['is_active' => false]);

        $allowedResp = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ]);
        $allowedResp->assertCreated();

        // 2. Defective/damaged item is rejected
        $defectiveResp = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1, 'is_defective' => true],
            ],
        ]);

        $defectiveResp->assertUnprocessable();
        $defectiveResp->assertJsonValidationErrors('items');
    }

    public function test_basic_pos_sell_permission_is_enforced(): void
    {
        Gate::define('pos.sell', fn () => false);

        $response = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ]);

        $response->assertForbidden();
    }
}
