<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Enums\DiscountType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\WarehouseStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function createSupplier(string $name = 'دار المعارف للطباعة'): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);
    }

    private function createProduct(string $name = 'كتاب القانون المدني', float $purchasePrice = 100.00): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'purchase_price' => $purchasePrice,
            'selling_price' => $purchasePrice * 1.5,
            'is_active' => true,
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
        // Subtotal: 180 + 170 = 350.00
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
            ->assertJsonPath('data.subtotal', '350.00')
            ->assertJsonPath('data.discount_amount', '35.00')
            ->assertJsonPath('data.total', '315.00');

        $this->assertDatabaseHas('purchase_orders', [
            'supplier_id' => $supplier->id,
            'status' => 'draft',
            'subtotal' => 350.00,
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
            'product_snapshot' => ['name' => $product1->name, 'sku' => $product1->sku],
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
            'product_snapshot' => ['name' => $product->name, 'sku' => $product->sku],
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
}
