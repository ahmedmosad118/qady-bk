<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Enums\DiscountType;
use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\PurchaseInvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\InventoryDocument;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\InventoryDocumentService;
use App\Services\InventoryDocumentWorkflowService;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Warehouse $defaultWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(WarehouseSeeder::class);
        $this->defaultWarehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
    }

    private function createSupplier(string $name = 'Al-Shorouk Press'): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);
    }

    private function createProduct(string $name = 'Usul al-Fiqh Book', float $purchasePrice = 100.00): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'purchase_price' => $purchasePrice,
            'selling_price' => $purchasePrice * 1.5,
            'is_active' => true,
        ]);
    }

    private function postGoodsReceiptThroughWorkflow(InventoryDocument $gr): void
    {
        $this->postJson("/api/inventory-documents/{$gr->id}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$gr->id}/approve")->assertOk();
        $this->postJson("/api/inventory-documents/{$gr->id}/post")->assertOk();
    }

    public function test_unauthenticated_user_cannot_access_purchase_invoices(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/purchase-invoices')->assertUnauthorized();
        $this->postJson('/api/purchase-invoices', [])->assertUnauthorized();
    }

    public function test_can_create_direct_purchase_invoice_with_auto_number_and_goods_receipt(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct('Al-Fiqh Al-Muyassar', 120.00);
        $today = now()->toDateString();

        $payload = [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'notes' => 'Direct cash purchase',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_price' => 110.00,
                ],
            ],
        ];

        $response = $this->postJson('/api/purchase-invoices', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', PurchaseInvoiceStatus::DRAFT->value)
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.purchase_order_id', null)
            ->assertJsonPath('data.subtotal', '550.00')
            ->assertJsonPath('data.discount_amount', '0.00')
            ->assertJsonPath('data.additional_expenses_total', '0.00')
            ->assertJsonPath('data.total', '550.00')
            ->assertJsonPath('data.goods_receipt.document_type', InventoryDocumentType::GOODS_RECEIPT->value)
            ->assertJsonPath('data.goods_receipt.status', InventoryDocumentStatus::DRAFT->value)
            ->assertJsonPath('data.goods_receipt.destination_warehouse_id', $this->defaultWarehouse->id);

        $invoiceNumber = $response->json('data.invoice_number');
        $this->assertNotEmpty($invoiceNumber);

        $invoiceId = (int) $response->json('data.id');
        $this->assertSame(sprintf('BILL/%s/%06d', now()->format('Y'), $invoiceId), $invoiceNumber);
        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);

        // Verify product snapshot structure matches Purchase Orders
        $item = $invoice->items()->firstOrFail();
        $this->assertSame([
            'name' => $product->name,
            'barcode' => $product->barcode,
            'unit_name' => 'piece',
        ], $item->product_snapshot);

        // Verify polymorphic relationship
        $gr = $invoice->goodsReceipt;
        $this->assertNotNull($gr);
        $this->assertSame('purchase_invoice', $gr->referenceable_type);
        $this->assertSame($invoice->id, $gr->referenceable_id);
        $this->assertInstanceOf(PurchaseInvoice::class, $gr->referenceable);
        $this->assertSame($invoice->id, $gr->referenceable->id);
        $this->assertCount(1, $gr->items);
        $this->assertEquals('5.000', $gr->items->first()->quantity);

        // No stock movements yet while GR is draft
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, WarehouseStock::count());
    }

    public function test_can_create_invoice_from_confirmed_purchase_order_and_reject_unconfirmed(): void
    {
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('Book 1', 100.00);
        $product2 = $this->createProduct('Book 2', 50.00);

        $draftOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);
        $draftOrder->items()->create([
            'product_id' => $product1->id,
            'product_snapshot' => ['name' => $product1->name, 'barcode' => $product1->barcode, 'unit_name' => 'piece'],
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_amount' => 0,
            'line_total' => 200.00,
        ]);

        // Reject creating invoice from draft PO
        $this->postJson("/api/purchase-invoices/from-purchase-order/{$draftOrder->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['purchase_order_id']);

        // Confirm PO -> status becomes 'converted'
        $this->postJson("/api/purchase-orders/{$draftOrder->id}/confirm")->assertOk();

        // Update PO with discounts on a fresh confirmed PO to test full copying
        $confirmedOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::CONVERTED,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10.00,
            'discount_amount' => 35.00,
            'subtotal' => 350.00,
            'total' => 315.00,
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
        $confirmedOrder->items()->create([
            'product_id' => $product1->id,
            'product_snapshot' => ['name' => $product1->name, 'barcode' => $product1->barcode, 'unit_name' => 'piece'],
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10.00,
            'discount_amount' => 20.00,
            'line_total' => 180.00,
        ]);
        $confirmedOrder->items()->create([
            'product_id' => $product2->id,
            'product_snapshot' => ['name' => $product2->name, 'barcode' => $product2->barcode, 'unit_name' => 'piece'],
            'quantity' => 4,
            'unit_price' => 50.00,
            'discount_type' => DiscountType::FIXED,
            'discount_value' => 30.00,
            'discount_amount' => 30.00,
            'line_total' => 170.00,
        ]);

        // Gross subtotal = 200 + 200 = 400.00
        // Item discounts = 20 + 30 = 50.00
        // Invoice discount = 10% of 350 = 35.00
        // Total = 400 - 50 - 35 = 315.00
        $res = $this->postJson("/api/purchase-invoices/from-purchase-order/{$confirmedOrder->id}", [
            'invoice_date' => '2026-11-16',
            'due_date' => '2026-11-30',
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.purchase_order_id', $confirmedOrder->id)
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.subtotal', '400.00')
            ->assertJsonPath('data.discount_type', 'percentage')
            ->assertJsonPath('data.discount_value', '10.00')
            ->assertJsonPath('data.discount_amount', '35.00')
            ->assertJsonPath('data.total', '315.00');

        $invoiceId = (int) $res->json('data.id');
        $invoice = PurchaseInvoice::query()->with(['items', 'goodsReceipt.items'])->findOrFail($invoiceId);
        $this->assertCount(2, $invoice->items);
        $this->assertNotNull($invoice->goodsReceipt);
        $this->assertCount(2, $invoice->goodsReceipt->items);

        // Cannot create a second active invoice from the same PO
        $this->postJson('/api/purchase-invoices', [
            'purchase_order_id' => $confirmedOrder->id,
            'invoice_date' => '2026-11-16',
            'due_date' => '2026-11-16',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['purchase_order_id']);

        // Also test creating via POST /api/purchase-invoices with purchase_order_id directly
        $res2 = $this->postJson('/api/purchase-invoices', [
            'purchase_order_id' => $draftOrder->id,
            'invoice_date' => '2026-11-16',
            'due_date' => '2026-11-16',
        ]);
        $res2->assertCreated()
            ->assertJsonPath('data.purchase_order_id', $draftOrder->id)
            ->assertJsonPath('data.total', '200.00');
    }

    public function test_multiple_products_duplicate_consolidation_expenses_and_calculation_rules(): void
    {
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('Law Book', 100.00);
        $product2 = $this->createProduct('History Book', 50.00);
        $today = now()->toDateString();

        // Product 1 appears twice (qty 1 + qty 1 = 2 @ 100 = 200 gross), item discount 10% = 20 -> line_total 180
        // Product 2: qty 4 @ 50 = 200 gross, item discount fixed 30 -> line_total 170
        // Subtotal (gross) = 200 + 200 = 400.00
        // Item discounts = 20 + 30 = 50.00
        // Net after item discounts = 350.00
        // Invoice discount = 10% of 350 = 35.00
        // Expenses: Shipping 40.00 + Loading 15.50 = 55.50
        // Final Total = 400.00 - 50.00 - 35.00 + 55.50 = 370.50
        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'items' => [
                [
                    'product_id' => $product1->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                    'discount_type' => 'percentage',
                    'discount_value' => 10,
                ],
                [
                    'product_id' => $product1->id,
                    'quantity' => 1,
                    'unit_price' => 100.00,
                ],
                [
                    'product_id' => $product2->id,
                    'quantity' => 4,
                    'unit_price' => 50.00,
                    'discount_type' => 'fixed',
                    'discount_value' => 30.00,
                ],
            ],
            'expenses' => [
                ['name' => 'Shipping', 'amount' => 40.00, 'notes' => 'Freight Carrier'],
                ['name' => 'Loading & Unloading', 'amount' => 15.50],
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.subtotal', '400.00')
            ->assertJsonPath('data.discount_amount', '35.00')
            ->assertJsonPath('data.additional_expenses_total', '55.50')
            ->assertJsonPath('data.total', '370.50');

        $invoice = PurchaseInvoice::query()->with(['items', 'expenses', 'goodsReceipt.items'])->findOrFail($res->json('data.id'));

        $this->assertCount(2, $invoice->items);
        $this->assertCount(2, $invoice->expenses);

        $item1 = $invoice->items->firstWhere('product_id', $product1->id);
        $item2 = $invoice->items->firstWhere('product_id', $product2->id);
        $this->assertEquals('2.000', $item1->quantity);
        $this->assertEquals('20.00', $item1->discount_amount);
        $this->assertEquals('180.00', $item1->line_total);
        $this->assertEquals('30.00', $item2->discount_amount);
        $this->assertEquals('170.00', $item2->line_total);

        // Same supplier can have another invoice on the same date
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 1, 'unit_price' => 95.00],
            ],
        ])->assertCreated();
    }

    public function test_rejects_excessive_discounts_and_invalid_values(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        // Item fixed discount exceeding gross line total
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 50,
                    'discount_type' => 'fixed',
                    'discount_value' => 150,
                ],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.discount_value']);

        // Invoice fixed discount exceeding net items total
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'discount_type' => 'fixed',
            'discount_value' => 200,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_value']);

        // Negative expense amount
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 50],
            ],
            'expenses' => [
                ['name' => 'Shipping', 'amount' => -10],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['expenses.0.amount']);
    }

    public function test_rejects_values_exceeding_allowed_decimal_places_instead_of_silently_rounding(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        $base = [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
        ];

        $this->postJson('/api/purchase-invoices', $base + [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.456']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_price']);

        $this->postJson('/api/purchase-invoices', $base + [
            'items' => [['product_id' => $product->id, 'quantity' => '1.2345', 'unit_price' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->postJson('/api/purchase-invoices', $base + [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
            'expenses' => [['name' => 'Shipping', 'amount' => '5.005']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['expenses.0.amount']);

        $this->assertSame(0, PurchaseInvoice::query()->count());
    }

    public function test_calculation_is_float_free_for_classic_binary_rounding_cases(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        // Binary-float trap: 0.145 * 3 = 0.435 -> must round half-up to 0.44 (float math gives 0.43).
        $response = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [['product_id' => $product->id, 'quantity' => 0.145, 'unit_price' => 3]],
        ])->assertCreated();

        $this->assertSame('0.44', $response->json('data.subtotal'));
        $this->assertSame('0.44', $response->json('data.total'));
    }

    public function test_invoice_numbers_are_system_generated_from_id_and_cannot_be_overridden(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $date = '2025-03-10';

        $payload = fn (array $extra = []): array => $extra + [
            'supplier_id' => $supplier->id,
            'invoice_date' => $date,
            'due_date' => $date,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ];

        $first = $this->postJson('/api/purchase-invoices', $payload())->assertCreated();
        $second = $this->postJson('/api/purchase-invoices', $payload())->assertCreated();

        // Year comes from the invoice date, sequence is the row id, zero-padded to 6 digits.
        $this->assertSame(sprintf('BILL/2025/%06d', $first->json('data.id')), $first->json('data.invoice_number'));
        $this->assertSame(sprintf('BILL/2025/%06d', $second->json('data.id')), $second->json('data.invoice_number'));
        $this->assertNotSame($first->json('data.invoice_number'), $second->json('data.invoice_number'));

        // The linked goods receipt note carries the final number, not the placeholder.
        $this->assertStringContainsString(
            $first->json('data.invoice_number'),
            (string) $first->json('data.goods_receipt.notes')
        );

        // Invoice numbers are system-generated only: a client-supplied number is ignored (on create and update).
        $third = $this->postJson('/api/purchase-invoices', $payload(['invoice_number' => 'SUP-INV-1']))->assertCreated();
        $this->assertSame(sprintf('BILL/2025/%06d', $third->json('data.id')), $third->json('data.invoice_number'));

        $this->putJson('/api/purchase-invoices/' . $third->json('data.id'), $payload(['invoice_number' => 'SUP-INV-2']))
            ->assertOk()
            ->assertJsonPath('data.invoice_number', $third->json('data.invoice_number'));

        $this->assertSame(0, PurchaseInvoice::query()->where('invoice_number', 'like', 'TMP-%')->count());
    }

    public function test_posting_goods_receipt_locks_invoice_before_document_to_avoid_deadlock(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        $invoice = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 10]],
        ])->assertCreated();

        $grId = (int) $invoice->json('data.goods_receipt.id');
        $this->postJson("/api/inventory-documents/{$grId}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$grId}/approve")->assertOk();

        $document = InventoryDocument::query()->findOrFail($grId);

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(InventoryDocumentWorkflowService::class)->post($document, $this->user);

        $firstIndexOf = function (string $table) use ($queries): int|false {
            foreach ($queries as $i => $sql) {
                if (stripos($sql, 'from "' . $table . '"') !== false) {
                    return $i;
                }
            }

            return false;
        };

        $invoiceIdx = $firstIndexOf('purchase_invoices');
        $documentIdx = $firstIndexOf('inventory_documents');

        $this->assertNotFalse($invoiceIdx, 'Invoice must be locked while posting its goods receipt.');
        $this->assertNotFalse($documentIdx);
        $this->assertLessThan($documentIdx, $invoiceIdx, 'Lock order must be invoice -> inventory document.');
        $this->assertSame(InventoryDocumentStatus::POSTED, InventoryDocument::query()->findOrFail($grId)->status);
    }

    public function test_editing_invoice_synchronizes_draft_goods_receipt(): void
    {
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('First Book', 100.00);
        $product2 = $this->createProduct('Second Book', 200.00);
        $today = now()->toDateString();

        $createRes = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 5, 'unit_price' => 100.00],
            ],
            'expenses' => [
                ['name' => 'Shipping', 'amount' => 25.00],
            ],
        ])->assertCreated();

        $invoiceId = (int) $createRes->json('data.id');
        $grId = (int) $createRes->json('data.goods_receipt.id');

        // Even if GR was submitted for approval (not yet posted), editing invoice syncs it and returns GR to draft
        $this->postJson("/api/inventory-documents/{$grId}/submit")->assertOk();

        $updateRes = $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->addDay()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'discount_type' => 'fixed',
            'discount_value' => 50.00,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 3, 'unit_price' => 100.00],
                ['product_id' => $product2->id, 'quantity' => 2, 'unit_price' => 200.00],
            ],
            'expenses' => [
                ['name' => 'Updated Shipping', 'amount' => 60.00],
            ],
        ]);

        // Subtotal = 300 + 400 = 700, discount = 50, expenses = 60 => total = 710.00
        $updateRes->assertOk()
            ->assertJsonPath('data.subtotal', '700.00')
            ->assertJsonPath('data.discount_amount', '50.00')
            ->assertJsonPath('data.additional_expenses_total', '60.00')
            ->assertJsonPath('data.total', '710.00')
            ->assertJsonPath('data.goods_receipt.id', $grId)
            ->assertJsonPath('data.goods_receipt.status', InventoryDocumentStatus::DRAFT->value);

        $gr = InventoryDocument::query()->with('items')->findOrFail($grId);
        $this->assertCount(2, $gr->items);
        $this->assertEquals('3.000', $gr->items->firstWhere('product_id', $product1->id)->quantity);
        $this->assertEquals('2.000', $gr->items->firstWhere('product_id', $product2->id)->quantity);
    }

    public function test_posting_goods_receipt_updates_stock_via_existing_workflow_and_locks_invoice(): void
    {
        $supplier = $this->createSupplier();
        $otherSupplier = $this->createSupplier('Other Supplier');
        $product = $this->createProduct('Posting Book', 80.00);
        $today = now()->toDateString();

        $createRes = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 80.00],
            ],
        ])->assertCreated();

        $invoiceId = (int) $createRes->json('data.id');
        $gr = InventoryDocument::query()->findOrFail($createRes->json('data.goods_receipt.id'));

        // Post through existing workflow
        $this->postGoodsReceiptThroughWorkflow($gr);

        // Stock movement created once and warehouse stock updated
        $this->assertSame(1, StockMovement::where('inventory_document_id', $gr->id)->count());
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $this->defaultWarehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
        $this->assertEquals('10.000', $stock->quantity);

        // Posting again must fail without duplicating movements
        $this->postJson("/api/inventory-documents/{$gr->id}/post")
            ->assertUnprocessable();
        $this->assertSame(1, StockMovement::where('inventory_document_id', $gr->id)->count());

        // Invoice is completely locked: update, cancel, and delete are all rejected
        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $otherSupplier->id,
            'invoice_date' => now()->addDay()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => 20, 'unit_price' => 90.00],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->postJson("/api/purchase-invoices/{$invoiceId}/cancel", ['reason' => 'Test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->deleteJson("/api/purchase-invoices/{$invoiceId}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Model-level delete protection as well
        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
        $this->expectException(\LogicException::class);
        $invoice->delete();
    }

    public function test_can_cancel_draft_invoice_and_automatically_cancel_draft_goods_receipt(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        $createRes = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 100.00],
            ],
        ])->assertCreated();

        $invoiceId = (int) $createRes->json('data.id');
        $grId = (int) $createRes->json('data.goods_receipt.id');

        $cancelRes = $this->postJson("/api/purchase-invoices/{$invoiceId}/cancel", [
            'reason' => 'Order cancelled by supplier',
        ]);

        $cancelRes->assertOk()
            ->assertJsonPath('data.status', PurchaseInvoiceStatus::CANCELLED->value)
            ->assertJsonPath('data.goods_receipt.status', InventoryDocumentStatus::CANCELLED->value);

        $this->assertDatabaseHas('purchase_invoices', [
            'id' => $invoiceId,
            'status' => PurchaseInvoiceStatus::CANCELLED->value,
            'deleted_at' => null,
        ]);

        $this->assertDatabaseHas('inventory_documents', [
            'id' => $grId,
            'status' => InventoryDocumentStatus::CANCELLED->value,
        ]);

        $this->assertSame(0, StockMovement::count());

        // Attempting to post a cancelled invoice's GR must be rejected
        $gr = InventoryDocument::query()->findOrFail($grId);
        $this->postJson("/api/inventory-documents/{$gr->id}/submit")->assertUnprocessable();
    }

    public function test_cannot_create_duplicate_goods_receipt_or_post_incomplete_goods_receipt(): void
    {
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('Book A', 50.00);
        $product2 = $this->createProduct('Book B', 60.00);
        $today = now()->toDateString();

        $createRes = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 5, 'unit_price' => 50.00],
                ['product_id' => $product2->id, 'quantity' => 3, 'unit_price' => 60.00],
            ],
        ])->assertCreated();

        $invoiceId = (int) $createRes->json('data.id');
        $grId = (int) $createRes->json('data.goods_receipt.id');

        // 1. Attempting to create a second Goods Receipt via API or Service must fail
        $this->postJson('/api/inventory-documents', [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => $today,
            'destination_warehouse_id' => $this->defaultWarehouse->id,
            'referenceable_type' => 'purchase_invoice',
            'referenceable_id' => $invoiceId,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 5],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['referenceable_id']);

        // 2. Direct update of linked GR via inventory endpoint is rejected
        $this->putJson("/api/inventory-documents/{$grId}", [
            'document_type' => InventoryDocumentType::GOODS_RECEIPT->value,
            'document_date' => $today,
            'destination_warehouse_id' => $this->defaultWarehouse->id,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 2],
            ],
        ])->assertUnprocessable();

        // 3. Simulate an incomplete GR (e.g. partial quantity) in DB and verify posting is blocked
        $gr = InventoryDocument::query()->findOrFail($grId);
        $gr->items()->where('product_id', $product1->id)->update(['quantity' => 2.000]);

        $this->postJson("/api/inventory-documents/{$gr->id}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$gr->id}/approve")->assertOk();
        $this->postJson("/api/inventory-documents/{$gr->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        $this->assertSame(0, StockMovement::count());
    }

    public function test_failed_operation_rolls_back_completely(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        // Deactivate default warehouse so GR creation fails inside transaction
        $this->defaultWarehouse->update(['is_active' => false]);

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 100.00],
            ],
            'expenses' => [
                ['name' => 'Shipping', 'amount' => 20.00],
            ],
        ]);

        $res->assertUnprocessable()
            ->assertJsonValidationErrors(['warehouse']);

        // Nothing persisted on rollback
        $this->assertSame(0, PurchaseInvoice::withTrashed()->count());
        $this->assertSame(0, DB::table('purchase_invoice_items')->count());
        $this->assertSame(0, DB::table('purchase_invoice_expenses')->count());
        $this->assertSame(0, InventoryDocument::withTrashed()->count());
    }

    public function test_mid_transaction_goods_receipt_failure_rolls_back_invoice_completely(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        $mock = $this->partialMock(InventoryDocumentService::class);
        $mock->shouldReceive('create')
            ->once()
            ->andThrow(ValidationException::withMessages(['items' => ['Simulated failure']]));

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 100.00],
            ],
            'expenses' => [
                ['name' => 'Loading', 'amount' => 30.00],
            ],
        ])->assertUnprocessable();

        $this->assertSame(0, PurchaseInvoice::withTrashed()->count());
        $this->assertSame(0, DB::table('purchase_invoice_items')->count());
        $this->assertSame(0, DB::table('purchase_invoice_expenses')->count());
        $this->assertSame(0, InventoryDocument::withTrashed()->count());
    }

    public function test_can_delete_draft_invoice_before_goods_receipt_posting(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();
        $today = now()->toDateString();

        $createRes = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 75.00],
            ],
        ])->assertCreated();

        $invoiceId = (int) $createRes->json('data.id');
        $grId = (int) $createRes->json('data.goods_receipt.id');

        $this->deleteJson("/api/purchase-invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('purchase_invoices', ['id' => $invoiceId]);
        $this->assertSoftDeleted('inventory_documents', ['id' => $grId]);
    }

    public function test_can_edit_invoice_created_from_purchase_order_before_posting_and_preserve_relation(): void
    {
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct('Book 1', 100.00);
        $product2 = $this->createProduct('Book 2', 50.00);

        $confirmedOrder = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::CONVERTED,
            'confirmed_at' => now(),
            'confirmed_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
        $confirmedOrder->items()->create([
            'product_id' => $product1->id,
            'product_snapshot' => ['name' => $product1->name, 'barcode' => $product1->barcode, 'unit_name' => 'piece'],
            'quantity' => 2,
            'unit_price' => 100.00,
            'discount_amount' => 0,
            'line_total' => 200.00,
        ]);

        $res = $this->postJson("/api/purchase-invoices/from-purchase-order/{$confirmedOrder->id}", [
            'invoice_date' => '2026-11-16',
            'due_date' => '2026-11-16',
        ])->assertCreated();

        $invoiceId = (int) $res->json('data.id');
        $grId = (int) $res->json('data.goods_receipt.id');

        $updateRes = $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'invoice_date' => '2026-11-16',
            'due_date' => '2026-11-25',
            'discount_type' => 'fixed',
            'discount_value' => 20.00,
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 4, 'unit_price' => 95.00],
                ['product_id' => $product2->id, 'quantity' => 2, 'unit_price' => 50.00],
            ],
        ]);

        // 4*95 + 2*50 = 380 + 100 = 480 - 20 = 460
        $updateRes->assertOk()
            ->assertJsonPath('data.purchase_order_id', $confirmedOrder->id)
            ->assertJsonPath('data.total', '460.00');

        $gr = InventoryDocument::query()->with('items')->findOrFail($grId);
        $this->assertCount(2, $gr->items);
        $this->assertEquals('4.000', $gr->items->firstWhere('product_id', $product1->id)->quantity);
        $this->assertEquals('2.000', $gr->items->firstWhere('product_id', $product2->id)->quantity);
    }

    public function test_can_list_filter_and_show_purchase_invoices(): void
    {
        $supplier1 = $this->createSupplier('Supplier 1');
        $supplier2 = $this->createSupplier('Supplier 2');
        $product = $this->createProduct();
        $today = now()->toDateString();

        $inv1 = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier1->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $inv2 = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier2->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])->assertCreated();

        $number1 = $inv1->json('data.invoice_number');

        $this->getJson('/api/purchase-invoices?search=' . urlencode($number1))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', $number1)
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/purchase-invoices?supplier_id={$supplier2->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.invoice_number', $inv2->json('data.invoice_number'));

        $id1 = $inv1->json('data.id');
        $this->getJson("/api/purchase-invoices/{$id1}")
            ->assertOk()
            ->assertJsonPath('data.id', $id1)
            ->assertJsonPath('data.invoice_number', $number1);

        // Verify listing includes item discount sum and invoice discount for table display.
        $inv3 = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier1->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'discount_type' => 'fixed',
            'discount_value' => 15,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'discount_type' => 'fixed',
                    'discount_value' => 10,
                ],
            ],
        ])->assertCreated();

        $listResponse = $this->getJson('/api/purchase-invoices?search=' . urlencode($inv3->json('data.invoice_number')))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertEquals(10.0, (float) $listResponse->json('data.0.items_sum_discount_amount'));
        $this->assertEquals(15.0, (float) $listResponse->json('data.0.discount_amount'));
    }
}
