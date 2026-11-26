<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalType;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Services\Accounting\JournalEntryContextService;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalEntryContextServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalYear $fy;

    private AccountingPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingSeeder::class);

        $this->fy = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);

        $this->period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $this->fy->id,
            'period_number' => 11,
            'name' => 'Nov 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);
    }

    public function test_resolves_generic_purchase_and_expense_contexts_and_delegates_from_journal_entry_service(): void
    {
        /** @var JournalEntryContextService $contextService */
        $contextService = app(JournalEntryContextService::class);
        /** @var JournalEntryService $jeService */
        $jeService = app(JournalEntryService::class);

        $cashJournal = Journal::query()->where('type', JournalType::Cash->value)->firstOrFail();
        $purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();
        $expenseJournal = Journal::query()->where('type', JournalType::Expense->value)->firstOrFail();

        $expectedCash = [
            'journal_id' => $cashJournal->id,
            'fiscal_year_id' => $this->fy->id,
            'accounting_period_id' => $this->period->id,
        ];
        $this->assertSame($expectedCash, $contextService->resolveContext($cashJournal->id, JournalType::Cash, '2026-11-15'));
        $this->assertSame($expectedCash, $jeService->resolveContext($cashJournal->id, JournalType::Cash, '2026-11-15'));

        $expectedPurchase = [
            'journal_id' => $purchaseJournal->id,
            'fiscal_year_id' => $this->fy->id,
            'accounting_period_id' => $this->period->id,
        ];
        $this->assertSame($expectedPurchase, $contextService->resolvePurchaseContext($purchaseJournal->id, '2026-11-15'));
        $this->assertSame($expectedPurchase, $jeService->resolvePurchaseContext($purchaseJournal->id, '2026-11-15'));

        $expectedExpense = [
            'journal_id' => $expenseJournal->id,
            'fiscal_year_id' => $this->fy->id,
            'accounting_period_id' => $this->period->id,
        ];
        $this->assertSame($expectedExpense, $contextService->resolveExpenseContext($expenseJournal->id, '2026-11-15'));
        $this->assertSame($expectedExpense, $jeService->resolveExpenseContext($expenseJournal->id, '2026-11-15'));
    }

    public function test_rejects_missing_inactive_or_wrong_type_journals(): void
    {
        /** @var JournalEntryContextService $contextService */
        $contextService = app(JournalEntryContextService::class);

        $purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();
        $expenseJournal = Journal::query()->where('type', JournalType::Expense->value)->firstOrFail();
        $inactiveJournal = Journal::query()->create([
            'code' => 'EXP-INACT',
            'name' => 'Inactive',
            'type' => JournalType::Expense,
            'is_active' => false,
        ]);

        // Missing journal
        try {
            $contextService->resolveExpenseContext(999999, '2026-11-15');
            $this->fail('Expected ValidationException for missing journal.');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounting.errors.expense_journal_missing'), $e->errors()['journal_id'][0]);
        }

        // Inactive journal
        try {
            $contextService->resolveExpenseContext($inactiveJournal->id, '2026-11-15');
            $this->fail('Expected ValidationException for inactive journal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_id', $e->errors());
        }

        // Wrong type: Purchase passed to Expense
        try {
            $contextService->resolveExpenseContext($purchaseJournal->id, '2026-11-15');
            $this->fail('Expected ValidationException for wrong type.');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounting.errors.expense_journal_missing'), $e->errors()['journal_id'][0]);
        }

        // Wrong type: Expense passed to Purchase
        try {
            $contextService->resolvePurchaseContext($expenseJournal->id, '2026-11-15');
            $this->fail('Expected ValidationException for wrong type.');
        } catch (ValidationException $e) {
            $this->assertSame(__('accounting.errors.purchase_journal_missing'), $e->errors()['journal_id'][0]);
        }
    }

    public function test_rejects_when_no_open_fiscal_year_or_period_covers_date(): void
    {
        /** @var JournalEntryContextService $contextService */
        $contextService = app(JournalEntryContextService::class);
        $expenseJournal = Journal::query()->where('type', JournalType::Expense->value)->firstOrFail();

        // Date outside any open fiscal year
        try {
            $contextService->resolveExpenseContext($expenseJournal->id, '2027-01-15');
            $this->fail('Expected ValidationException for missing open fiscal year.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fiscal_year_id', $e->errors());
        }

        // Date inside open fiscal year, but no open period covers it
        try {
            $contextService->resolveExpenseContext($expenseJournal->id, '2026-10-15');
            $this->fail('Expected ValidationException for missing open accounting period.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('accounting_period_id', $e->errors());
        }
    }
}
