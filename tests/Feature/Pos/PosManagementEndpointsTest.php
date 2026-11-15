<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosManagementEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PaymentMethod $cashMethod;
    protected Product $product;
    protected Contact $customer;
    protected PosShift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Cashier One']);
        Sanctum::actingAs($this->user);

        $this->warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'WH-01',
            'address' => 'Cairo, Egypt',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->cashMethod = PaymentMethod::create([
            'name' => 'نقدي',
            'code' => 'cash',
            'is_cash' => true,
            'is_active' => true,
        ]);

        $category = ProductCategory::create([
            'name' => 'General Books',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Constitutional Law Volume 1',
            'sku' => 'LAW-001',
            'barcode' => '6221234567890',
            'category_id' => $category->id,
            'purchase_price' => 50.00,
            'selling_price' => 100.00,
            'is_active' => true,
        ]);

        $this->warehouse->stocks()->create([
            'product_id' => $this->product->id,
            'quantity' => 50,
        ]);

        $this->customer = Contact::create([
            'name' => 'Counselor Ahmad',
            'phone' => '01012345678',
            'is_customer' => true,
            'is_supplier' => false,
            'is_active' => true,
        ]);

        /** @var PosShiftService $shiftService */
        $shiftService = app(PosShiftService::class);
        $this->shift = $shiftService->openShift($this->user, 200.0, 'Morning shift');
    }

    public function test_can_paginate_and_filter_shifts_listing(): void
    {
        $response = $this->getJson('/api/pos/shifts');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'shift_number',
                        'opening_balance',
                        'opened_by',
                        'opened_at',
                        'sales_sum_grand_total',
                    ],
                ],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);

        $this->assertEquals(1, $response->json('meta.total'));
    }

    public function test_can_view_shift_details_with_summary_transactions_and_sales(): void
    {
        /** @var PosSaleService $saleService */
        $saleService = app(PosSaleService::class);

        $sale = $saleService->createSale([
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount_tendered' => 100.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 100.0,
                ],
            ],
        ], $this->user);

        $response = $this->getJson("/api/pos/shifts/{$this->shift->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shift.shift_number', $this->shift->shift_number)
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.sales_count', 1);

        $sales = $response->json('data.shift.sales');
        $this->assertNotEmpty($sales);
        $this->assertEquals($sale->invoice_number, $sales[0]['invoice_number']);
    }

    public function test_can_paginate_sales_and_view_sale_details_with_inventory_document(): void
    {
        /** @var PosSaleService $saleService */
        $saleService = app(PosSaleService::class);

        $sale = $saleService->createSale([
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount_tendered' => 200.0,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2,
                    'unit_price' => 100.0,
                ],
            ],
        ], $this->user);

        // 1. Check Sales Listing
        $listResponse = $this->getJson('/api/pos/sales');
        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.invoice_number', $sale->invoice_number);

        // 2. Check Sale Details with all tabs data
        $showResponse = $this->getJson("/api/pos/sales/{$sale->id}");
        $showResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.invoice_number', $sale->invoice_number)
            ->assertJsonPath('data.customer.name', $this->customer->name)
            ->assertJsonPath('data.creator.name', $this->user->name)
            ->assertJsonPath('data.items.0.product_snapshot.name', $this->product->name);

        // Verify linked Inventory Document
        $invDocs = $showResponse->json('data.inventory_documents');
        $this->assertNotEmpty($invDocs);
        $this->assertEquals('goods_delivery', $invDocs[0]['document_type']);
        $this->assertEquals('posted', $invDocs[0]['status']);

        // 3. Check Inventory Documents list eager loads referenceable POS sale
        $invListResponse = $this->getJson('/api/inventory-documents');
        $invListResponse->assertOk()
            ->assertJsonPath('success', true);

        $items = $invListResponse->json('data');
        $this->assertNotEmpty($items);
        $matching = collect($items)->firstWhere('id', $invDocs[0]['id']);
        $this->assertNotNull($matching);
        $this->assertEquals('pos_sale', $matching['referenceable_type']);
        $this->assertEquals($sale->invoice_number, $matching['referenceable']['invoice_number']);
    }
}
