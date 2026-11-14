<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\PaymentMethod;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\Pos\PosReturnService;
use App\Services\Pos\PosSaleService;
use App\Services\Pos\PosShiftService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PosDashboardAndPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Warehouse $warehouse;
    protected PosShift $shift;
    protected PaymentMethod $cashMethod;
    protected PaymentMethod $visaMethod;
    protected PaymentMethod $bankTransferMethod;
    protected Product $product;
    protected PosSaleService $saleService;
    protected PosShiftService $shiftService;
    protected PosReturnService $returnService;

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
        $this->returnService = app(PosReturnService::class);

        $this->shift = $this->shiftService->openShift($this->user, 500.00);

        $this->product = Product::factory()->create([
            'name' => 'حلية طالب العلم',
            'sku' => 'BK-HILYA-001',
            'selling_price' => 100.00,
            'is_active' => true,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'quantity' => '50.000',
        ]);
    }

    public function test_can_list_active_payment_methods(): void
    {
        $response = $this->getJson('/api/pos/payment-methods');
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('نقدي', $names);
        $this->assertContains('فيزا', $names);
        $this->assertContains('تحويل بنكي', $names);
    }

    public function test_dashboard_stats_calculates_totals_and_net_sales(): void
    {
        // 1. Cash sale: 2 * 100 = 200
        $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->cashMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2],
            ],
        ], $this->user);

        // 2. Visa sale: 3 * 100 = 300
        $visaSale = $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->visaMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 3],
            ],
        ], $this->user);

        // 3. Bank transfer sale: 1 * 100 = 100
        $this->saleService->createSale([
            'shift_id' => $this->shift->id,
            'payment_method_id' => $this->bankTransferMethod->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1],
            ],
        ], $this->user);

        // 4. Return 1 unit from Visa sale (refund 100)
        $saleItem = $visaSale->items()->first();
        $this->returnService->processReturn([
            'shift_id' => $this->shift->id,
            'pos_sale_id' => $visaSale->id,
            'items' => [
                ['pos_sale_item_id' => $saleItem->id, 'quantity' => 1],
            ],
        ], $this->user);

        // Total gross sales = 200 + 300 + 100 = 600.00
        // Total returns = 100.00
        // Net sales = 500.00
        $response = $this->getJson('/api/pos/dashboard/stats');
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.invoice_count', 3);
        $response->assertJsonPath('data.gross_sales', '600.00');
        $response->assertJsonPath('data.cash_sales', '200.00');
        $response->assertJsonPath('data.visa_sales', '300.00');
        $response->assertJsonPath('data.bank_transfer_sales', '100.00');
        $response->assertJsonPath('data.returns_count', 1);
        $response->assertJsonPath('data.total_returns', '100.00');
        $response->assertJsonPath('data.net_sales', '500.00');
    }
}
