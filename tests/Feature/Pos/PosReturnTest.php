<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\StockMovementDirection;
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
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class PosReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PosShift $shift;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $visaMethod;
    protected Product $product;
    protected PosSaleService $saleService;
    protected PosShiftService $shiftService;
    protected PosReturnService $returnService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->cashMethod = PaymentMethod::query()->where('name', 'نقدي')->firstOrFail();
        $this->visaMethod = PaymentMethod::query()->where('name', 'فيزا')->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->saleService = app(PosSaleService::class);
        $this->returnService = app(PosReturnService::class);

        $this->shift = $this->shiftService->openShift($this->user, 1000.00);

        $this->product = Product::factory()->create([
            'name' => 'تفسير ابن كثير',
            'sku' => 'BK-TAFSIR-001',
            'barcode' => '6221000000003',
            'purchase_price' => 150.00,
            'selling_price' => 250.00,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '10.000',
        ]);
    }

    public function test_can_retrieve_returnable_items_for_sale(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
            ],
        ], $this->user);

        $response = $this->getJson("/api/pos/sales/{$sale->id}/returnable-items");
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.items.0.sold_quantity', 3);
        $response->assertJsonPath('data.items.0.already_returned_quantity', 0);
        $response->assertJsonPath('data.items.0.remaining_returnable_quantity', 3);
        $response->assertJsonPath('data.items.0.can_return', true);
    }

    public function test_partial_cash_return_refunds_money_and_returns_stock(): void
    {
        // 1. Sell 5 units of product (5 * 250 = 1250)
        // Stock goes from 10 to 5. Expected cash goes from 1000 to 2250.
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 5],
            ],
        ], $this->user);

        $saleItem = $sale->items()->first();

        // 2. Return 2 units (refund = 2 * 250 = 500)
        $payload = [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                [
                    'pos_sale_item_id' => $saleItem->id,
                    'quantity' => 2,
                ],
            ],
        ];

        $response = $this->postJson('/api/pos/returns', $payload);
        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.total_returned', '500.00');

        $returnNumber = $response->json('data.return_number');
        $this->assertNotEmpty($returnNumber);

        // Verify stock returned: 5 + 2 = 7
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('7.000', (string) $stock->quantity);

        // Verify inbound stock movement created
        $inboundMovement = StockMovement::query()
            ->where('product_id', $this->product->id)
            ->where('direction', StockMovementDirection::INBOUND)
            ->first();
        $this->assertNotNull($inboundMovement);
        $this->assertEquals('2.000', (string) $inboundMovement->quantity);

        // Verify inventory return document created and POSTED
        $posReturn = PosReturn::query()->where('return_number', $returnNumber)->firstOrFail();
        $invDoc = $posReturn->inventoryDocuments()->first();
        $this->assertNotNull($invDoc);
        $this->assertEquals(InventoryDocumentType::GOODS_RECEIPT, $invDoc->document_type);
        $this->assertEquals(InventoryDocumentStatus::POSTED, $invDoc->status);

        // Verify cash_return recorded in shift and cash deducted: 2250 - 500 = 1750
        $this->assertDatabaseHas('pos_shift_transactions', [
            'shift_id' => $this->shift->id,
            'pos_return_id' => $posReturn->id,
            'type' => 'cash_return',
            'amount' => '500.00',
        ]);

        $this->shift->refresh();
        $this->assertEquals('1750.00', $this->shift->expected_cash);
    }

    public function test_cannot_return_more_than_sold_quantity(): void
    {
        // Sell 3 units
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
            ],
        ], $this->user);

        $saleItem = $sale->items()->first();

        // 1. Return 2 units (valid)
        $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 2],
            ],
        ], $this->user);

        // 2. Try returning 2 more units (only 1 remaining => must fail!)
        $response = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
    }

    public function test_visa_return_does_not_affect_cash_balance(): void
    {
        // Sell 2 units via Visa
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->visaMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $this->user);

        $saleItem = $sale->items()->first();

        // Return 1 unit
        $response = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ]);

        $response->assertCreated();

        // Cash balance should remain exactly 1000.00 (opening balance)
        $this->shift->refresh();
        $this->assertEquals('1000.00', $this->shift->expected_cash);

        // No cash transactions in shift
        $this->assertEquals(0, PosShiftTransaction::query()->count());
    }

    public function test_pos_return_is_immutable(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ], $this->user);

        $saleItem = $sale->items()->first();

        $return = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ], $this->user);

        $this->expectException(LogicException::class);
        $return->update(['notes' => 'تعديل ممنوع']);
    }

    public function test_pos_return_cannot_be_deleted(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ], $this->user);

        $saleItem = $sale->items()->first();

        $return = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ], $this->user);

        $this->expectException(LogicException::class);
        $return->delete();
    }
}
