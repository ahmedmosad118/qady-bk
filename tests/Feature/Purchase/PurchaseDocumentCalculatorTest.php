<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Models\Contact;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Purchase\PurchaseDocumentCalculator;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseDocumentCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseDocumentCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PurchaseDocumentCalculator();
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(bool $active = true): Collection
    {
        $product = Product::factory()->make(['id' => 1, 'name' => 'Book', 'is_active' => $active]);

        return collect([1 => $product]);
    }

    public function test_rounds_half_up_without_float_error(): void
    {
        $calc = $this->calculator->calculate(
            [['product_id' => 1, 'quantity' => 0.145, 'unit_price' => 3]],
            $this->products()
        );

        $this->assertSame('0.44', $calc['subtotal']);
        $this->assertSame('0.44', $calc['total']);
    }

    public function test_percentage_discount_rounds_half_up(): void
    {
        // 12.5% of 33.33 = 4.16625 -> 4.17
        $calc = $this->calculator->calculate(
            [['product_id' => 1, 'quantity' => 1, 'unit_price' => '33.33', 'discount_type' => 'percentage', 'discount_value' => '12.5']],
            $this->products()
        );

        $this->assertSame('4.17', $calc['items'][0]['discount_amount']);
        $this->assertSame('29.16', $calc['items'][0]['line_total']);
        $this->assertSame('33.33', $calc['subtotal']);
        $this->assertSame('29.16', $calc['net_lines_total']);
        $this->assertSame('4.17', $calc['items_discount_total']);
    }

    public function test_totals_include_document_discount_and_expenses(): void
    {
        $calc = $this->calculator->calculate(
            [['product_id' => 1, 'quantity' => 2, 'unit_price' => 100]],
            $this->products(),
            'fixed',
            '20',
            [['name' => 'Shipping', 'amount' => '15.50']]
        );

        $this->assertSame('200.00', $calc['subtotal']);
        $this->assertSame('20.00', $calc['discount_amount']);
        $this->assertSame('15.50', $calc['additional_expenses_total']);
        $this->assertSame('195.50', $calc['total']);
    }

    public function test_rejects_more_decimals_than_scale_and_non_numeric(): void
    {
        foreach ([
            ['quantity' => '1.2345', 'unit_price' => 1, 'key' => 'items.0.quantity'],
            ['quantity' => 1, 'unit_price' => '1.005', 'key' => 'items.0.unit_price'],
            ['quantity' => '1e3', 'unit_price' => 1, 'key' => 'items.0.quantity'],
            ['quantity' => true, 'unit_price' => 1, 'key' => 'items.0.quantity'],
        ] as $case) {
            try {
                $this->calculator->calculate(
                    [['product_id' => 1, 'quantity' => $case['quantity'], 'unit_price' => $case['unit_price']]],
                    $this->products()
                );
                $this->fail('Expected ValidationException for ' . $case['key']);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($case['key'], $e->errors());
            }
        }
    }

    public function test_inactive_products_are_always_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->calculator->calculate(
            [['product_id' => 1, 'quantity' => 1, 'unit_price' => 5]],
            $this->products(false)
        );
    }

    public function test_duplicate_discount_error_key_uses_original_line_index(): void
    {
        $items = [
            ['product_id' => 1, 'quantity' => 1, 'unit_price' => 10],
            ['product_id' => 1, 'quantity' => 1, 'unit_price' => 10],
        ];
        $items[0]['discount_type'] = 'fixed';
        $items[0]['discount_value'] = 50;

        try {
            $this->calculator->calculate($items, $this->products());
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items.0.discount_value', $e->errors());
        }
    }

    public function test_purchase_order_and_invoice_produce_identical_amounts_for_same_input(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->seed(WarehouseSeeder::class);
        Warehouse::query()->where('is_default', true)->firstOrFail();

        $supplier = Contact::factory()->create(['is_supplier' => true, 'is_customer' => false, 'is_active' => true]);
        $product = Product::factory()->create(['is_active' => true]);
        $today = now()->toDateString();

        $items = [
            ['product_id' => $product->id, 'quantity' => 0.145, 'unit_price' => 3],
            ['product_id' => Product::factory()->create(['is_active' => true])->id, 'quantity' => 1, 'unit_price' => '33.33', 'discount_type' => 'percentage', 'discount_value' => '12.5'],
        ];

        $order = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => $today,
            'discount_type' => 'percentage',
            'discount_value' => '7.5',
            'items' => $items,
        ])->assertCreated();

        $invoice = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'discount_type' => 'percentage',
            'discount_value' => '7.5',
            'items' => $items,
        ])->assertCreated();

        $this->assertSame($order->json('data.discount_amount'), $invoice->json('data.discount_amount'));
        $this->assertSame($order->json('data.subtotal'), $invoice->json('data.subtotal'));
        $this->assertSame($order->json('data.total'), $invoice->json('data.total'));
        $this->assertSame(
            $order->json('data.items.0.line_total'),
            $invoice->json('data.items.0.line_total')
        );
        $this->assertSame(
            $order->json('data.items.1.discount_amount'),
            $invoice->json('data.items.1.discount_amount')
        );
    }

    public function test_purchase_order_rejects_excess_decimals_and_excess_fixed_discount(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $supplier = Contact::factory()->create(['is_supplier' => true, 'is_customer' => false, 'is_active' => true]);
        $product = Product::factory()->create(['is_active' => true]);

        $base = ['supplier_id' => $supplier->id, 'order_date' => now()->toDateString()];

        $this->postJson('/api/purchase-orders', $base + [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '10.456']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.unit_price']);

        $this->postJson('/api/purchase-orders', $base + [
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'discount_type' => 'fixed', 'discount_value' => 99]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.discount_value']);
    }
}
