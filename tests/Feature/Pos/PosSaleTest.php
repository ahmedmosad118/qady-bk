<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PaymentMethodType;
use App\Enums\StockMovementDirection;
use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\InventoryDocumentItem;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosSaleCalculator;
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
    protected PosSaleCalculator $calculator;
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
        $this->visaMethod = PaymentMethod::query()->where('type', PaymentMethodType::VISA)->firstOrFail();
        $this->bankTransferMethod = PaymentMethod::query()->where('type', PaymentMethodType::BANK_TRANSFER)->firstOrFail();

        $this->shiftService = app(PosShiftService::class);
        $this->saleService = app(PosSaleService::class);
        $this->calculator = app(PosSaleCalculator::class);

        $this->shift = $this->shiftService->openShift($this->user, 1000.00);

        $this->product1 = Product::factory()->create([
            'name' => 'صحيح البخاري',
            'barcode' => '6221000000001',
            'purchase_price' => 120.00,
            'selling_price' => 200.00,
        ]);

        $this->product2 = Product::factory()->create([
            'name' => 'رياض الصالحين',
            'barcode' => '6221000000002',
            'purchase_price' => 80.00,
            'selling_price' => 150.00,
        ]);

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
                    'quantity' => 2,
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

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->first();
        $this->assertEquals('8.000', (string) $stock->quantity);

        $movement = StockMovement::query()
            ->where('product_id', $this->product1->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($movement);
        $this->assertEquals(StockMovementDirection::OUTBOUND, $movement->direction);
        $this->assertEquals('2.000', (string) $movement->quantity);

        $sale = PosSale::query()->where('invoice_number', $invoiceNumber)->firstOrFail();
        $invDoc = $sale->inventoryDocuments()->first();
        $this->assertNotNull($invDoc);
        $this->assertEquals(InventoryDocumentType::GOODS_DELIVERY, $invDoc->document_type);
        $this->assertEquals(InventoryDocumentStatus::POSTED, $invDoc->status);

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
        $visaPayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->visaMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ];
        $visaResp = $this->postJson('/api/pos/sales', $visaPayload);
        $visaResp->assertCreated();

        $bankPayload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->bankTransferMethod->id,
            'items' => [
                ['product_id' => $this->product2->id, 'quantity' => 1],
            ],
        ];
        $bankResp = $this->postJson('/api/pos/sales', $bankPayload);
        $bankResp->assertCreated();

        $this->shift->refresh();
        $this->assertEquals('1000.00', $this->shift->expected_cash);
        $this->assertEquals(0, PosShiftTransaction::query()->count());
    }

    public function test_cash_payment_identified_by_type_not_display_name(): void
    {
        $this->cashMethod->update(['name' => 'Cash Payment']);

        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => '1.000'],
            ],
        ], $this->user);

        $this->assertDatabaseHas('pos_shift_transactions', [
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $sale->id,
            'type' => PosShiftTransaction::TYPE_CASH_SALE,
            'amount' => '200.00',
        ]);

        $this->shift->refresh();
        $this->assertSame('1200.00', (string) $this->shift->expected_cash);
    }

    public function test_sale_without_customer_is_allowed(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'customer_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $this->assertNull($response->json('data.customer_id'));
        $this->assertNull($response->json('data.customer'));
    }

    public function test_sale_with_registered_customer_binds_and_hydrates_contact_and_rejects_invalid_customer(): void
    {
        $customer = Contact::factory()->create([
            'name' => 'المستشار أحمد القاضي',
            'phone' => '01099887766',
            'is_customer' => true,
        ]);

        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'customer_id' => $customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => '1.000'],
            ],
        ], $this->user);

        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertTrue($sale->relationLoaded('customer'));
        $this->assertNotNull($sale->customer);
        $this->assertSame($customer->id, $sale->customer->id);
        $this->assertSame('المستشار أحمد القاضي', $sale->customer->name);

        // Non-existent customer_id must throw ValidationException on customer_id
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'customer_id' => 999999,
                'payment_method_id' => $this->cashMethod->id,
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => '1.000'],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException for non-existent customer_id.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('customer_id', $e->errors());
        }
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
                    'unit_price' => 180.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $response->assertJsonPath('data.grand_total', '180.00');

        $sale = PosSale::query()->firstOrFail();
        $item = $sale->items()->firstOrFail();

        $this->assertSame('200.00', (string) $item->original_unit_price);
        $this->assertSame('180.00', (string) $item->unit_price);
        $this->assertArrayHasKey('name', $item->product_snapshot);
        $this->assertArrayHasKey('sku', $item->product_snapshot);
        $this->assertArrayHasKey('barcode', $item->product_snapshot);
        $this->assertArrayHasKey('unit_name', $item->product_snapshot);
        $this->assertSame('صحيح البخاري', $item->product_snapshot['name']);
        $this->assertNull($item->product_snapshot['sku']);
        $this->assertSame('6221000000001', $item->product_snapshot['barcode']);
        $this->assertSame(__('pos.unit_piece'), $item->product_snapshot['unit_name']);

        // Mutate product later and verify historical invoice snapshot is preserved
        $this->product1->update([
            'name' => 'Updated Book Name',
            'barcode' => '9999999999999',
            'selling_price' => 350.00,
        ]);

        $item->refresh();
        $this->assertSame('صحيح البخاري', $item->product_snapshot['name']);
        $this->assertSame('6221000000001', $item->product_snapshot['barcode']);
        $this->assertSame('200.00', (string) $item->original_unit_price);
    }

    public function test_item_and_invoice_discounts_with_accurate_allocation(): void
    {
        // Item 1: 2 * 200 = 400. Item discount: fixed 20 => total = 380.00
        // Item 2: 1 * 150 = 150. Item discount: 10% (15) => total = 135.00
        // Sum of line totals = 380 + 135 = 515.00
        // Invoice discount: fixed 15.00 => grand total = 500.00
        // Allocation: Item 1 = 15.00 * 380 / 515 = 11.067961... -> 11.07, Item 2 = 15.00 - 11.07 = 3.93
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
                    'discount_value' => 10.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertCreated();
        $response->assertJsonPath('data.subtotal', '550.00');
        $response->assertJsonPath('data.items_discount_total', '35.00');
        $response->assertJsonPath('data.invoice_discount_amount', '15.00');
        $response->assertJsonPath('data.grand_total', '500.00');

        $sale = PosSale::query()->firstOrFail();
        $items = $sale->items()->orderBy('id')->get();

        $this->assertSame('11.07', (string) $items[0]->allocated_invoice_discount);
        $this->assertSame('3.93', (string) $items[1]->allocated_invoice_discount);
        $this->assertSame(
            '15.00',
            bcadd((string) $items[0]->allocated_invoice_discount, (string) $items[1]->allocated_invoice_discount, 2)
        );
    }

    public function test_duplicate_product_ids_consolidate_quantities_with_bcmath(): void
    {
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => '1.250'],
                ['product_id' => $this->product1->id, 'quantity' => '0.375'],
                ['product_id' => $this->product1->id, 'quantity' => '2.125'],
            ],
        ], $this->user);

        // Consolidated quantity: 1.250 + 0.375 + 2.125 = 3.750
        // Subtotal: 3.750 * 200.00 = 750.00
        $this->assertCount(1, $sale->items);
        $saleItem = $sale->items->first();
        $this->assertSame('3.750', (string) $saleItem->quantity);
        $this->assertSame('750.00', (string) $saleItem->subtotal);
        $this->assertSame('750.00', (string) $sale->grand_total);

        // Stock: 10.000 - 3.750 = 6.250
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->firstOrFail();
        $this->assertSame('6.250', (string) $stock->quantity);
    }

    public function test_fractional_quantities_percentage_invoice_discount_and_high_precision_decimals(): void
    {
        $product3 = Product::factory()->create([
            'name' => 'زاد المستقنع',
            'barcode' => '6221000000009',
            'purchase_price' => 20.00,
            'selling_price' => 33.33,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product3->id,
            'quantity' => '20.000',
        ]);

        // Item 1: 1.500 * 19.99 = 29.985 -> rounds to 29.99. Discount 12.5% = 3.74875 -> 3.75. Line total = 26.24
        // Item 2: 2.250 * 33.33 = 74.9925 -> rounds to 74.99. Discount fixed 4.99 -> 4.99. Line total = 70.00
        // Items subtotal = 29.99 + 74.99 = 104.98
        // Items discount total = 3.75 + 4.99 = 8.74
        // Net items total = 26.24 + 70.00 = 96.24
        // Invoice discount: 7.5% of 96.24 = 7.218 -> 7.22
        // Grand total: 96.24 - 7.22 = 89.02
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'percentage',
            'invoice_discount_value' => '7.50',
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => '1.500',
                    'unit_price' => '19.99',
                    'discount_type' => 'percentage',
                    'discount_value' => '12.50',
                ],
                [
                    'product_id' => $product3->id,
                    'quantity' => '2.250',
                    'unit_price' => '33.33',
                    'discount_type' => 'fixed',
                    'discount_value' => '4.99',
                ],
            ],
        ], $this->user);

        $this->assertSame('104.98', (string) $sale->subtotal);
        $this->assertSame('8.74', (string) $sale->items_discount_total);
        $this->assertSame('7.22', (string) $sale->invoice_discount_amount);
        $this->assertSame('89.02', (string) $sale->grand_total);

        $items = $sale->items()->orderBy('id')->get();
        $this->assertSame('29.99', (string) $items[0]->subtotal);
        $this->assertSame('3.75', (string) $items[0]->discount_amount);
        $this->assertSame('26.24', (string) $items[0]->total);

        $this->assertSame('74.99', (string) $items[1]->subtotal);
        $this->assertSame('4.99', (string) $items[1]->discount_amount);
        $this->assertSame('70.00', (string) $items[1]->total);

        // Allocation: Item 1 = 7.22 * 26.24 / 96.24 = 1.968545... -> 1.97; Item 2 = 7.22 - 1.97 = 5.25
        $this->assertSame('1.97', (string) $items[0]->allocated_invoice_discount);
        $this->assertSame('5.25', (string) $items[1]->allocated_invoice_discount);
        $this->assertSame(
            (string) $sale->invoice_discount_amount,
            bcadd((string) $items[0]->allocated_invoice_discount, (string) $items[1]->allocated_invoice_discount, 2)
        );
    }

    public function test_invoice_discount_allocation_absorbs_rounding_remainder_on_last_item(): void
    {
        $product3 = Product::factory()->create([
            'name' => 'عمدة الأحكام',
            'barcode' => '6221000000010',
            'purchase_price' => 50.00,
            'selling_price' => 100.00,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product3->id,
            'quantity' => '10.000',
        ]);

        // 3 equal items of 100.00 each (total = 300.00), invoice discount = 10.00
        // Item 1: 3.33, Item 2: 3.33, Item 3 (last): 10.00 - 6.66 = 3.34
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'fixed',
            'invoice_discount_value' => '10.00',
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => '1.000', 'unit_price' => '100.00'],
                ['product_id' => $this->product2->id, 'quantity' => '1.000', 'unit_price' => '100.00'],
                ['product_id' => $product3->id, 'quantity' => '1.000', 'unit_price' => '100.00'],
            ],
        ], $this->user);

        $items = $sale->items()->orderBy('id')->get();
        $this->assertCount(3, $items);
        $this->assertSame('3.33', (string) $items[0]->allocated_invoice_discount);
        $this->assertSame('3.33', (string) $items[1]->allocated_invoice_discount);
        $this->assertSame('3.34', (string) $items[2]->allocated_invoice_discount);

        $sumAllocated = bcadd(
            bcadd((string) $items[0]->allocated_invoice_discount, (string) $items[1]->allocated_invoice_discount, 2),
            (string) $items[2]->allocated_invoice_discount,
            2
        );
        $this->assertSame('10.00', $sumAllocated);
    }

    public function test_zero_and_invalid_discount_and_quantity_values(): void
    {
        // 1. Zero discounts succeed cleanly
        $sale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'invoice_discount_type' => 'percentage',
            'invoice_discount_value' => '0.00',
            'items' => [
                [
                    'product_id' => $this->product1->id,
                    'quantity' => '1.000',
                    'discount_type' => 'fixed',
                    'discount_value' => '0.00',
                ],
            ],
        ], $this->user);

        $this->assertSame('0.00', (string) $sale->items_discount_total);
        $this->assertSame('0.00', (string) $sale->invoice_discount_amount);
        $this->assertSame('200.00', (string) $sale->grand_total);

        // 2. Negative item discount rejected
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'items' => [
                    [
                        'product_id' => $this->product1->id,
                        'quantity' => '1.000',
                        'discount_type' => 'fixed',
                        'discount_value' => '-5.00',
                    ],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException for negative item discount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // 3. Item percentage discount > 100 rejected
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'items' => [
                    [
                        'product_id' => $this->product1->id,
                        'quantity' => '1.000',
                        'discount_type' => 'percentage',
                        'discount_value' => '100.01',
                    ],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException for item percentage discount > 100.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // 4. Invoice percentage discount > 100 rejected
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'invoice_discount_type' => 'percentage',
                'invoice_discount_value' => '105.00',
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => '1.000'],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException for invoice percentage discount > 100.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_discount_value', $e->errors());
        }

        // 5. Negative invoice discount rejected
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'invoice_discount_type' => 'fixed',
                'invoice_discount_value' => '-1.00',
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => '1.000'],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException for negative invoice discount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_discount_value', $e->errors());
        }

        // 6. Invalid discount type rejected
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'invoice_discount_type' => 'invalid_type',
                'invoice_discount_value' => '10.00',
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => '1.000'],
                ],
            ], $this->user);
            $this->fail('Expected ValidationException for invalid invoice discount type.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_discount_type', $e->errors());
        }
    }

    public function test_missing_product_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);

        $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => 999999, 'quantity' => '1.000'],
            ],
        ], $this->user);
    }

    public function test_bulk_product_retrieval_eliminates_n_plus_one_for_1_10_and_50_items(): void
    {
        $products = Product::factory()->count(100)->create([
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

        $this->user->load(['roles', 'permissions']);
        app(\Spatie\Permission\PermissionRegistrar::class)->getPermissions();
        $saleQueryCounts = [];

        foreach ([1, 10, 50, 100] as $itemCount) {
            $itemsPayload = $products->take($itemCount)->map(fn (Product $p): array => [
                'product_id' => $p->id,
                'quantity' => '1.000',
            ])->values()->all();

            DB::flushQueryLog();
            DB::enableQueryLog();

            $consolidateResult = $this->calculator->consolidateItems($itemsPayload);
            $loadedProducts = $this->saleService->loadProducts(array_keys($consolidateResult));
            $this->calculator->calculate($consolidateResult, $loadedProducts);

            $calcQueries = DB::getQueryLog();
            DB::disableQueryLog();

            // Product retrieval + item calculation must execute exactly 1 query regardless of item count (1, 10, 50, or 100)
            $this->assertCount(
                1,
                $calcQueries,
                "Expected exactly 1 product query during loadProducts + calculate for {$itemCount} items."
            );

            // Also verify full sale execution bulk inserts pos_sale_items and inventory_document_items in 1 query each
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->visaMethod->id,
                'items' => $itemsPayload,
            ], $this->user);

            $rawQueries = DB::getQueryLog();
            $saleQueries = collect($rawQueries)->pluck('query');
            DB::disableQueryLog();

            $saleQueryCounts[$itemCount] = count($rawQueries);

            $posSaleItemInserts = $saleQueries->filter(
                fn (string $sql): bool => str_contains(strtolower($sql), 'insert into "pos_sale_items"')
                    || str_contains(strtolower($sql), 'insert into `pos_sale_items`')
            );
            $invDocItemInserts = $saleQueries->filter(
                fn (string $sql): bool => str_contains(strtolower($sql), 'insert into "inventory_document_items"')
                    || str_contains(strtolower($sql), 'insert into `inventory_document_items`')
            );

            $this->assertCount(1, $posSaleItemInserts, "Expected 1 bulk insert into pos_sale_items for {$itemCount} items.");
            $this->assertCount(1, $invDocItemInserts, "Expected 1 bulk insert into inventory_document_items for {$itemCount} items.");
        }

        $this->assertSame($saleQueryCounts[1], $saleQueryCounts[10]);
        $this->assertSame($saleQueryCounts[10], $saleQueryCounts[50]);
        $this->assertSame($saleQueryCounts[50], $saleQueryCounts[100]);
    }

    public function test_missing_default_warehouse_throws_validation_exception_without_fallback(): void
    {
        // Create another active non-default warehouse and unset is_default on the main warehouse
        Warehouse::query()->create([
            'code' => 'SEC-WH',
            'name' => 'Secondary Warehouse',
            'address' => 'Alexandria',
            'is_default' => false,
        ]);

        Warehouse::query()->update(['is_default' => false]);

        $response = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('warehouse');

        $this->expectException(ValidationException::class);
        $this->saleService->paginateProducts('');
    }

    public function test_client_supplied_warehouse_id_cannot_override_default_warehouse(): void
    {
        $secondaryWarehouse = Warehouse::query()->create([
            'code' => 'SEC-WH',
            'name' => 'Secondary Warehouse',
            'address' => 'Giza',
            'is_default' => false,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $secondaryWarehouse->id,
            'product_id' => $this->product1->id,
            'quantity' => '100.000',
        ]);

        $response = $this->postJson('/api/pos/sales', [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'warehouse_id' => $secondaryWarehouse->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 2],
            ],
        ]);

        $response->assertCreated();

        // Default warehouse stock must be deducted (10 -> 8), secondary warehouse untouched (100)
        $defaultStock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->value('quantity');
        $secondaryStock = WarehouseStock::query()
            ->where('warehouse_id', $secondaryWarehouse->id)
            ->where('product_id', $this->product1->id)
            ->value('quantity');

        $this->assertSame('8.000', (string) $defaultStock);
        $this->assertSame('100.000', (string) $secondaryStock);

        // Verify product lookup also ignores client warehouse_id and returns default warehouse stock
        $lookupProduct = $this->saleService->lookupBarcode('6221000000001', $secondaryWarehouse->id);
        $this->assertNotNull($lookupProduct);
        $this->assertCount(1, $lookupProduct->warehouseStocks);
        $this->assertSame($this->warehouse->id, $lookupProduct->warehouseStocks->first()->warehouse_id);
    }

    public function test_negative_stock_is_strictly_prevented(): void
    {
        $payload = [
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 11],
            ],
        ];

        $response = $this->postJson('/api/pos/sales', $payload);
        $response->assertStatus(422);

        $this->assertEquals(0, PosSale::query()->count());

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->first();
        $this->assertEquals('10.000', (string) $stock->quantity);
    }

    public function test_transaction_rollback_when_failure_occurs(): void
    {
        // Product 1 has 10 in stock (valid for 2), Product 2 has 5 in stock (invalid for 999)
        try {
            $this->saleService->createSale([
                'shift_id' => $this->shift->id,
                'payment_method_id' => $this->cashMethod->id,
                'items' => [
                    ['product_id' => $this->product1->id, 'quantity' => 2],
                    ['product_id' => $this->product2->id, 'quantity' => 999],
                ],
            ], $this->user);
        } catch (\Throwable) {
            // Expected rollback
        }

        $this->assertSame(0, PosSale::query()->count());
        $this->assertSame(0, PosSaleItem::query()->count());
        $this->assertSame(0, PosShiftTransaction::query()->count());
        $this->assertSame(0, InventoryDocument::query()->count());
        $this->assertSame(0, InventoryDocumentItem::query()->count());
        $this->assertSame(0, StockMovement::query()->count());

        $this->shift->refresh();
        $this->assertSame('1000.00', (string) $this->shift->expected_cash);

        $stock1 = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->value('quantity');
        $this->assertSame('10.000', (string) $stock1);
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
        $sale->update(['notes' => 'Forbidden update']);
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

    public function test_can_search_products_and_lookup_by_barcode_and_filter_child_categories(): void
    {
        $parentCategory = ProductCategory::factory()->create(['name' => 'Hadith']);
        $childCategory = ProductCategory::factory()->create([
            'name' => 'Sahih Collections',
            'parent_id' => $parentCategory->id,
        ]);

        $this->product1->update(['category_id' => $childCategory->id]);

        // Paginate with parent category includes child category products
        $paginatedByParent = $this->saleService->paginateProducts('', null, $parentCategory->id, 24);
        $this->assertSame(1, $paginatedByParent->total());
        $this->assertSame($this->product1->id, $paginatedByParent->items()[0]->id);

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
        // Create additional products so default 32-per-page pagination spans 2 pages
        $extra = Product::factory()->count(33)->create(['selling_price' => 100.00]);
        foreach ($extra as $product) {
            WarehouseStock::query()->create([
                'warehouse_id' => $this->warehouse->id,
                'product_id' => $product->id,
                'quantity' => '5.000',
            ]);
        }

        // Default per_page must be 32
        $page1 = $this->getJson('/api/pos/products');
        $page1->assertOk();
        $this->assertCount(32, $page1->json('data'));
        $this->assertEquals(1, $page1->json('meta.current_page'));
        $this->assertEquals(32, $page1->json('meta.per_page'));
        $this->assertEquals(2, $page1->json('meta.last_page'));
        $this->assertEquals(35, $page1->json('meta.total'));
        $this->assertEquals(1, $page1->json('meta.from'));
        $this->assertEquals(32, $page1->json('meta.to'));

        // Page 2 returns the remaining 3 products with zero overlap against Page 1
        $page2 = $this->getJson('/api/pos/products?page=2');
        $page2->assertOk();
        $this->assertCount(3, $page2->json('data'));
        $this->assertEquals(2, $page2->json('meta.current_page'));
        $this->assertEquals(32, $page2->json('meta.per_page'));
        $this->assertEquals(33, $page2->json('meta.from'));
        $this->assertEquals(35, $page2->json('meta.to'));

        $page1Ids = collect($page1->json('data'))->pluck('id')->all();
        $page2Ids = collect($page2->json('data'))->pluck('id')->all();
        $this->assertSame([], array_intersect($page1Ids, $page2Ids));

        // Explicit custom per_page remains supported
        $custom = $this->getJson('/api/pos/products?per_page=1&page=1');
        $custom->assertOk();
        $this->assertCount(1, $custom->json('data'));
        $this->assertEquals(1, $custom->json('meta.current_page'));
        $this->assertEquals(1, $custom->json('meta.per_page'));
        $this->assertEquals(35, $custom->json('meta.total'));
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

    public function test_can_search_sales_by_partial_invoice_number_and_arabic_digits(): void
    {
        $customer = Contact::factory()->create([
            'name' => 'أحمد للمرتجعات',
            'phone' => null,
            'mobile' => '01088887777',
            'is_customer' => true,
        ]);

        $sale1 = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'customer_id' => $customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => 1],
            ],
        ], $this->user);

        $sale2 = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product2->id, 'quantity' => 1],
            ],
        ], $this->user);

        // Partial invoice suffix (e.g. '001') matches sale1
        $suffix1 = substr($sale1->invoice_number, -3);
        $partialResp = $this->getJson('/api/pos/sales?search=' . urlencode($suffix1));
        $partialResp->assertOk();
        $this->assertContains($sale1->id, collect($partialResp->json('data'))->pluck('id')->all());

        // Common partial segment (e.g. '000') returns all matching sales
        $commonResp = $this->getJson('/api/pos/sales?search=000');
        $commonResp->assertOk();
        $ids = collect($commonResp->json('data'))->pluck('id')->all();
        $this->assertContains($sale1->id, $ids);
        $this->assertContains($sale2->id, $ids);

        // Arabic-Indic digits match invoice number
        $arabicSuffix = strtr($suffix1, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
        $arabicResp = $this->getJson('/api/pos/sales?search=' . urlencode($arabicSuffix));
        $arabicResp->assertOk();
        $this->assertContains($sale1->id, collect($arabicResp->json('data'))->pluck('id')->all());

        // Customer mobile number search also matches
        $mobileResp = $this->getJson('/api/pos/sales?search=01088887777');
        $mobileResp->assertOk();
        $this->assertSame([$sale1->id], collect($mobileResp->json('data'))->pluck('id')->all());
    }

    public function test_idempotency_and_concurrent_duplicate_client_uuid_handling(): void
    {
        $clientUuid = '550e8400-e29b-41d4-a716-446655440000';

        $payload = [
            'client_uuid' => $clientUuid,
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product1->id, 'quantity' => '2.000'],
            ],
        ];

        $firstSale = $this->saleService->createSale($payload, $this->user);
        $secondSale = $this->saleService->createSale($payload, $this->user);

        $this->assertSame($firstSale->id, $secondSale->id);
        $this->assertSame($firstSale->invoice_number, $secondSale->invoice_number);
        $this->assertSame(1, PosSale::query()->count());
        $this->assertSame(1, PosSaleItem::query()->count());
        $this->assertSame(1, PosShiftTransaction::query()->count());
        $this->assertSame(1, InventoryDocument::query()->count());
        $this->assertSame(1, StockMovement::query()->count());

        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product1->id)
            ->value('quantity');
        $this->assertSame('8.000', (string) $stock);
    }
}
