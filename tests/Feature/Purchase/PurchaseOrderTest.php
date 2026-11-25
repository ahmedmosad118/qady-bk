<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderExpense;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return $this->actingAsSuperAdmin();
    }

    private function createSupplier(string $name = 'دار المعارف للطباعة'): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'is_customer' => false,
        ]);
    }

    private function createProduct(string $name = 'كتاب القانون المدني', float $purchasePrice = 100.00): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'purchase_price' => $purchasePrice,
            'selling_price' => $purchasePrice * 1.5,
        ]);
    }

    public function test_can_create_a_purchase_order_with_items_and_discounts(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('كتاب 1', 100.00);
        $product2 = $this->createProduct('كتاب 2', 50.00);

        // Product 1: qty 2, price 100 => 200. Discount: 10% (20.00) => line total 180.00
        // Product 2: qty 4, price 50 => 200. Discount: fixed 30.00 => line total 170.00
        // Subtotal (gross, before any discount): 200 + 200 = 400.00; items discounts = 50.00
        // Net lines: 400 - 50 = 350.00
        // Order discount: 10% on 350.00 = 35.00
        // Total: 350 - 35 = 315.00
        $payload = [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'expected_delivery_date' => now()->addDays(5)->toDateString(),
            'notes' => 'أمر توريد عاجل',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'items' => [
                [
                    'product_id' => $product1->id,
                    'quantity' => 2,
                    'unit_price' => 100.00,
                    'discount_type' => 'percentage',
                    'discount_value' => 10,
                ],
                [
                    'product_id' => $product2->id,
                    'quantity' => 4,
                    'unit_price' => 50.00,
                    'discount_type' => 'fixed',
                    'discount_value' => 30.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/purchase-orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', '400.00')
            ->assertJsonPath('data.discount_amount', '35.00')
            ->assertJsonPath('data.total', '315.00');

        $this->assertDatabaseHas('purchase_orders', [
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'subtotal' => 400.00,
            'discount_amount' => 35.00,
            'total' => 315.00,
            'created_by' => $user->id,
        ]);

        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $orderId,
            'product_id' => $product1->id,
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_amount' => 20.00,
            'line_total' => 180.00,
        ]);

        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $orderId,
            'product_id' => $product2->id,
            'quantity' => 4,
            'unit_price' => 50.00,
            'discount_amount' => 30.00,
            'line_total' => 170.00,
        ]);
    }

    public function test_order_number_follows_id_based_format(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $payload = [
            'supplier_id' => $supplier->id,
            'order_date' => '2026-02-01',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ];

        $first = $this->postJson('/api/purchase-orders', $payload)->assertCreated();
        $second = $this->postJson('/api/purchase-orders', $payload)->assertCreated();

        $this->assertSame(sprintf('PRH-ORD/2026/%06d', $first->json('data.id')), $first->json('data.order_number'));
        $this->assertSame(sprintf('PRH-ORD/2026/%06d', $second->json('data.id')), $second->json('data.order_number'));
        $this->assertSame(0, PurchaseOrder::query()->where('order_number', 'like', 'TMP-%')->count());
    }

    public function test_service_and_controller_messages_follow_app_locale(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $payload = [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ];

        app()->setLocale('en');
        $orderId = $this->postJson('/api/purchase-orders', $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'Purchase order created successfully.')
            ->json('data.id');

        $this->postJson("/api/purchase-orders/{$orderId}/confirm")->assertOk();
        $this->postJson("/api/purchase-orders/{$orderId}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'The purchase order has already been confirmed.');

        app()->setLocale('ar');
        $this->postJson("/api/purchase-orders/{$orderId}/confirm")
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'أمر الشراء تم تأكيده مسبقاً.');

        $this->getJson("/api/purchase-orders/{$orderId}")->assertOk()->assertJsonPath('data.id', $orderId);
        $this->getJson('/api/purchase-orders/999999')->assertNotFound();
    }

    public function test_order_lines_use_bulk_insert_and_date_filters_are_inclusive(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $products = [$this->createProduct('كتاب أ'), $this->createProduct('كتاب ب'), $this->createProduct('كتاب ج')];

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        foreach (['2026-03-01', '2026-03-05', '2026-03-09'] as $date) {
            $this->postJson('/api/purchase-orders', [
                'supplier_id' => $supplier->id,
                'order_date' => $date,
                'expected_delivery_date' => $date,
                'items' => array_map(fn (Product $p): array => ['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 10], $products),
            ])->assertCreated();
        }

        $inserts = count(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'insert into "purchase_order_items"')));
        $this->assertSame(3, $inserts, 'One bulk insert per order (3 orders x 3 lines).');

        $item = PurchaseOrderItem::query()->firstOrFail();
        $this->assertNotNull($item->created_at);
        $this->assertIsArray($item->product_snapshot);
        $this->assertStringStartsWith('كتاب', $item->product_snapshot['name']);

        $this->getJson('/api/purchase-orders?order_date_from=2026-03-05&order_date_to=2026-03-05')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/purchase-orders?order_date_from=2026-03-05')
            ->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/purchase-orders?delivery_date_to=2026-03-05')
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_order_list_per_page_is_capped_and_floored(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        PurchaseOrder::factory()->count(3)->create(['supplier_id' => $supplier->id, 'created_by' => $user->id]);

        $this->getJson('/api/purchase-orders?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson('/api/purchase-orders?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        $this->getJson('/api/purchase-orders?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_duplicate_products_in_order_are_consolidated(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct('كتاب مكرر', 80.00);

        // Sent 2 lines with same product: 3 items + 2 items = 5 items total @ 80.00 = 400.00
        $payload = [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 80.00,
                ],
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 80.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/purchase-orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $orderId = $response->json('data.id');

        // Only 1 item row in database with quantity 5
        $items = PurchaseOrder::findOrFail($orderId)->items;
        $this->assertCount(1, $items);
        $this->assertEquals('5.000', $items->first()->quantity);
        $this->assertEquals('400.00', $items->first()->line_total);
    }

    public function test_cannot_create_purchase_order_with_invalid_supplier(): void
    {
        $this->actingUser();
        $customerOnly = Contact::factory()->create([
            'is_supplier' => false,
            'is_customer' => true,
        ]);
        $product = $this->createProduct();

        $response = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $customerOnly->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50.00],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_purchase_order_rejects_soft_deleted_supplier_and_soft_deleted_product(): void
    {
        $this->actingUser();
        $deletedSupplier = $this->createSupplier('مورد محذوف');
        $deletedSupplier->delete();
        $activeSupplier = $this->createSupplier();
        $product = $this->createProduct();
        $deletedProduct = $this->createProduct('كتاب محذوف');
        $deletedProduct->delete();

        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $deletedSupplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);

        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $activeSupplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $deletedProduct->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    public function test_cannot_confirm_order_when_supplier_loses_supplier_role(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $orderId = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50]],
        ])->assertCreated()->json('data.id');

        $supplier->update(['is_supplier' => false]);

        $this->postJson("/api/purchase-orders/{$orderId}/confirm")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['supplier_id']);

        $this->assertSame('draft', PurchaseOrder::findOrFail($orderId)->status->value);
    }

    public function test_can_update_a_draft_purchase_order(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('أصل الكتاب', 100.00);
        $product2 = $this->createProduct('كتاب بديل', 120.00);

        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'subtotal' => 100.00,
            'total' => 100.00,
        ]);
        $order->items()->create([
            'product_id' => $product1->id,
            'product_snapshot' => ['name' => $product1->name, 'barcode' => $product1->barcode],
            'quantity' => 1,
            'unit_price' => 100.00,
            'line_total' => 100.00,
        ]);

        $response = $this->putJson("/api/purchase-orders/{$order->id}", [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'notes' => 'تحديث بعد المراجعة',
            'items' => [
                ['product_id' => $product2->id, 'quantity' => 3, 'unit_price' => 120.00],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total', '360.00');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'total' => 360.00,
            'notes' => 'تحديث بعد المراجعة',
        ]);

        // Old item removed, new item added
        $this->assertDatabaseMissing('purchase_order_items', [
            'purchase_order_id' => $order->id,
            'product_id' => $product1->id,
        ]);
        $this->assertDatabaseHas('purchase_order_items', [
            'purchase_order_id' => $order->id,
            'product_id' => $product2->id,
            'quantity' => 3,
            'line_total' => 360.00,
        ]);
    }

    public function test_cannot_update_a_converted_or_cancelled_purchase_order(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $convertedOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::CONVERTED,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        $response = $this->putJson("/api/purchase-orders/{$convertedOrder->id}", [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50.00],
            ],
        ]);

        // FormRequest authorize() returns false for non-draft orders
        $response->assertForbidden();
    }

    public function test_can_confirm_a_draft_purchase_order(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct('كتاب للتأكيد', 200.00);

        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'subtotal' => 400.00,
            'total' => 400.00,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_snapshot' => ['name' => $product->name, 'barcode' => $product->barcode],
            'quantity' => 2,
            'unit_price' => 200.00,
            'line_total' => 400.00,
        ]);

        $response = $this->postJson("/api/purchase-orders/{$order->id}/confirm");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'converted');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'status' => 'converted',
            'confirmed_by' => $user->id,
        ]);
        $this->assertNotNull(PurchaseOrder::find($order->id)->confirmed_at);
    }

    public function test_cannot_confirm_an_already_converted_or_cancelled_order(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $convertedOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::CONVERTED,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);
        $convertedOrder->items()->create([
            'product_id' => $product->id,
            'product_snapshot' => ['name' => $product->name],
            'quantity' => 1,
            'unit_price' => 50.00,
            'line_total' => 50.00,
        ]);

        $response = $this->postJson("/api/purchase-orders/{$convertedOrder->id}/confirm");
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_confirming_order_does_not_modify_stock(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::DRAFT,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_snapshot' => ['name' => $product->name],
            'quantity' => 10,
            'unit_price' => 50.00,
            'line_total' => 500.00,
        ]);

        $stockCountBefore = WarehouseStock::count();

        $this->postJson("/api/purchase-orders/{$order->id}/confirm")->assertOk();

        // Warehouse stocks must NOT have changed
        $this->assertEquals($stockCountBefore, WarehouseStock::count());
    }

    public function test_can_cancel_a_draft_purchase_order(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();

        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::DRAFT,
        ]);

        $response = $this->postJson("/api/purchase-orders/{$order->id}/cancel", [
            'reason' => 'تم التراجع بناء على طلب الإدارة',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_can_delete_draft_order_and_cannot_delete_converted_order(): void
    {
        $user = $this->actingUser();
        $supplier = $this->createSupplier();

        $draftOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::DRAFT,
        ]);

        $this->deleteJson("/api/purchase-orders/{$draftOrder->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('purchase_orders', ['id' => $draftOrder->id]);

        $convertedOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'created_by' => $user->id,
            'status' => PurchaseOrderStatus::CONVERTED,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        $this->deleteJson("/api/purchase-orders/{$convertedOrder->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_can_list_purchase_orders_with_item_and_order_discount_sums(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'discount_type' => 'fixed',
                    'discount_value' => 15,
                ],
            ],
        ])->assertCreated();

        $response = $this->getJson('/api/purchase-orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertEquals(15.0, (float) $response->json('data.0.items_sum_discount_amount'));
        $this->assertEquals(20.0, (float) $response->json('data.0.discount_amount'));
    }

    public function test_purchase_order_rejects_zero_unit_price_on_create_and_update(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 0],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_price']);

        $created = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50],
            ],
        ])->assertCreated();

        $this->putJson('/api/purchase-orders/'.$created->json('data.id'), [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 0],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_price']);
    }

    public function test_purchase_order_supports_additional_expenses_in_separate_table(): void
    {
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct('كتاب التجويد', 100.00);

        $createRes = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'discount_type' => 'fixed',
            'discount_value' => 20,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'شحن', 'amount' => '45.50', 'notes' => 'نقل بري'],
                ['name' => 'تغليف', 'amount' => '14.50', 'notes' => null],
            ],
        ])->assertCreated();

        $orderId = (int) $createRes->json('data.id');
        // Subtotal 300 - Discount 20 + Expenses 60.00 = 340.00
        $createRes->assertJsonPath('data.additional_expenses_total', '60.00')
            ->assertJsonPath('data.total', '340.00')
            ->assertJsonCount(2, 'data.expenses');

        $this->assertDatabaseHas('purchase_order_expenses', [
            'purchase_order_id' => $orderId,
            'name' => 'شحن',
            'amount' => 45.50,
            'notes' => 'نقل بري',
        ]);
        $this->assertDatabaseHas('purchase_order_expenses', [
            'purchase_order_id' => $orderId,
            'name' => 'تغليف',
            'amount' => 14.50,
        ]);
        $this->assertDatabaseCount('purchase_invoice_expenses', 0);
        $this->assertSame(2, PurchaseOrderExpense::query()->where('purchase_order_id', $orderId)->count());

        // Listing returns expenses_count
        $this->getJson('/api/purchase-orders')
            ->assertOk()
            ->assertJsonPath('data.0.expenses_count', 2);

        // Updating draft order replaces purchase_order_expenses and recalculates totals
        $this->putJson("/api/purchase-orders/{$orderId}", [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'رسوم جمركية', 'amount' => '25.00'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.additional_expenses_total', '25.00')
            ->assertJsonPath('data.total', '325.00')
            ->assertJsonCount(1, 'data.expenses');

        $this->assertDatabaseMissing('purchase_order_expenses', [
            'purchase_order_id' => $orderId,
            'name' => 'شحن',
        ]);
        $this->assertDatabaseHas('purchase_order_expenses', [
            'purchase_order_id' => $orderId,
            'name' => 'رسوم جمركية',
            'amount' => 25.00,
        ]);

        // Deleting draft order cleans up purchase_order_expenses
        $this->deleteJson("/api/purchase-orders/{$orderId}")->assertOk();
        $this->assertSame(0, PurchaseOrderExpense::query()->where('purchase_order_id', $orderId)->count());
    }

    public function test_purchase_order_attachment_upload_view_download_replace_remove_and_delete(): void
    {
        Storage::fake('local');
        $this->actingUser();
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        // Reject unsupported file extension
        $this->post('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50],
            ],
            'attachment' => UploadedFile::fake()->create('malware.exe', 10),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachment']);

        $firstFile = UploadedFile::fake()->create('po-quote.pdf', 120, 'application/pdf');
        $createRes = $this->post('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            ],
            'attachment' => $firstFile,
        ], ['Accept' => 'application/json'])->assertCreated();

        $orderId = (int) $createRes->json('data.id');
        $createRes->assertJsonPath('data.has_attachment', true)
            ->assertJsonPath('data.attachment_original_name', 'po-quote.pdf')
            ->assertJsonMissingPath('data.attachment_path')
            ->assertJsonMissingPath('data.attachment_disk');

        $order = PurchaseOrder::query()->findOrFail($orderId);
        $firstPath = $order->attachment_path;
        $this->assertNotEmpty($firstPath);
        Storage::disk('local')->assertExists($firstPath);

        $this->get("/api/purchase-orders/{$orderId}/attachment/view")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->get("/api/purchase-orders/{$orderId}/attachment/download")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Replace file via multipart PUT override
        $secondFile = UploadedFile::fake()->image('updated-quote.png');
        $updateRes = $this->post("/api/purchase-orders/{$orderId}", [
            '_method' => 'PUT',
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            ],
            'attachment' => $secondFile,
        ], ['Accept' => 'application/json'])->assertOk();

        $updateRes->assertJsonPath('data.has_attachment', true)
            ->assertJsonPath('data.attachment_original_name', 'updated-quote.png');

        $order->refresh();
        $secondPath = $order->attachment_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);

        // Remove attachment via remove_attachment=true
        $this->putJson("/api/purchase-orders/{$orderId}", [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            ],
            'remove_attachment' => true,
        ])->assertOk()
            ->assertJsonPath('data.has_attachment', false)
            ->assertJsonPath('data.attachment_original_name', null);

        Storage::disk('local')->assertMissing($secondPath);
        $this->get("/api/purchase-orders/{$orderId}/attachment/download")->assertNotFound();
    }
}
