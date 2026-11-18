<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PosShiftTransaction;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PosShiftTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected PosShiftService $shiftService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->user->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($this->user);

        $this->seed(PaymentMethodSeeder::class);
        $this->seed(WarehouseSeeder::class);

        $this->shiftService = app(PosShiftService::class);
    }

    public function test_can_open_shift(): void
    {
        $response = $this->postJson('/api/pos/shifts', [
            'opening_balance' => 500.00,
            'notes' => 'وردية الصباح',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.opening_balance', '500.00');
        $response->assertJsonPath('data.expected_cash', '500.00');
        $this->assertNull($response->json('data.closed_at'));

        $this->assertDatabaseHas('pos_shifts', [
            'opened_by' => $this->user->id,
            'opening_balance' => '500.00',
            'closed_at' => null,
        ]);
    }

    public function test_can_open_multiple_shifts_simultaneously(): void
    {
        $shift1 = $this->shiftService->openShift($this->user, 200.00);
        $shift2 = $this->shiftService->openShift($this->user, 400.00);

        $this->assertTrue($shift1->isOpen());
        $this->assertTrue($shift2->isOpen());

        $response = $this->getJson('/api/pos/shifts/open');
        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_record_expense_and_withdrawal(): void
    {
        $shift = $this->shiftService->openShift($this->user, 1000.00);

        // Expense
        $expResponse = $this->postJson("/api/pos/shifts/{$shift->id}/expenses", [
            'amount' => 50.00,
            'name' => 'شراء أدوات مكتبية',
            'description' => 'أوراق وأقلام',
        ]);
        $expResponse->assertCreated();
        $this->assertDatabaseHas('pos_shift_transactions', [
            'shift_id' => $shift->id,
            'type' => 'expense',
            'amount' => '50.00',
            'name' => 'شراء أدوات مكتبية',
        ]);

        // Withdrawal
        $withResponse = $this->postJson("/api/pos/shifts/{$shift->id}/withdrawals", [
            'amount' => 150.00,
            'name' => 'سحب نقدي للخزينة',
            'description' => 'إيداع بنكي جزئي',
        ]);
        $withResponse->assertCreated();
        $this->assertDatabaseHas('pos_shift_transactions', [
            'shift_id' => $shift->id,
            'type' => 'withdrawal',
            'amount' => '150.00',
            'name' => 'سحب نقدي للخزينة',
        ]);

        // Expected cash should be: 1000 - 50 - 150 = 800.00
        $shift->refresh();
        $this->assertEquals('800.00', $shift->expected_cash);
    }

    public function test_calculates_expected_cash_and_difference_upon_close(): void
    {
        $shift = $this->shiftService->openShift($this->user, 1000.00);

        // Record expense 100 and withdrawal 200 => expected = 700
        $this->shiftService->recordExpense($shift, $this->user, 100.00, 'ضيافة');
        $this->shiftService->recordWithdrawal($shift, $this->user, 200.00, 'سحب');

        // Close with actual cash = 650 (Shortage of 50 => difference = -50.00)
        $response = $this->postJson("/api/pos/shifts/{$shift->id}/close", [
            'actual_cash' => 650.00,
            'notes' => 'عجز 50 جنيه',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.expected_cash', '700.00');
        $response->assertJsonPath('data.actual_cash', '650.00');
        $response->assertJsonPath('data.difference', '-50.00');

        $shift->refresh();
        $this->assertTrue($shift->isClosed());
        $this->assertEquals('700.00', (string) $shift->expected_cash);
        $this->assertEquals('650.00', (string) $shift->actual_cash);
        $this->assertEquals('-50.00', (string) $shift->difference);
        $this->assertNotNull($shift->closed_at);
        $this->assertEquals($this->user->id, $shift->closed_by);
    }

    public function test_cannot_record_operations_on_closed_shift(): void
    {
        $shift = $this->shiftService->openShift($this->user, 500.00);
        $this->shiftService->closeShift($shift, $this->user, 500.00);

        // Attempt expense on closed shift
        $response = $this->postJson("/api/pos/shifts/{$shift->id}/expenses", [
            'amount' => 50.00,
            'name' => 'مصروف بعد الإغلاق',
        ]);
        $response->assertStatus(422);

        // Attempt closing again
        $closeAgain = $this->postJson("/api/pos/shifts/{$shift->id}/close", [
            'actual_cash' => 500.00,
        ]);
        $closeAgain->assertStatus(422);
    }

    public function test_shift_transactions_are_immutable(): void
    {
        $shift = $this->shiftService->openShift($this->user, 500.00);
        $tx = $this->shiftService->recordExpense($shift, $this->user, 40.00, 'فاتورة كهرباء');

        $this->expectException(LogicException::class);
        $tx->update(['amount' => '60.00']);
    }

    public function test_shift_transactions_cannot_be_deleted(): void
    {
        $shift = $this->shiftService->openShift($this->user, 500.00);
        $tx = $this->shiftService->recordExpense($shift, $this->user, 40.00, 'فاتورة مياه');

        $this->expectException(LogicException::class);
        $tx->delete();
    }

    public function test_shift_balances_expenses_withdrawals_and_sales_summary_use_exact_decimal_precision(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $visaMethod = PaymentMethod::query()->where('type', PaymentMethodType::VISA)->firstOrFail();
        /** @var PosSaleService $saleService */
        $saleService = app(PosSaleService::class);

        $product = Product::factory()->create([
            'name' => 'شرح الورقات',
            'selling_price' => 33.33,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '20.000',
        ]);

        // Open shift with string decimal '1000.55'
        $shift = $this->shiftService->openShift($this->user, '1000.55');
        $this->assertSame('1000.55', (string) $shift->opening_balance);

        // Cash sale: 1 * 19.99 = 19.99 -> expected_cash = 1000.55 + 19.99 = 1020.54
        $saleService->createSale([
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'items' => [['product_id' => $product->id, 'quantity' => '1.000', 'unit_price' => '19.99']],
        ], $this->user);

        // Visa sale: 1 * 33.33 = 33.33 -> does not affect cash, total_sales_amount = 19.99 + 33.33 = 53.32
        $saleService->createSale([
            'shift_id' => $shift->id,
            'payment_method_id' => $visaMethod->id,
            'items' => [['product_id' => $product->id, 'quantity' => '1.000', 'unit_price' => '33.33']],
        ], $this->user);

        // Expense: '10.15', Withdrawal: '25.25' -> expected_cash = 1020.54 - 10.15 - 25.25 = 985.14
        $this->shiftService->recordExpense($shift, $this->user, '10.15', 'إكرامية');
        $this->shiftService->recordWithdrawal($shift, $this->user, '25.25', 'توريد جزئي');

        // Close with actual_cash = '985.10' -> difference = 985.10 - 985.14 = -0.04
        $closed = $this->shiftService->closeShift($shift, $this->user, '985.10');
        $summary = $this->shiftService->getShiftSummary($closed);

        $this->assertSame('985.14', $summary['expected_cash']);
        $this->assertSame('985.10', $summary['actual_cash']);
        $this->assertSame('-0.04', $summary['difference']);
        $this->assertSame('19.99', $summary['cash_sales']);
        $this->assertSame('33.33', $summary['visa_sales']);
        $this->assertSame('53.32', $summary['total_sales_amount']);
        $this->assertSame('10.15', $summary['expenses']);
        $this->assertSame('25.25', $summary['withdrawals']);
    }

    public function test_shift_summary_query_count_remains_constant_for_1_10_and_50_sales(): void
    {
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $visaMethod = PaymentMethod::query()->where('type', PaymentMethodType::VISA)->firstOrFail();
        $bankMethod = PaymentMethod::query()->where('type', PaymentMethodType::BANK_TRANSFER)->firstOrFail();
        /** @var PosSaleService $saleService */
        $saleService = app(PosSaleService::class);

        $product = Product::factory()->create([
            'name' => 'أصول الفقه',
            'selling_price' => 25.50,
        ]);

        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '1000.000',
        ]);

        $shift = $this->shiftService->openShift($this->user, '500.00');
        $queryCounts = [];

        foreach ([1, 10, 50, 100] as $targetSales) {
            $existingCount = $shift->sales()->count();
            $toAdd = $targetSales - $existingCount;

            for ($i = 0; $i < $toAdd; $i++) {
                $method = match ($i % 3) {
                    0 => $cashMethod,
                    1 => $visaMethod,
                    default => $bankMethod,
                };

                $saleService->createSale([
                    'shift_id' => $shift->id,
                    'payment_method_id' => $method->id,
                    'items' => [['product_id' => $product->id, 'quantity' => '1.000', 'unit_price' => '25.50']],
                ], $this->user);
            }

            $freshShift = PosShift::query()->findOrFail($shift->id);
            $retrievedCount = 0;
            Event::listen('eloquent.retrieved:*', function () use (&$retrievedCount): void {
                $retrievedCount++;
            });

            DB::flushQueryLog();
            DB::enableQueryLog();

            $summary = $this->shiftService->getShiftSummary($freshShift);

            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            Event::forget('eloquent.retrieved:*');

            $this->assertSame($targetSales, $summary['sales_count']);
            $this->assertLessThanOrEqual(2, $retrievedCount, 'getShiftSummary must not hydrate sales or transactions into PHP memory.');
            $queryCounts[$targetSales] = count($queries);
        }

        $this->assertSame(
            $queryCounts[1],
            $queryCounts[10],
            'getShiftSummary query count must not grow between 1 and 10 sales.'
        );
        $this->assertSame(
            $queryCounts[10],
            $queryCounts[50],
            'getShiftSummary query count must not grow between 10 and 50 sales.'
        );
        $this->assertSame(
            $queryCounts[50],
            $queryCounts[100],
            'getShiftSummary query count must not grow between 50 and 100 sales.'
        );
    }

    public function test_empty_shift_summary_and_cash_return_transactions_pagination(): void
    {
        $shift = $this->shiftService->openShift($this->user, '250.00');

        $emptySummary = $this->shiftService->getShiftSummary($shift->fresh());
        $this->assertTrue($emptySummary['is_open']);
        $this->assertSame('250.00', $emptySummary['opening_balance']);
        $this->assertSame('250.00', $emptySummary['expected_cash']);
        $this->assertSame('0.00', $emptySummary['cash_sales']);
        $this->assertSame('0.00', $emptySummary['cash_returns']);
        $this->assertSame('0.00', $emptySummary['expenses']);
        $this->assertSame('0.00', $emptySummary['withdrawals']);
        $this->assertSame('0.00', $emptySummary['total_sales_amount']);
        $this->assertSame(0, $emptySummary['sales_count']);
        $this->assertSame(0, $emptySummary['returns_count']);
        $this->assertSame(0, $emptySummary['transactions_count']);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::CASH)->firstOrFail();
        $product = Product::factory()->create(['selling_price' => 40.00]);
        WarehouseStock::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => '10.000',
        ]);

        $sale = app(PosSaleService::class)->createSale([
            'shift_id' => $shift->id,
            'payment_method_id' => $cashMethod->id,
            'items' => [['product_id' => $product->id, 'quantity' => '2.000', 'unit_price' => '40.00']],
        ], $this->user);

        $saleItem = $sale->items()->firstOrFail();
        $return = app(PosReturnService::class)->processReturn([
            'shift_id' => $shift->id,
            'pos_sale_id' => $sale->id,
            'items' => [['pos_sale_item_id' => $saleItem->id, 'quantity' => '1.000']],
        ], $this->user);

        $summaryAfterReturn = $this->shiftService->getShiftSummary($shift->fresh());
        $this->assertSame(1, $summaryAfterReturn['returns_count']);
        $this->assertSame(2, $summaryAfterReturn['transactions_count']);
        $this->assertSame('80.00', $summaryAfterReturn['cash_sales']);
        $this->assertSame('40.00', $summaryAfterReturn['cash_returns']);
        $this->assertSame('290.00', $summaryAfterReturn['expected_cash']);

        $txResponse = $this->getJson("/api/pos/shifts/{$shift->id}/transactions?per_page=10");
        $txResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.type', PosShiftTransaction::TYPE_CASH_RETURN)
            ->assertJsonPath('data.1.return.return_number', $return->return_number)
            ->assertJsonPath('data.1.return.total_returned', '40.00');
    }
}
