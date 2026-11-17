<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PaymentMethodType;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\Permission\Models\Role;
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
        $this->user->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($this->user);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $this->visaMethod = PaymentMethod::query()->where('type', PaymentMethodType::VISA)->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->saleService = app(PosSaleService::class);
        $this->returnService = app(PosReturnService::class);

        $this->shift = $this->shiftService->openShift($this->user, 1000.00);

        $this->product = Product::factory()->create([
            'name' => 'تفسير ابن كثير',
            'barcode' => '6221000000003',
            'purchase_price' => 150.00,
            'selling_price' => 250.00,
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

    public function test_can_paginate_and_filter_pos_returns(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
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

        $response = $this->getJson('/api/pos/returns?search=' . urlencode($return->return_number));
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $return->id)
            ->assertJsonPath('data.0.sale.invoice_number', $sale->invoice_number)
            ->assertJsonPath('data.0.items_count', 1);

        $filteredByPayment = $this->getJson('/api/pos/returns?payment_method_id=' . $this->visaMethod->id);
        $filteredByPayment->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_can_show_pos_return_with_sale_items_and_inventory_documents(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
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

        $response = $this->getJson("/api/pos/returns/{$return->id}");
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $return->id)
            ->assertJsonPath('data.sale.invoice_number', $sale->invoice_number)
            ->assertJsonPath('data.shift.shift_number', $this->shift->shift_number)
            ->assertJsonPath('data.items.0.product.name', $this->product->name)
            ->assertJsonPath('data.items.0.sale_item.sale_id', $sale->id)
            ->assertJsonCount(1, 'data.inventory_documents')
            ->assertJsonPath('data.inventory_documents.0.referenceable_type', 'pos_return');
    }

    public function test_pos_sales_index_and_show_include_return_summary_and_returned_products(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
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

        $indexResponse = $this->getJson('/api/pos/sales?search=' . urlencode($sale->invoice_number));
        $indexResponse->assertOk()
            ->assertJsonPath('data.0.returns_count', 1);
        $this->assertEquals(250.0, (float) $indexResponse->json('data.0.returns_total_amount'));

        $showResponse = $this->getJson("/api/pos/sales/{$sale->id}");
        $showResponse->assertOk()
            ->assertJsonPath('data.returns.0.id', $return->id)
            ->assertJsonPath('data.returns.0.items.0.product.name', $this->product->name)
            ->assertJsonPath('data.returns.0.creator.id', $this->user->id);
    }

    public function test_partial_returns_with_fractional_quantities_and_discounts_preserve_decimal_precision(): void
    {
        // Sell 3.000 units at 33.33 = 99.99, item discount = 9.99 (fixed), invoice discount = 10.00 (fixed)
        // Line total = 90.00, allocated invoice discount = 10.00, net paid = 80.00, total discount = 19.99
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => '10.00',
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '3.000',
                    'unit_price' => '33.33',
                    'discount_type' => 'fixed',
                    'discount_value' => '9.99',
                ],
            ],
        ], $this->user);

        $this->assertSame('80.00', (string) $sale->grand_total);
        $saleItem = $sale->items()->firstOrFail();

        // 1. First partial return of 1.500 units (half of 3.000): refund = 40.00, allocated_discount = 10.00
        $return1 = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.500'],
            ],
        ], $this->user);

        $this->assertSame('40.00', (string) $return1->total_returned);
        $returnItem1 = $return1->items()->firstOrFail();
        $this->assertSame('1.500', (string) $returnItem1->quantity);
        $this->assertSame('40.00', (string) $returnItem1->refund_amount);
        $this->assertSame('10.00', (string) $returnItem1->allocated_discount);

        $returnable = $this->returnService->getReturnableItems($sale->fresh());
        $this->assertEquals(1.5, $returnable['items'][0]['already_returned_quantity']);
        $this->assertEquals(1.5, $returnable['items'][0]['remaining_returnable_quantity']);
        $this->assertSame('26.67', $returnable['items'][0]['net_paid_unit_price']);
        $this->assertTrue($returnable['items'][0]['can_return']);

        // 2. Attempt to return 1.501 units (exceeds remaining 1.500 by 0.001) -> must fail
        $overReturnResp = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.501'],
            ],
        ]);
        $overReturnResp->assertStatus(422);

        // 3. Return the remaining 1.500 units -> succeeds, remaining becomes 0, expected_cash returns to 1000.00
        $return2 = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.500'],
            ],
        ], $this->user);

        $this->assertSame('40.00', (string) $return2->total_returned);
        $this->shift->refresh();
        $this->assertSame('1000.00', (string) $this->shift->expected_cash);

        $returnableAfter = $this->returnService->getReturnableItems($sale->fresh());
        $this->assertEquals(0.0, $returnableAfter['items'][0]['remaining_returnable_quantity']);
        $this->assertFalse($returnableAfter['items'][0]['can_return']);
    }

    public function test_multiple_returns_for_same_product_and_defective_vs_inactive_rules(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '4.000'],
            ],
        ], $this->user);

        $saleItem = $sale->items()->firstOrFail();

        // Deactivate product in catalog: returns of previously sold valid items must still succeed
        $this->product->update(['is_active' => false]);

        // Return 1: 1.000 unit
        $ret1 = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.000']],
        ], $this->user);
        $this->assertSame('250.00', (string) $ret1->total_returned);

        // Return 2: 2.000 units (cumulative returned = 3.000, remaining = 1.000)
        $ret2 = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [['pos_sale_item_id' => $saleItem->id, 'quantity' => '2.000']],
        ], $this->user);
        $this->assertSame('500.00', (string) $ret2->total_returned);

        // Defective item cannot be returned
        try {
            $this->returnService->processReturn([
                'shift_id' => $this->shift->id,
                'pos_sale_id' => $sale->id,
                'items' => [['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.000', 'is_defective' => true]],
            ], $this->user);
            $this->fail('Expected ValidationException for defective item.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Duplicate lines in same return payload exceeding remaining 1.000 (0.600 + 0.500 = 1.100 > 1.000) must fail
        try {
            $this->returnService->processReturn([
                'shift_id' => $this->shift->id,
                'pos_sale_id' => $sale->id,
                'items' => [
                    ['pos_sale_item_id' => $saleItem->id, 'quantity' => '0.600'],
                    ['pos_sale_item_id' => $saleItem->id, 'quantity' => '0.500'],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException when cumulative lines exceed remaining quantity.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Return 3 (Full completion): remaining 1.000 unit
        $ret3 = $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.000']],
        ], $this->user);
        $this->assertSame('250.00', (string) $ret3->total_returned);
    }

    public function test_return_queries_remain_constant_for_1_10_and_50_items(): void
    {
        $products = Product::factory()->count(50)->create([
            'purchase_price' => 50.00,
            'selling_price' => 100.00,
        ]);

        foreach ($products as $product) {
            WarehouseStock::query()->create([
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $product->id,
                'quantity' => '100.000',
            ]);
        }

        $returnableQueryCounts = [];
        $processReturnQueryCounts = [];

        foreach ([1, 10, 50] as $itemCount) {
            $saleItemsPayload = $products->take($itemCount)->map(fn (Product $p): array => [
                'product_id' => $p->id,
                'quantity' => '2.000',
            ])->values()->all();

            $sale = $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->visaMethod->id,
                'items' => $saleItemsPayload,
            ], $this->user);

            // 1. Measure getReturnableItems query count on a fresh PosSale instance
            $freshSale = PosSale::query()->findOrFail($sale->id);
            DB::flushQueryLog();
            DB::enableQueryLog();

            $returnable = $this->returnService->getReturnableItems($freshSale);

            $returnableQueries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertCount($itemCount, $returnable['items']);
            $returnableQueryCounts[$itemCount] = count($returnableQueries);

            // 2. Measure processReturn query count for returning 1.000 of each of the $itemCount items
            $returnPayloadItems = $sale->items->map(fn ($saleItem): array => [
                'pos_sale_item_id' => $saleItem->id,
                'quantity' => '1.000',
            ])->values()->all();

            DB::flushQueryLog();
            DB::enableQueryLog();

            $posReturn = $this->returnService->processReturn([
                'shift_id' => $this->shift->id,
                'pos_sale_id' => $sale->id,
                'items' => $returnPayloadItems,
            ], $this->user);

            $processQueries = DB::getQueryLog();
            DB::disableQueryLog();

            $this->assertCount($itemCount, $posReturn->items);
            $processReturnQueryCounts[$itemCount] = count($processQueries);
        }

        // Query count for 1, 10, and 50 items must be identical (O(1), zero N+1 growth)
        $this->assertSame(
            $returnableQueryCounts[1],
            $returnableQueryCounts[10],
            'getReturnableItems query count must not grow between 1 and 10 items.'
        );
        $this->assertSame(
            $returnableQueryCounts[10],
            $returnableQueryCounts[50],
            'getReturnableItems query count must not grow between 10 and 50 items.'
        );

        $this->assertSame(
            $processReturnQueryCounts[1],
            $processReturnQueryCounts[10],
            'processReturn query count must not grow between 1 and 10 items.'
        );
        $this->assertSame(
            $processReturnQueryCounts[10],
            $processReturnQueryCounts[50],
            'processReturn query count must not grow between 10 and 50 items.'
        );
    }

    public function test_return_ignores_client_warehouse_id_and_rejects_missing_default_warehouse_without_fallback(): void
    {
        $secondaryWarehouse = Warehouse::query()->create([
            'code' => 'SEC-WH',
            'name' => 'Secondary Warehouse',
            'address' => 'Giza',
            'is_default' => false,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $secondaryWarehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '50.000',
        ]);

        // 1. Sell 2 units from default warehouse (stock: 10 -> 8)
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $this->user);

        $saleItem = $sale->items()->firstOrFail();

        // 2. Client sends fake/secondary warehouse_id in return request -> must be ignored and return to default warehouse
        $returnResp = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ]);
        $returnResp->assertCreated();

        // Default warehouse stock incremented (8 -> 9); secondary warehouse untouched (50)
        $defaultStock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');
        $secondaryStock = WarehouseStock::query()
            ->where('warehouse_id', $secondaryWarehouse->id)
            ->where('product_id', $this->product->id)
            ->value('quantity');

        $this->assertSame('9.000', (string) $defaultStock);
        $this->assertSame('50.000', (string) $secondaryStock);

        // Verify InventoryDocument destination_warehouse_id is the default warehouse
        $invDocDestWh = $returnResp->json('data.inventory_documents.0.destination_warehouse_id');
        $this->assertSame($this->warehouse->id, $invDocDestWh);

        // 3. When no default warehouse exists, return is rejected with 422 on warehouse (no random fallback)
        Warehouse::query()->update(['is_default' => false]);

        $noDefaultResp = $this->postJson('/api/pos/returns', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ]);
        $noDefaultResp->assertStatus(422);
        $noDefaultResp->assertJsonValidationErrors('warehouse');
    }
}
