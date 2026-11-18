<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Product\ProductBarcodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductBarcodeLabelTest extends TestCase
{
    use RefreshDatabase;

    private function login(): void
    {
        $this->actingAs(User::factory()->create());
    }

    public function test_generator_builds_valid_ean13_with_internal_prefix(): void
    {
        $generator = new ProductBarcodeGenerator();

        $this->assertSame('2000000000015', $generator->fromSequence(1));
        $code = $generator->fromSequence(123456);
        $this->assertSame(13, strlen($code));
        $this->assertStringStartsWith('200', $code);
        $this->assertSame($generator->checkDigit(substr($code, 0, 12)), (int) $code[12]);
    }

    public function test_missing_barcodes_are_generated_and_persisted_uniquely(): void
    {
        $this->login();
        $a = Product::factory()->create();
        $b = Product::factory()->create();

        $response = $this->postJson('/api/products/barcode-labels', [
            'items' => [
                ['product_id' => $a->id, 'copies' => 3],
                ['product_id' => $b->id, 'copies' => 1],
            ],
        ]);

        $response->assertOk()->assertJsonPath('meta.generated', 2);
        $codes = collect($response->json('data'))->pluck('barcode');
        $this->assertCount(2, $codes->unique());
        $this->assertSame($codes[0], $a->fresh()->barcode);
        $this->assertSame($codes[1], $b->fresh()->barcode);
        $this->assertSame(3, $response->json('data.0.copies'));
    }

    public function test_existing_barcode_is_kept_and_sequence_continues(): void
    {
        $this->login();
        $generator = new ProductBarcodeGenerator();
        $existing = Product::factory()->withBarcode('6221000000017')->create();
        $internal = Product::factory()->withBarcode($generator->fromSequence(5))->create();
        $new = Product::factory()->create();

        $response = $this->postJson('/api/products/barcode-labels', [
            'items' => [
                ['product_id' => $existing->id, 'copies' => 1],
                ['product_id' => $new->id, 'copies' => 1],
            ],
        ]);

        $response->assertOk()->assertJsonPath('meta.generated', 1)
            ->assertJsonPath('data.0.barcode', '6221000000017')
            ->assertJsonPath('data.0.barcode_generated', false)
            ->assertJsonPath('data.1.barcode', $generator->fromSequence(6));
        $this->assertSame($internal->barcode, $internal->fresh()->barcode);
    }

    public function test_sequence_accounts_for_soft_deleted_products(): void
    {
        $this->login();
        $generator = new ProductBarcodeGenerator();
        Product::factory()->withBarcode($generator->fromSequence(9))->create()->delete();
        $new = Product::factory()->create();

        $this->postJson('/api/products/barcode-labels', [
            'items' => [['product_id' => $new->id, 'copies' => 1]],
        ])->assertOk();

        $this->assertSame($generator->fromSequence(10), $new->fresh()->barcode);
    }

    public function test_duplicate_items_are_merged(): void
    {
        $this->login();
        $p = Product::factory()->create();

        $this->postJson('/api/products/barcode-labels', [
            'items' => [
                ['product_id' => $p->id, 'copies' => 2],
                ['product_id' => $p->id, 'copies' => 3],
            ],
        ])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.copies', 5);
    }

    public function test_validation_rules(): void
    {
        $this->login();
        $p = Product::factory()->create();

        $this->postJson('/api/products/barcode-labels', ['items' => []])->assertUnprocessable();
        $this->postJson('/api/products/barcode-labels', [
            'items' => [['product_id' => 999999, 'copies' => 1]],
        ])->assertUnprocessable();
        $this->postJson('/api/products/barcode-labels', [
            'items' => [['product_id' => $p->id, 'copies' => 501]],
        ])->assertUnprocessable();
        $this->postJson('/api/products/barcode-labels', [
            'items' => [['product_id' => $p->id, 'copies' => 0]],
        ])->assertUnprocessable();
        $this->assertNull($p->fresh()->barcode);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/products/barcode-labels', [])->assertUnauthorized();
    }

    public function test_products_index_can_be_filtered_by_ids(): void
    {
        $this->login();
        $a = Product::factory()->create();
        Product::factory()->create();
        $c = Product::factory()->create();

        $response = $this->getJson("/api/products?ids={$a->id},{$c->id}")->assertOk();

        $this->assertEqualsCanonicalizing([$a->id, $c->id], collect($response->json('data'))->pluck('id')->all());
    }
}
