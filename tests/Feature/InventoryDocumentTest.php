<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Enums\StockAdjustmentDirection;
use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected Warehouse $secondWarehouse;
    protected Product $product1;
    protected Product $product2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(WarehouseSeeder::class);
        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->secondWarehouse = Warehouse::query()->create([
            'code' => 'BRANCH-1',
            'name' => 'فرع الإسكندرية',
            'address' => 'الإسكندرية',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->product1 = Product::factory()->create(['is_active' => true]);
        $this->product2 = Product::factory()->create(['is_active' => true]);
    }

    public function test_can_create_goods_receipt_document(): void
    {
        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'notes' => 'إذن استلام شحنة كتب جديدة',
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 50,
                    'notes' => 'سليم وجاهز للتخزين',
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.document_type', InventoryDocumentType::GOODS_RECEIPT->value)
            ->assertJsonPath('data.status', InventoryDocumentStatus::DRAFT->value)
            ->assertJsonPath('data.destination_warehouse_id', $this->warehouse->id)
            ->assertJsonPath('data.created_by', $this->user->id);

        $this->assertDatabaseHas('inventory_documents', [
            'document_type' => 'goods_receipt',
            'destination_warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('inventory_document_items', [
            'product_id' => $this->product1->id,
            'quantity' => 50,
        ]);
    }

    public function test_can_create_warehouse_transfer_document(): void
    {
        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::WAREHOUSE_TRANSFER->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'destination_warehouse_id' => $this->secondWarehouse->id,
            'notes' => 'تحويل بين المخزن الرئيسي وفرع الإسكندرية',
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 20,
                ],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.document_type', InventoryDocumentType::WAREHOUSE_TRANSFER->value)
            ->assertJsonPath('data.source_warehouse_id', $this->warehouse->id)
            ->assertJsonPath('data.destination_warehouse_id', $this->secondWarehouse->id);
    }

    public function test_transfer_rejects_identical_source_and_destination(): void
    {
        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::WAREHOUSE_TRANSFER->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 10,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_warehouse_id']);
    }

    public function test_can_create_stock_adjustment_increase_and_decrease(): void
    {
        // Increase: destination required
        $incResponse = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::STOCK_ADJUSTMENT->value,
            'adjustment_direction' => StockAdjustmentDirection::INCREASE->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 5],
            ],
        ]);
        $incResponse->assertStatus(201)
            ->assertJsonPath('data.adjustment_direction', 'increase');

        // Decrease: source required
        $decResponse = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::STOCK_ADJUSTMENT->value,
            'adjustment_direction' => StockAdjustmentDirection::DECREASE->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 2],
            ],
        ]);
        $decResponse->assertStatus(201)
            ->assertJsonPath('data.adjustment_direction', 'decrease');
    }

    public function test_stock_write_off_requires_source_and_reason(): void
    {
        // Missing reason
        $failResponse = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::STOCK_WRITE_OFF->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 3],
            ],
        ]);
        $failResponse->assertStatus(422)
            ->assertJsonValidationErrors(['write_off_reason']);

        // With reason
        $okResponse = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::STOCK_WRITE_OFF->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'write_off_reason' => 'كتب تالفة بسبب تسرب مياه الأمطار',
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 3],
            ],
        ]);
        $okResponse->assertStatus(201)
            ->assertJsonPath('data.write_off_reason', 'كتب تالفة بسبب تسرب مياه الأمطار');
    }

    public function test_rejects_duplicate_product_in_same_document(): void
    {
        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 10],
                ['product_id' => $this->product1->id, 'quantity' => 20],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id', 'items.1.product_id']);
    }

    public function test_rejects_non_positive_quantity(): void
    {
        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $this->warehouse->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 0],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_rejects_inactive_warehouse(): void
    {
        $inactiveWh = Warehouse::query()->create([
            'code' => 'INACTIVE-1',
            'name' => 'مخزن مغلق',
            'address' => 'مغلق',
            'is_default' => false,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => '2026-11-13',
            'destination_warehouse_id' => $inactiveWh->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 5],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['destination_warehouse_id']);
    }

    public function test_polymorphic_referenceable_works_properly(): void
    {
        // Use an existing model as referenceable (e.g. Contact)
        $contact = Contact::factory()->create();

        $response = $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_DELIVERY->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'referenceable_type' => Contact::class,
            'referenceable_id' => $contact->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 4],
            ],
        ]);

        $response->assertStatus(201);
        $docId = $response->json('data.id');

        $doc = InventoryDocument::query()->findOrFail($docId);
        $this->assertInstanceOf(Contact::class, $doc->referenceable);
        $this->assertEquals($contact->id, $doc->referenceable->id);
    }

    public function test_can_filter_documents_by_type_and_stats_are_scoped_to_type(): void
    {
        // Create 1 goods delivery and 1 stock write off
        $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_DELIVERY->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->product1->id, 'quantity' => 2]],
        ])->assertStatus(201);

        $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::STOCK_WRITE_OFF->value,
            'document_date' => '2026-11-13',
            'source_warehouse_id' => $this->warehouse->id,
            'write_off_reason' => 'كتب مبللة',
            'items' => [['product_id' => $this->product1->id, 'quantity' => 1]],
        ])->assertStatus(201);

        // Filter by goods_delivery
        $res = $this->getJson('/api/inventory-documents?document_type=goods_delivery');
        $res->assertStatus(200);
        $res->assertJsonCount(1, 'data');
        $this->assertEquals('goods_delivery', $res->json('data.0.document_type'));

        // Filter by stock_write_off
        $res2 = $this->getJson('/api/inventory-documents?document_type=stock_write_off');
        $res2->assertStatus(200);
        $res2->assertJsonCount(1, 'data');
        $this->assertEquals('stock_write_off', $res2->json('data.0.document_type'));

        // All documents (no filter)
        $resAll = $this->getJson('/api/inventory-documents');
        $resAll->assertStatus(200);
        $resAll->assertJsonCount(2, 'data');
    }
}
