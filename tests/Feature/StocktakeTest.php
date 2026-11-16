<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\StockAdjustmentDirection;
use App\Enums\StockMovementDirection;
use App\Enums\StocktakeItemStatus;
use App\Enums\StocktakeScope;
use App\Enums\StocktakeStatus;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\Product;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockMovementService;
use App\Services\Inventory\StocktakeService;
use App\Services\Inventory\StocktakeWorkflowService;
use App\Services\InventoryDocumentWorkflowService;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $counterUser;
    protected Warehouse $warehouse;
    protected Warehouse $secondWarehouse;
    protected Product $activeProduct1;
    protected Product $activeProduct2;
    protected Product $inactiveProductWithStock;
    protected Product $inactiveProductZeroStock;
    protected StocktakeService $stocktakeService;
    protected StocktakeWorkflowService $workflowService;
    protected StockMovementService $movementService;
    protected InventoryDocumentWorkflowService $docWorkflowService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->counterUser = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(WarehouseSeeder::class);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->secondWarehouse = Warehouse::query()->create([
            'code' => 'WH-BRANCH-01',
            'name' => 'فرع الجيزة',
            'address' => 'شارع التحرير، الدقي',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->activeProduct1 = Product::factory()->create([
            'name' => 'صحيح البخاري',
            'is_active' => true,
        ]);

        $this->activeProduct2 = Product::factory()->create([
            'name' => 'رياض الصالحين',
            'is_active' => true,
        ]);

        $this->inactiveProductWithStock = Product::factory()->create([
            'name' => 'كتاب نادر غير نشط ذو رصيد',
            'is_active' => false,
        ]);

        $this->inactiveProductZeroStock = Product::factory()->create([
            'name' => 'كتاب ملغي بدون رصيد',
            'is_active' => false,
        ]);

        // Seed stock for inactive product 1
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->inactiveProductWithStock->id,
            'quantity' => '5.000',
        ]);

        // Seed stock for active product 1
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->activeProduct1->id,
            'quantity' => '50.000',
        ]);

        $this->stocktakeService = app(StocktakeService::class);
        $this->workflowService = app(StocktakeWorkflowService::class);
        $this->movementService = app(StockMovementService::class);
        $this->docWorkflowService = app(InventoryDocumentWorkflowService::class);
    }

    public function test_can_create_draft_full_and_selected_stocktake(): void
    {
        // 1. Create FULL scope stocktake
        $responseFull = $this->postJson('/api/stocktakes', [
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::FULL->value,
            'stocktake_date' => '2026-11-14',
            'notes' => 'جرد سنوي كامل',
        ]);

        $responseFull->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.scope', 'full')
            ->assertJsonPath('data.status', 'draft');

        $stocktakeId = $responseFull->json('data.id');
        $this->assertDatabaseHas('stocktakes', [
            'id' => $stocktakeId,
            'warehouse_id' => $this->warehouse->id,
            'scope' => 'full',
            'status' => 'draft',
        ]);

        // 2. Create SELECTED scope stocktake
        $responseSelected = $this->postJson('/api/stocktakes', [
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'notes' => 'جرد عشوائي لمنتج محدد',
            'product_ids' => [$this->activeProduct1->id],
        ]);

        $responseSelected->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.scope', 'selected');

        $selectedId = $responseSelected->json('data.id');
        $this->assertDatabaseHas('stocktake_items', [
            'stocktake_id' => $selectedId,
            'product_id' => $this->activeProduct1->id,
            'count_status' => 'pending',
            'system_quantity' => null,
            'counted_quantity' => null,
        ]);
    }

    public function test_selected_stocktake_rejects_empty_or_duplicate_products(): void
    {
        // Empty product_ids
        $resEmpty = $this->postJson('/api/stocktakes', [
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [],
        ]);
        $resEmpty->assertStatus(422);

        // Duplicate products
        $resDuplicate = $this->postJson('/api/stocktakes', [
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id, $this->activeProduct1->id],
        ]);
        $resDuplicate->assertStatus(422);
    }

    public function test_starting_full_stocktake_includes_active_and_inactive_with_stock(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::FULL->value,
            'stocktake_date' => '2026-11-14',
        ], $this->user);

        $this->assertEquals(0, $stocktake->items()->count());

        // Start stocktake
        $started = $this->workflowService->start($stocktake, $this->user);
        $this->assertEquals(StocktakeStatus::IN_PROGRESS, $started->status);

        $productIds = $started->items()->pluck('product_id')->all();

        // Must include active products
        $this->assertContains($this->activeProduct1->id, $productIds);
        $this->assertContains($this->activeProduct2->id, $productIds);

        // Must include inactive product with stock > 0
        $this->assertContains($this->inactiveProductWithStock->id, $productIds);

        // Must NOT include inactive product with zero stock
        $this->assertNotContains($this->inactiveProductZeroStock->id, $productIds);
    }

    public function test_cannot_start_two_active_stocktakes_for_same_warehouse(): void
    {
        $stocktake1 = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $stocktake2 = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct2->id],
        ], $this->user);

        // Start first
        $this->workflowService->start($stocktake1, $this->user);

        // Attempting to start second on same warehouse must fail
        $this->expectException(ValidationException::class);
        $this->workflowService->start($stocktake2, $this->user);
    }

    public function test_blind_count_masks_system_quantity_and_variance_prior_to_count(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);
        $item = $stocktake->items()->firstOrFail();

        // 1. Pending status: API must return null system_quantity and null variance
        $resShow = $this->getJson("/api/stocktakes/{$stocktake->id}");
        $resShow->assertStatus(200);
        $itemData = $resShow->json('data.items.0');

        $this->assertNull($itemData['system_quantity']);
        $this->assertNull($itemData['counted_quantity']);
        $this->assertNull($itemData['variance']);
        $this->assertEquals('pending', $itemData['count_status']);

        // 2. Start counting: snapshot is taken on backend, but API response to counter must STILL be null
        $this->stocktakeService->startCounting($item, $this->user);
        $item->refresh();
        $this->assertEquals('50.000', (string) $item->system_quantity); // Present in DB

        Sanctum::actingAs($this->counterUser);
        $resShowCounting = $this->getJson("/api/stocktakes/{$stocktake->id}");
        $itemDataCounting = $resShowCounting->json('data.items.0');

        $this->assertNull($itemDataCounting['system_quantity']); // Masked from API!
        $this->assertNull($itemDataCounting['variance']);

        // 3. Record count
        $this->stocktakeService->recordCount($item, $this->counterUser, 48);

        // Now if admin/reviewer views, system_quantity and variance are revealed
        Sanctum::actingAs($this->user);
        $resShowCounted = $this->getJson("/api/stocktakes/{$stocktake->id}");
        $itemDataCounted = $resShowCounted->json('data.items.0');

        $this->assertEquals(50.0, $itemDataCounted['system_quantity']);
        $this->assertEquals(48.0, $itemDataCounted['counted_quantity']);
        $this->assertEquals(-2.0, $itemDataCounted['variance']);
        $this->assertEquals('decrease', $itemDataCounted['variance_direction']);
    }

    public function test_stock_movement_is_strictly_blocked_on_product_while_in_counting_state(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);
        $item = $stocktake->items()->firstOrFail();

        // Before counting: stock movement on activeProduct1 is allowed
        // Let's create an approved goods receipt document
        $doc = InventoryDocument::query()->create([
            'document_number' => 'DOC-GR-2026-00099',
            'document_type' => InventoryDocumentType::GOODS_RECEIPT,
            'status' => InventoryDocumentStatus::APPROVED,
            'destination_warehouse_id' => $this->warehouse->id,
            'document_date' => '2026-11-14',
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);
        InventoryDocumentItem::query()->create([
            'inventory_document_id' => $doc->id,
            'product_id' => $this->activeProduct1->id,
            'quantity' => 10,
        ]);

        // Start counting activeProduct1 -> Locks product in this warehouse
        $this->stocktakeService->startCounting($item, $this->user);

        // Attempting to post the document touching activeProduct1 in warehouse must fail!
        try {
            $this->movementService->postDocumentMovements($doc, $this->user->id);
            $this->fail('Expected ValidationException was not thrown while product was counting.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stocktake_locked', $e->errors());
        }

        // BUT moving another product (activeProduct2) in the SAME warehouse is allowed!
        $docOther = InventoryDocument::query()->create([
            'document_number' => 'DOC-GR-2026-00100',
            'document_type' => InventoryDocumentType::GOODS_RECEIPT,
            'status' => InventoryDocumentStatus::APPROVED,
            'destination_warehouse_id' => $this->warehouse->id,
            'document_date' => '2026-11-14',
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);
        InventoryDocumentItem::query()->create([
            'inventory_document_id' => $docOther->id,
            'product_id' => $this->activeProduct2->id,
            'quantity' => 15,
        ]);

        $movements = $this->movementService->postDocumentMovements($docOther, $this->user->id);
        $this->assertNotEmpty($movements);

        // AND moving activeProduct1 in ANOTHER warehouse is allowed!
        $docBranch = InventoryDocument::query()->create([
            'document_number' => 'DOC-GR-2026-00101',
            'document_type' => InventoryDocumentType::GOODS_RECEIPT,
            'status' => InventoryDocumentStatus::APPROVED,
            'destination_warehouse_id' => $this->secondWarehouse->id,
            'document_date' => '2026-11-14',
            'created_by' => $this->user->id,
            'approved_by' => $this->user->id,
        ]);
        InventoryDocumentItem::query()->create([
            'inventory_document_id' => $docBranch->id,
            'product_id' => $this->activeProduct1->id,
            'quantity' => 5,
        ]);
        $branchMovements = $this->movementService->postDocumentMovements($docBranch, $this->user->id);
        $this->assertNotEmpty($branchMovements);

        // Once counting is finished, lock is released and activeProduct1 in warehouse can move freely!
        $this->stocktakeService->recordCount($item, $this->user, 50);

        $releasedMovements = $this->movementService->postDocumentMovements($doc, $this->user->id);
        $this->assertNotEmpty($releasedMovements);
    }

    public function test_cancel_count_resets_status_and_releases_lock(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);
        $item = $stocktake->items()->firstOrFail();

        $this->stocktakeService->startCounting($item, $this->user);
        $this->assertEquals(StocktakeItemStatus::COUNTING, $item->refresh()->count_status);

        // Cancel count
        $this->stocktakeService->cancelCount($item, $this->user, 'تعذر الوصول للرف');
        $item->refresh();

        $this->assertEquals(StocktakeItemStatus::PENDING, $item->count_status);
        $this->assertNull($item->system_quantity);

        // Check history was recorded
        $this->assertDatabaseHas('stocktake_item_histories', [
            'stocktake_item_id' => $item->id,
            'action' => 'cancel_count',
            'reason' => 'تعذر الوصول للرف',
        ]);
    }

    public function test_approving_stocktake_with_variances_generates_draft_adjustments(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id, $this->activeProduct2->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);

        $items = $stocktake->items()->get();
        $item1 = $items->firstWhere('product_id', $this->activeProduct1->id);
        $item2 = $items->firstWhere('product_id', $this->activeProduct2->id);

        // Item 1: system has 50 -> count is 45 (Decrease of 5)
        $this->stocktakeService->startCounting($item1, $this->user);
        $this->stocktakeService->recordCount($item1, $this->user, 45);

        // Item 2: system has 0 -> count is 8 (Increase of 8)
        $this->stocktakeService->startCounting($item2, $this->user);
        $this->stocktakeService->recordCount($item2, $this->user, 8);

        // Submit for approval
        $this->workflowService->submit($stocktake, $this->user);
        $this->assertEquals(StocktakeStatus::PENDING_APPROVAL, $stocktake->refresh()->status);

        // Approve
        $approved = $this->workflowService->approve($stocktake, $this->user);
        $this->assertEquals(StocktakeStatus::APPROVED, $approved->status);

        // Check that 2 draft adjustment documents were generated
        $adjustments = $approved->inventoryDocuments()->get();
        $this->assertCount(2, $adjustments);

        $increaseDoc = $adjustments->firstWhere('adjustment_direction', StockAdjustmentDirection::INCREASE);
        $this->assertNotNull($increaseDoc);
        $this->assertEquals(InventoryDocumentStatus::DRAFT, $increaseDoc->status);
        $this->assertEquals($this->warehouse->id, $increaseDoc->destination_warehouse_id);
        $this->assertNull($increaseDoc->source_warehouse_id);
        $this->assertEquals(8.0, (float) $increaseDoc->items()->first()->quantity);

        $decreaseDoc = $adjustments->firstWhere('adjustment_direction', StockAdjustmentDirection::DECREASE);
        $this->assertNotNull($decreaseDoc);
        $this->assertEquals(InventoryDocumentStatus::DRAFT, $decreaseDoc->status);
        $this->assertEquals($this->warehouse->id, $decreaseDoc->source_warehouse_id);
        $this->assertNull($decreaseDoc->destination_warehouse_id);
        $this->assertEquals(5.0, (float) $decreaseDoc->items()->first()->quantity);
    }

    public function test_posting_all_adjustments_automatically_completes_the_stocktake(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);
        $item = $stocktake->items()->firstOrFail();

        // System 50 -> counted 55 (Increase of 5)
        $this->stocktakeService->startCounting($item, $this->user);
        $this->stocktakeService->recordCount($item, $this->user, 55);

        $this->workflowService->submit($stocktake, $this->user);
        $approved = $this->workflowService->approve($stocktake, $this->user);

        $this->assertEquals(StocktakeStatus::APPROVED, $approved->status);
        $adjustments = $approved->inventoryDocuments()->get();
        $this->assertCount(1, $adjustments);

        $doc = $adjustments->first();

        // Put the adjustment document through the workflow: submit -> approve -> post
        $this->docWorkflowService->submit($doc, $this->user);
        $this->docWorkflowService->approve($doc, $this->user);

        // Before post: stocktake is still APPROVED
        $this->assertEquals(StocktakeStatus::APPROVED, $stocktake->refresh()->status);

        // Post adjustment document
        $this->docWorkflowService->post($doc, $this->user);

        // After posting: stocktake must automatically be COMPLETED!
        $this->assertEquals(StocktakeStatus::COMPLETED, $stocktake->refresh()->status);
        $this->assertNotNull($stocktake->completed_by);
        $this->assertNotNull($stocktake->completed_at);

        // And stock balance was updated correctly
        $finalStock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->activeProduct1->id)
            ->value('quantity');
        $this->assertEquals('55.000', (string) $finalStock);
    }

    public function test_exact_count_with_no_variances_completes_immediately_without_adjustments(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);
        $item = $stocktake->items()->firstOrFail();

        // System 50 -> counted exactly 50
        $this->stocktakeService->startCounting($item, $this->user);
        $this->stocktakeService->recordCount($item, $this->user, 50);

        $this->workflowService->submit($stocktake, $this->user);
        $completed = $this->workflowService->approve($stocktake, $this->user);

        $this->assertEquals(StocktakeStatus::COMPLETED, $completed->status);
        $this->assertCount(0, $completed->inventoryDocuments);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_rejection_allows_recount_and_resubmission(): void
    {
        $stocktake = $this->stocktakeService->createStocktake([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StocktakeScope::SELECTED->value,
            'stocktake_date' => '2026-11-14',
            'product_ids' => [$this->activeProduct1->id],
        ], $this->user);

        $this->workflowService->start($stocktake, $this->user);
        $item = $stocktake->items()->firstOrFail();

        $this->stocktakeService->startCounting($item, $this->user);
        $this->stocktakeService->recordCount($item, $this->user, 30);

        $this->workflowService->submit($stocktake, $this->user);

        // Reject
        $rejected = $this->workflowService->reject($stocktake, $this->user, 'الرقم غير منطقي يرجى إعادة العد');
        $this->assertEquals(StocktakeStatus::REJECTED, $rejected->status);
        $this->assertEquals('الرقم غير منطقي يرجى إعادة العد', $rejected->rejection_reason);

        // Recount
        $this->stocktakeService->recount($item, $this->user, 'إعادة تحقق');
        $this->assertEquals(StocktakeItemStatus::COUNTING, $item->refresh()->count_status);

        // Record corrected count
        $this->stocktakeService->recordCount($item, $this->user, 49);

        // Resubmit
        $this->workflowService->submit($stocktake, $this->user);
        $this->assertEquals(StocktakeStatus::PENDING_APPROVAL, $stocktake->refresh()->status);
    }
}
