<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Enums\StockAdjustmentDirection;
use App\Enums\StockMovementDirection;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockMovementService;
use App\Services\InventoryDocumentWorkflowService;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class StockMovementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected Warehouse $destinationWarehouse;
    protected Product $product;
    protected Product $secondProduct;
    protected StockMovementService $stockMovementService;
    protected InventoryDocumentWorkflowService $workflowService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(WarehouseSeeder::class);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->destinationWarehouse = Warehouse::query()->create([
            'code' => 'BRANCH-1',
            'name' => 'فرع الإسكندرية',
            'address' => 'الإسكندرية',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->product = Product::factory()->create(['name' => 'كتاب الفقه الإسلامي', 'is_active' => true]);
        $this->secondProduct = Product::factory()->create(['name' => 'تاريخ الأدب العربي', 'is_active' => true]);

        $this->stockMovementService = app(StockMovementService::class);
        $this->workflowService = app(InventoryDocumentWorkflowService::class);
    }

    /**
     * Helper to create and approve an inventory document.
     */
    protected function createAndApproveDocument(
        InventoryDocumentType $type,
        array $items,
        ?int $sourceWhId = null,
        ?int $destWhId = null,
        ?StockAdjustmentDirection $adjDir = null,
        ?InventoryReturnDirection $retDir = null,
        ?string $writeOffReason = null
    ): InventoryDocument {
        $doc = InventoryDocument::query()->create([
            'document_number' => 'DOC-TEST-' . uniqid(),
            'document_type' => $type,
            'status' => InventoryDocumentStatus::DRAFT,
            'document_date' => '2026-11-14',
            'source_warehouse_id' => $sourceWhId,
            'destination_warehouse_id' => $destWhId,
            'adjustment_direction' => $adjDir,
            'return_direction' => $retDir,
            'write_off_reason' => $writeOffReason,
            'created_by' => $this->user->id,
        ]);

        foreach ($items as $item) {
            $doc->items()->create([
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'notes' => $item['notes'] ?? null,
            ]);
        }

        $this->workflowService->submit($doc, $this->user);
        return $this->workflowService->approve($doc, $this->user);
    }

    public function test_goods_receipt_creates_inbound_movement_and_increases_balance(): void
    {
        $doc = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 20]],
            destWhId: $this->warehouse->id
        );

        $posted = $this->workflowService->post($doc, $this->user);

        $this->assertEquals(InventoryDocumentStatus::POSTED, $posted->status);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_document_id' => $doc->id,
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => StockMovementDirection::INBOUND->value,
            'quantity' => '20.000',
        ]);

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($stock);
        $this->assertEquals('20.000', (string) $stock->quantity);
    }

    public function test_outbound_delivery_deducts_stock_when_available(): void
    {
        // First inbound to seed 15 units
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 15]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        // Outbound delivery of 6 units
        $delivery = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_DELIVERY,
            [['product_id' => $this->product->id, 'quantity' => 6]],
            sourceWhId: $this->warehouse->id
        );
        $this->workflowService->post($delivery, $this->user);

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertEquals('9.000', (string) $stock->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'inventory_document_id' => $delivery->id,
            'product_id' => $this->product->id,
            'direction' => StockMovementDirection::OUTBOUND->value,
            'quantity' => '6.000',
        ]);
    }

    public function test_negative_stock_is_strictly_blocked(): void
    {
        // No stock exists; attempt delivery of 5
        $delivery = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_DELIVERY,
            [['product_id' => $this->product->id, 'quantity' => 5]],
            sourceWhId: $this->warehouse->id
        );

        $this->expectException(ValidationException::class);
        $this->workflowService->post($delivery, $this->user);

        $this->assertDatabaseMissing('stock_movements', [
            'inventory_document_id' => $delivery->id,
        ]);
    }

    public function test_warehouse_transfer_creates_dual_atomic_movements(): void
    {
        // Receipt of 10 in main warehouse
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 10]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        // Transfer 4 from main warehouse to branch warehouse
        $transfer = $this->createAndApproveDocument(
            InventoryDocumentType::WAREHOUSE_TRANSFER,
            [['product_id' => $this->product->id, 'quantity' => 4]],
            sourceWhId: $this->warehouse->id,
            destWhId: $this->destinationWarehouse->id
        );
        $this->workflowService->post($transfer, $this->user);

        // Verify Source deducted: 10 - 4 = 6
        $sourceStock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('6.000', (string) $sourceStock->quantity);

        // Verify Destination added: 0 + 4 = 4
        $destStock = WarehouseStock::query()
            ->where('warehouse_id', $this->destinationWarehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('4.000', (string) $destStock->quantity);

        // Verify two movements created
        $this->assertDatabaseHas('stock_movements', [
            'inventory_document_id' => $transfer->id,
            'warehouse_id' => $this->warehouse->id,
            'direction' => StockMovementDirection::OUTBOUND->value,
            'quantity' => '4.000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'inventory_document_id' => $transfer->id,
            'warehouse_id' => $this->destinationWarehouse->id,
            'direction' => StockMovementDirection::INBOUND->value,
            'quantity' => '4.000',
        ]);
    }

    public function test_stock_adjustment_increase_and_decrease(): void
    {
        // 1. Adjustment Increase (+8)
        $adjInc = $this->createAndApproveDocument(
            InventoryDocumentType::STOCK_ADJUSTMENT,
            [['product_id' => $this->product->id, 'quantity' => 8]],
            destWhId: $this->warehouse->id,
            adjDir: StockAdjustmentDirection::INCREASE
        );
        $this->workflowService->post($adjInc, $this->user);

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('8.000', (string) $stock->quantity);

        // 2. Adjustment Decrease (-3)
        $adjDec = $this->createAndApproveDocument(
            InventoryDocumentType::STOCK_ADJUSTMENT,
            [['product_id' => $this->product->id, 'quantity' => 3]],
            sourceWhId: $this->warehouse->id,
            adjDir: StockAdjustmentDirection::DECREASE
        );
        $this->workflowService->post($adjDec, $this->user);

        $stock->refresh();
        $this->assertEquals('5.000', (string) $stock->quantity);
    }

    public function test_inventory_returns_and_write_off(): void
    {
        // Seed 10 via opening balance
        $ob = $this->createAndApproveDocument(
            InventoryDocumentType::OPENING_BALANCE,
            [['product_id' => $this->product->id, 'quantity' => 10]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($ob, $this->user);

        // Write off 2 units
        $wo = $this->createAndApproveDocument(
            InventoryDocumentType::STOCK_WRITE_OFF,
            [['product_id' => $this->product->id, 'quantity' => 2]],
            sourceWhId: $this->warehouse->id,
            writeOffReason: 'تلف بالماء'
        );
        $this->workflowService->post($wo, $this->user);

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('8.000', (string) $stock->quantity);

        // Inbound return of 1 unit
        $retIn = $this->createAndApproveDocument(
            InventoryDocumentType::INVENTORY_RETURN,
            [['product_id' => $this->product->id, 'quantity' => 1]],
            destWhId: $this->warehouse->id,
            retDir: InventoryReturnDirection::INBOUND
        );
        $this->workflowService->post($retIn, $this->user);

        $stock->refresh();
        $this->assertEquals('9.000', (string) $stock->quantity);

        // Outbound return of 4 units
        $retOut = $this->createAndApproveDocument(
            InventoryDocumentType::INVENTORY_RETURN,
            [['product_id' => $this->product->id, 'quantity' => 4]],
            sourceWhId: $this->warehouse->id,
            retDir: InventoryReturnDirection::OUTBOUND
        );
        $this->workflowService->post($retOut, $this->user);

        $stock->refresh();
        $this->assertEquals('5.000', (string) $stock->quantity);
    }

    public function test_reversing_posted_document_inverts_movements_and_updates_stocks(): void
    {
        // 1. Post goods receipt of 10
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 10]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        // 2. Reverse receipt
        $reversed = $this->workflowService->reverse($receipt, $this->user, 'خطأ في الكمية الموردة');
        $this->assertEquals(InventoryDocumentStatus::REVERSED, $reversed->status);

        // Balance must be back to 0
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->first();
        $this->assertEquals('0.000', (string) $stock->quantity);

        // Verify reversal movement created with reversal_of_id
        $orig = StockMovement::query()->where('inventory_document_id', $receipt->id)->whereNull('reversal_of_id')->first();
        $this->assertNotNull($orig);

        $revMov = StockMovement::query()->where('reversal_of_id', $orig->id)->first();
        $this->assertNotNull($revMov);
        $this->assertEquals(StockMovementDirection::OUTBOUND, $revMov->direction);
        $this->assertEquals('10.000', (string) $revMov->quantity);
    }

    public function test_reversal_is_blocked_if_it_would_cause_negative_stock(): void
    {
        // 1. Goods receipt of 10
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 10]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        // 2. Goods delivery of 8 (balance is now 2)
        $delivery = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_DELIVERY,
            [['product_id' => $this->product->id, 'quantity' => 8]],
            sourceWhId: $this->warehouse->id
        );
        $this->workflowService->post($delivery, $this->user);

        // 3. Attempting to reverse the receipt (which requires subtracting 10, but only 2 exist!)
        $this->expectException(ValidationException::class);
        $this->workflowService->reverse($receipt, $this->user, 'محاولة عكس بعد صرف المخزون');
    }

    public function test_stock_movement_is_immutable_and_cannot_be_updated_or_deleted(): void
    {
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 5]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        $mov = StockMovement::query()->firstOrFail();

        // Attempting to update must throw LogicException
        $this->expectException(LogicException::class);
        $mov->quantity = '99.000';
        $mov->save();
    }

    public function test_stock_movement_cannot_be_deleted(): void
    {
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 5]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        $mov = StockMovement::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $mov->delete();
    }

    public function test_product_or_warehouse_with_movements_cannot_be_deleted(): void
    {
        $receipt = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 5]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($receipt, $this->user);

        $this->expectException(LogicException::class);
        $this->product->delete();
    }

    public function test_stock_card_returns_chronological_movements_with_running_balance(): void
    {
        // 1. Receipt of 20
        $r1 = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 20]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($r1, $this->user);

        // 2. Delivery of 5
        $d1 = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_DELIVERY,
            [['product_id' => $this->product->id, 'quantity' => 5]],
            sourceWhId: $this->warehouse->id
        );
        $this->workflowService->post($d1, $this->user);

        // 3. Receipt of 10
        $r2 = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => 10]],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($r2, $this->user);

        $res = $this->getJson("/api/stock-movements/stock-card?product_id={$this->product->id}&warehouse_id={$this->warehouse->id}");
        $res->assertStatus(200);

        $this->assertEquals('25.000', $res->json('data.current_stock'));
        $movements = $res->json('data.movements');
        $this->assertCount(3, $movements);

        // Step 1: 0 + 20 = 20
        $this->assertEquals('20.000', $movements[0]['balance_after']);
        // Step 2: 20 - 5 = 15
        $this->assertEquals('15.000', $movements[1]['balance_after']);
        // Step 3: 15 + 10 = 25
        $this->assertEquals('25.000', $movements[2]['balance_after']);
    }

    public function test_warehouse_stocks_api_returns_paginated_list_and_stats(): void
    {
        $r = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [
                ['product_id' => $this->product->id, 'quantity' => 12],
                ['product_id' => $this->secondProduct->id, 'quantity' => 7],
            ],
            destWhId: $this->warehouse->id
        );
        $this->workflowService->post($r, $this->user);

        $res = $this->getJson("/api/warehouse-stocks?warehouse_id={$this->warehouse->id}");
        $res->assertStatus(200);
        $res->assertJsonCount(2, 'data');
    }

    public function test_post_and_reverse_through_authoritative_workflow_persist_stock_movement_models_with_database_ids(): void
    {
        $doc = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [
                ['product_id' => $this->product->id, 'quantity' => '10.000'],
                ['product_id' => $this->secondProduct->id, 'quantity' => '5.000'],
            ],
            destWhId: $this->warehouse->id
        );

        $postedDoc = $this->workflowService->post($doc, $this->user);
        $this->assertEquals(InventoryDocumentStatus::POSTED, $postedDoc->status);

        $postedMovements = $postedDoc->stockMovements()->whereNull('reversal_of_id')->orderBy('id')->get();
        $this->assertCount(2, $postedMovements);
        foreach ($postedMovements as $movement) {
            $this->assertInstanceOf(StockMovement::class, $movement);
            $this->assertNotNull($movement->id, 'Returned StockMovement must have a persisted database ID.');
            $this->assertTrue($movement->exists);
            $this->assertNull($movement->reversal_of_id);
        }

        $reversedDoc = $this->workflowService->reverse($postedDoc, $this->user, 'Reversal quantity correction');
        $this->assertEquals(InventoryDocumentStatus::REVERSED, $reversedDoc->status);

        $reversalMovements = $reversedDoc->stockMovements()->whereNotNull('reversal_of_id')->orderBy('id')->get();
        $this->assertCount(2, $reversalMovements);
        foreach ($reversalMovements as $index => $reversal) {
            $this->assertInstanceOf(StockMovement::class, $reversal);
            $this->assertNotNull($reversal->id, 'Returned reversal StockMovement must have a persisted database ID.');
            $this->assertTrue($reversal->exists);
            $this->assertSame($postedMovements[$index]->id, $reversal->reversal_of_id);
        }
    }

    public function test_direct_status_update_to_posted_without_movements_is_blocked_by_observer(): void
    {
        $doc = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => '5.000']],
            destWhId: $this->warehouse->id
        );

        $this->expectException(LogicException::class);
        $doc->update(['status' => InventoryDocumentStatus::POSTED]);
    }

    public function test_relation_loaded_does_not_bypass_observer_and_loaded_and_unloaded_behave_identically(): void
    {
        $docLoaded = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => '5.000']],
            destWhId: $this->warehouse->id
        );

        $fakeMovement = new StockMovement(['product_id' => $this->product->id, 'quantity' => '5.000']);
        $docLoaded->setRelation('stockMovements', new \Illuminate\Database\Eloquent\Collection([$fakeMovement]));

        $this->expectException(LogicException::class);
        $docLoaded->update(['status' => InventoryDocumentStatus::POSTED]);
    }

    public function test_observer_blocks_creating_posted_or_reversed_document_and_direct_reversals_or_service_bypass(): void
    {
        try {
            InventoryDocument::query()->create([
                'document_number' => 'DOC-BAD-CREATE-01',
                'document_type' => InventoryDocumentType::GOODS_RECEIPT,
                'status' => InventoryDocumentStatus::POSTED,
                'destination_warehouse_id' => $this->warehouse->id,
                'document_date' => '2026-11-14',
                'created_by' => $this->user->id,
            ]);
            $this->fail('Expected LogicException on direct POSTED document creation.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $doc = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_RECEIPT,
            [['product_id' => $this->product->id, 'quantity' => '5.000']],
            destWhId: $this->warehouse->id
        );

        try {
            $this->stockMovementService->postDocumentMovements($doc, $this->user->id);
            $this->fail('Expected LogicException when bypassing InventoryDocumentWorkflowService::post().');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $posted = $this->workflowService->post($doc, $this->user);

        try {
            $posted->update(['status' => InventoryDocumentStatus::REVERSED]);
            $this->fail('Expected LogicException on direct status update to REVERSED.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $posted->fresh()->update(['status' => InventoryDocumentStatus::DRAFT]);
            $this->fail('Expected LogicException on backward status mutation from POSTED.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
        $this->assertSame('5.000', (string) $stock->quantity);
    }

    public function test_post_and_reverse_query_counts_remain_constant_for_1_10_50_and_100_items(): void
    {
        $products = Product::factory()->count(100)->create([
            'purchase_price' => 50,
            'selling_price' => 100,
        ]);

        $postQueryCounts = [];
        $reverseQueryCounts = [];

        foreach ([1, 10, 50, 100] as $itemCount) {
            $items = $products->take($itemCount)->map(fn (Product $p): array => [
                'product_id' => $p->id,
                'quantity' => '5.000',
            ])->values()->all();

            $doc = $this->createAndApproveDocument(
                InventoryDocumentType::GOODS_RECEIPT,
                $items,
                destWhId: $this->warehouse->id
            );

            // Pre-ensure warehouse_stocks rows exist so we measure steady-state posting across all sizes
            foreach ($products->take($itemCount) as $p) {
                WarehouseStock::query()->firstOrCreate(
                    ['warehouse_id' => $this->warehouse->id, 'product_id' => $p->id],
                    ['quantity' => '10.000']
                );
            }

            DB::flushQueryLog();
            DB::enableQueryLog();

            $postedDoc = $this->workflowService->post($doc, $this->user);

            $postQueries = DB::getQueryLog();
            DB::disableQueryLog();

            $postQueryCounts[$itemCount] = count($postQueries);

            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->workflowService->reverse($postedDoc, $this->user, 'اختبار الأداء');

            $reverseQueries = DB::getQueryLog();
            DB::disableQueryLog();

            $reverseQueryCounts[$itemCount] = count($reverseQueries);
        }

        // O(1) constant query count across 1, 10, 50, and 100 items
        $this->assertSame($postQueryCounts[1], $postQueryCounts[10]);
        $this->assertSame($postQueryCounts[10], $postQueryCounts[50]);
        $this->assertSame($postQueryCounts[50], $postQueryCounts[100]);

        $this->assertSame($reverseQueryCounts[1], $reverseQueryCounts[10]);
        $this->assertSame($reverseQueryCounts[10], $reverseQueryCounts[50]);
        $this->assertSame($reverseQueryCounts[50], $reverseQueryCounts[100]);
    }

    public function test_failed_multi_item_posting_rolls_back_all_stock_and_movements_cleanly(): void
    {
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '10.000',
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->secondProduct->id,
            'quantity' => '1.000',
        ]);

        $doc = $this->createAndApproveDocument(
            InventoryDocumentType::GOODS_DELIVERY,
            [
                ['product_id' => $this->product->id, 'quantity' => '5.000'],
                ['product_id' => $this->secondProduct->id, 'quantity' => '5.000'],
            ],
            sourceWhId: $this->warehouse->id
        );

        try {
            $this->workflowService->post($doc, $this->user);
            $this->fail('Expected ValidationException on second insufficient item.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertEquals(InventoryDocumentStatus::APPROVED, $doc->fresh()->status);
        $this->assertSame(0, StockMovement::query()->where('inventory_document_id', $doc->id)->count());
        $this->assertSame('10.000', (string) WarehouseStock::query()->where('warehouse_id', $this->warehouse->id)->where('product_id', $this->product->id)->value('quantity'));
        $this->assertSame('1.000', (string) WarehouseStock::query()->where('warehouse_id', $this->warehouse->id)->where('product_id', $this->secondProduct->id)->value('quantity'));
    }
}
