<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\StockMovementDirection;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
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
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class PosSaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PosShift $shift;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $visaMethod;
    protected PaymentMethod $bankTransferMethod;
    protected Product $product1;
    protected Product $product2;
    protected PosSaleService $saleService;
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
        $this->visaMethod = PaymentMethod::query()->where('name', 'فيزا')->firstOrFail();
        $this->bankTransferMethod = PaymentMethod::query()->where('name', 'تحويل بنكي')->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->saleService = app(PosSaleService::class);

        $this->shift = $this->shiftService->openShift($this->user, 1000.00);

        $this->product1 = Product::factory()->create([
            'name' => 'صحيح البخاري',
            'barcode' => '6221000000001',
            'purchase_price' => 120.00,
            'selling_price' => 200.00,
            'is_active' => true,
        ]);

        $this->product2 = Product::factory()->create([
            'name' => 'رياض الصالحين',
            'barcode' => '6221000000002',
            'purchase_price' => 80.00,
            'selling_price' => 150.00,
            'is_active' => true,
        ]);

        // Seed stock: 10 units for product 1, 5 units for product 2
        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product1->id,
            'quantity' => '10.000',
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product2->id,
            'quantity' => '5.000',
        ]);
    }

    public function test_cannot_sell_without_open_shift(): void
    {
        // Close shift
        $this->shiftService->closeShift($this->shift, $this->user, 1000.00);

        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertStatus(422);
    }

    public function test_cash_sale_creates_invoice_and_deducts_inventory_and_adds_cash(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 2, // 2 * 200 = 400
                ],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.subtotal', '400.00');
        $response->assertJsonPath('data.grand_total', '400.00');

        $invoiceNumber = $response->json('data.invoice_number');
        $this->assertNotEmpty($invoiceNumber);

        // Verify inventory deducted from 10 to 8
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->first();
        $this->assertEquals('8.000', (string) $stock->quantity);

        // Verify StockMovement record created (direction = outbound)
        $movement = StockMovement::query()
            ->where('product_id', $this->product1->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(StockMovementDirection::OUTBOUND, $movement->direction);
        $this->assertEquals('2.000', (string) $movement->quantity);

        // Verify inventory document created and marked POSTED
        $sale = PosSale::query()->where('invoice_number', $invoiceNumber)->firstOrFail();
        $invDoc = $sale->inventoryDocuments()->first();
        $this->assertNotNull($invDoc);
        $this->assertEquals(InventoryDocumentType::GOODS_DELIVERY, $invDoc->document_type);
        $this->assertEquals(InventoryDocumentStatus::POSTED, $invDoc->status);

        // Verify shift cash transaction created and expected cash increased: 1000 + 400 = 1400
        $this->assertDatabaseHas('pos_shift_transactions', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'type' => 'cash_sale',
            'amount' => '400.00',
        ]);

        $this->shift->refresh();
        $this->assertEquals('1400.00', $this->shift->expected_cash);
    }

    public function test_visa_and_bank_transfer_sales_do_not_affect_cash_balance(): void
    {
        // 1. Visa sale
        $visaPayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->visaMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1], // 200.00
            ],
        ];
        $visaResp = $this->postJson('/api/pos/sales', $visaPayload);
        $visaResp->assertCreated();

        // 2. Bank transfer sale
        $bankPayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->bankTransferMethod->id,
            'items' => [
                ['product_id' => $this->product2->id, 'quantity' => 1], // 150.00
            ],
        ];
        $bankResp = $this->postJson('/api/pos/sales', $bankPayload);
        $bankResp->assertCreated();

        // Shift expected cash must remain strictly 1000.00 (opening balance)
        $this->shift->refresh();
        $this->assertEquals('1000.00', $this->shift->expected_cash);

        // No cash_sale transaction in pos_shift_transactions
        $this->assertEquals(0, PosShiftTransaction::query()->count());
    }

    public function test_sale_without_customer_is_allowed(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'customer_id' => null, // Optional
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $this->assertNull($response->json('data.customer_id'));
    }

    public function test_price_override_and_preserves_original_price_in_snapshot(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 1,
                    'unit_price' => 180.00, // Original is 200.00, overridden to 180.00
                ],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $response->assertJsonPath('data.grand_total', '180.00');

        $sale = PosSale::query()->first();
        $item = $sale->items()->first();

        $this->assertEquals('200.00', (string) $item->original_unit_price);
        $this->assertEquals('180.00', (string) $item->unit_price);
        $this->assertEquals('صحيح البخاري', $item->product_snapshot['name']);
        $this->assertEquals('6221000000001', $item->product_snapshot['barcode']);
        $this->assertEquals('قطعة', $item->product_snapshot['unit_name']);
    }

    public function test_item_and_invoice_discounts_with_accurate_allocation(): void
    {
        // Item 1: 2 * 200 = 400. Item discount: fixed 20 => total = 380
        // Item 2: 1 * 150 = 150. Item discount: 10% (15) => total = 135
        // Sum of line totals = 380 + 135 = 515.00
        // Invoice discount: fixed 15.00 => grand total = 500.00
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => 15.00,
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => 2,
                    'discount_type' => 'fixed',
                    'discount_value' => 20.00,
                ],
                [
                    'product_id' => $this->product2->id,
                    'quantity' => 1,
                    'discount_type' => 'percentage',
                    'discount_value' => 10.00, // 10% of 150 = 15
                ],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $response->assertJsonPath('data.subtotal', '550.00');
        $response->assertJsonPath('data.items_discount_total', '35.00');
        $response->assertJsonPath('data.invoice_discount_amount', '15.00');
        $response->assertJsonPath('data.grand_total', '500.00');

        $sale = PosSale::query()->first();
        $items = $sale->items()->orderBy('id')->get();

        // Check accurate allocation of the 15.00 invoice discount
        $alloc1 = (float) $items[0]->allocated_invoice_discount;
        $alloc2 = (float) $items[1]->allocated_invoice_discount;
        $this->assertEquals(15.00, $alloc1 + $alloc2);
    }

    public function test_negative_stock_is_strictly_prevented(): void
    {
        // product1 has only 10 in stock; request 11
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 11],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertStatus(422);

        // Ensure no sale record created
        $this->assertEquals(0, PosSale::query()->count());

        // Ensure stock untouched
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->first();
        $this->assertEquals('10.000', (string) $stock->quantity);
    }

    public function test_transaction_rollback_when_failure_occurs(): void
    {
        // Try selling more than available stock
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 999],
                ],
            ], $this->user);
        } catch (\Throwable) {
            // Expected
        }

        $this->assertEquals(0, PosSale::query()->count());
        $this->assertEquals(0, PosShiftTransaction::query()->count());
        $this->assertEquals(0, StockMovement::query()->count());
    }

    public function test_pos_sale_is_immutable(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ], $this->user);

        $this->expectException(LogicException::class);
        $sale->update(['notes' => 'تعديل ممنوع']);
    }

    public function test_pos_sale_cannot_be_deleted(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ], $this->user);

        $this->expectException(LogicException::class);
        $sale->delete();
    }

    public function test_can_search_products_and_lookup_by_barcode(): void
    {
        // Search by name
        $searchResp = $this->getJson('/api/pos/products?search=البخاري');
        $searchResp->assertOk();
        $this->assertCount(1, $searchResp->json('data'));
        $this->assertEquals('6221000000001', $searchResp->json('data.0.barcode'));

        // Lookup by exact barcode
        $lookupResp = $this->getJson('/api/pos/products/lookup?barcode=6221000000002');
        $lookupResp->assertOk();
        $this->assertEquals('رياض الصالحين', $lookupResp->json('data.name'));
    }

    public function test_can_paginate_pos_products(): void
    {
        $resp = $this->getJson('/api/pos/products?per_page=1&page=1');
        $resp->assertOk();
        $this->assertCount(1, $resp->json('data'));
        $this->assertEquals(1, $resp->json('meta.current_page'));
        $this->assertEquals(1, $resp->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(2, $resp->json('meta.total'));
    }

    public function test_can_lookup_sale_by_invoice_number(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ], $this->user);

        $response = $this->getJson("/api/pos/sales/by-number/{$sale->invoice_number}");
        $response->assertOk();
        $response->assertJsonPath('data.id', $sale->id);
        $response->assertJsonPath('data.invoice_number', $sale->invoice_number);
    }
}
