<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalizedMessagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function langFiles(): array
    {
        return ['inventory' => ['inventory'], 'stocktakes' => ['stocktakes'], 'purchases' => ['purchases']];
    }

    #[DataProvider('langFiles')]
    public function test_arabic_and_english_lang_files_have_same_keys_and_placeholders(string $file): void
    {
        $en = Arr::dot(require lang_path("en/{$file}.php"));
        $ar = Arr::dot(require lang_path("ar/{$file}.php"));

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($ar))), "Keys missing in ar/{$file}.php");
        $this->assertSame([], array_values(array_diff(array_keys($ar), array_keys($en))), "Keys missing in en/{$file}.php");

        $placeholders = static function (string $text): array {
            preg_match_all('/:[a-z_]+/i', $text, $m);
            $found = array_unique($m[0]);
            sort($found);

            return $found;
        };

        foreach ($en as $key => $text) {
            $this->assertSame(
                $placeholders((string) $text),
                $placeholders((string) $ar[$key]),
                "Placeholders differ for {$file}.{$key}"
            );
        }
    }

    public function test_purchase_request_validation_messages_follow_app_locale(): void
    {
        $this->actingAsSuperAdmin();
        $supplier = Contact::factory()->create(['is_supplier' => true, 'is_customer' => false, 'is_active' => true]);
        $product = Product::factory()->create(['is_active' => true]);

        $payload = [
            'supplier_id' => $supplier->id,
            'order_date' => now()->toDateString(),
            'discount_type' => 'percentage',
            'discount_value' => '150',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ];

        app()->setLocale('en');
        $this->postJson('/api/purchase-orders', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.discount_value.0', 'Document discount percentage cannot exceed 100%.');

        app()->setLocale('ar');
        $this->postJson('/api/purchase-orders', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.discount_value.0', 'نسبة الخصم الإجمالي لا يمكن أن تتجاوز 100%.');

        // Attribute names are localized too (missing required field).
        $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['order_date', 'items']);

        app()->setLocale('en');
        $response = $this->postJson('/api/purchase-orders', ['supplier_id' => $supplier->id])->assertUnprocessable();
        $this->assertStringContainsString('order date', (string) $response->json('errors.order_date.0'));
    }

    public function test_invoice_discount_requires_type_when_value_is_sent_and_orders_do_not_in_request_layer(): void
    {
        $this->actingAsSuperAdmin();
        $supplier = Contact::factory()->create(['is_supplier' => true, 'is_customer' => false, 'is_active' => true]);
        $product = Product::factory()->create(['is_active' => true]);
        $today = now()->toDateString();

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => $today,
            'due_date' => $today,
            'discount_value' => 5,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'discount_value' => 2]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_type', 'items.0.discount_type']);

        // Orders keep their previous behaviour: the request layer only checks the 100% cap,
        // the type-required rule is enforced by the shared calculator on `discount_value`.
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $supplier->id,
            'order_date' => $today,
            'discount_value' => 5,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['discount_value'])
            ->assertJsonMissingValidationErrors(['discount_type']);
    }
}
