<?php

declare(strict_types=1);

namespace Tests\Feature\Expense;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseItem;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PurchaseInvoice;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseJournalSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createSuperAdmin();
    }

    public function test_accounting_seeder_creates_expense_journal_idempotently_and_preserves_existing_journals(): void
    {
        $this->seed(AccountingSeeder::class);
        $this->seed(AccountingSeeder::class);

        $this->assertSame(1, Journal::query()->where('code', 'EXP')->count());

        $expJournal = Journal::query()->where('code', 'EXP')->firstOrFail();
        $this->assertSame('دفتر المصروفات', $expJournal->name);
        $this->assertSame(JournalType::Expense, $expJournal->type);
        $this->assertTrue($expJournal->is_active);

        foreach (['SAL', 'PUR', 'EXP', 'CSH', 'BNK', 'MSC', 'ADJST', 'STJ', 'OPBJ'] as $code) {
            $this->assertSame(1, Journal::query()->where('code', $code)->count(), "Duplicate or missing journal {$code}");
        }
    }

    public function test_morph_map_registers_expense_and_preserves_existing_aliases(): void
    {
        $this->assertSame('expense', (new Expense)->getMorphClass());
        $this->assertSame(Expense::class, Relation::getMorphedModel('expense'));

        $this->assertSame(Stocktake::class, Relation::getMorphedModel('stocktake'));
        $this->assertSame(PosSale::class, Relation::getMorphedModel('pos_sale'));
        $this->assertSame(PosReturn::class, Relation::getMorphedModel('pos_return'));
        $this->assertSame(PurchaseInvoice::class, Relation::getMorphedModel('purchase_invoice'));
        $this->assertSame(Payment::class, Relation::getMorphedModel('payment'));
    }

    public function test_resolve_expense_context_validates_journal_fiscal_year_and_period(): void
    {
        $this->seed(AccountingSeeder::class);

        $fy = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);

        $period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $fy->id,
            'period_number' => 11,
            'name' => 'Nov 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $expJournal = Journal::query()->where('code', 'EXP')->firstOrFail();
        $purJournal = Journal::query()->where('code', 'PUR')->firstOrFail();

        /** @var JournalEntryService $service */
        $service = app(JournalEntryService::class);

        $resolved = $service->resolveExpenseContext($expJournal->id, '2026-11-15');
        $this->assertSame([
            'journal_id' => $expJournal->id,
            'fiscal_year_id' => $fy->id,
            'accounting_period_id' => $period->id,
        ], $resolved);

        // Purchase context still works
        $purchaseResolved = $service->resolvePurchaseContext($purJournal->id, '2026-11-15');
        $this->assertSame($purJournal->id, $purchaseResolved['journal_id']);

        // Rejects Purchase journal when Expense expected
        try {
            $service->resolveExpenseContext($purJournal->id, '2026-11-15');
            $this->fail('Expected ValidationException for Purchase journal in Expense context.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_id', $e->errors());
            $this->assertSame(__('accounting.errors.expense_journal_missing'), $e->errors()['journal_id'][0]);
        }

        // Rejects inactive journal
        $inactive = Journal::query()->create([
            'code' => 'EXP-OFF',
            'name' => 'Inactive Expense',
            'type' => JournalType::Expense,
            'is_active' => false,
        ]);
        try {
            $service->resolveExpenseContext($inactive->id, '2026-11-15');
            $this->fail('Expected ValidationException for inactive journal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_id', $e->errors());
        }

        // Rejects missing open fiscal year / period
        try {
            $service->resolveExpenseContext($expJournal->id, '2027-01-15');
            $this->fail('Expected ValidationException for missing open fiscal year.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('fiscal_year_id', $e->errors());
        }

        try {
            $service->resolveExpenseContext($expJournal->id, '2026-10-15');
            $this->fail('Expected ValidationException for missing open period.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('accounting_period_id', $e->errors());
        }
    }

    public function test_get_details_loads_expense_source_relations(): void
    {
        $this->seed(AccountingSeeder::class);

        $fy = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);
        $period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $fy->id,
            'period_number' => 11,
            'name' => 'Nov 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $expJournal = Journal::query()->where('code', 'EXP')->firstOrFail();
        $expAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Expenses->value))
            ->firstOrFail();
        $payableAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Payable->value))
            ->firstOrFail();

        $supplier = Contact::factory()->create(['is_supplier' => true, 'is_active' => true]);
        $category = ExpenseCategory::factory()->create(['name' => 'Utilities']);

        $expense = Expense::factory()->create([
            'contact_id' => $supplier->id,
            'journal_id' => $expJournal->id,
            'expense_date' => '2026-11-15',
            'subtotal' => 150.00,
            'total' => 150.00,
            'created_by' => $this->user->id,
        ]);
        ExpenseItem::factory()->create([
            'expense_id' => $expense->id,
            'account_id' => $expAccount->id,
            'expense_category_id' => $category->id,
            'amount' => 150.00,
        ]);

        /** @var JournalEntryService $service */
        $service = app(JournalEntryService::class);
        $entry = $service->createDraftForSource([
            'journal_id' => $expJournal->id,
            'fiscal_year_id' => $fy->id,
            'accounting_period_id' => $period->id,
            'contact_id' => $supplier->id,
            'entry_date' => '2026-11-15',
            'reference' => $expense->expense_number,
            'lines' => [
                ['account_id' => $expAccount->id, 'debit' => '150.00', 'credit' => '0.00', 'label' => 'Utilities'],
                ['account_id' => $payableAccount->id, 'debit' => '0.00', 'credit' => '150.00', 'label' => $supplier->name],
            ],
        ], $expense, $this->user);

        $details = $service->getDetails($entry->fresh());

        $this->assertSame('expense', $details->source_type);
        $this->assertInstanceOf(Expense::class, $details->source);
        $this->assertTrue($details->source->relationLoaded('contact'));
        $this->assertTrue($details->source->relationLoaded('journal'));
        $this->assertTrue($details->source->relationLoaded('items'));
        $this->assertTrue($details->source->items->first()->relationLoaded('account'));
        $this->assertTrue($details->source->items->first()->relationLoaded('category'));
    }

    public function test_backend_pagination_on_expenses_categories_and_journal_entries(): void
    {
        $this->seed(AccountingSeeder::class);
        Sanctum::actingAs($this->user);

        $expJournal = Journal::query()->where('code', 'EXP')->firstOrFail();
        $expAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Expenses->value))
            ->firstOrFail();
        $payableAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Payable->value))
            ->firstOrFail();

        $categories = ExpenseCategory::factory()->count(3)->create();

        foreach (range(1, 3) as $i) {
            $exp = Expense::factory()->create([
                'journal_id' => $expJournal->id,
                'expense_date' => '2026-11-15',
                'created_by' => $this->user->id,
            ]);
            ExpenseItem::factory()->create([
                'expense_id' => $exp->id,
                'account_id' => $expAccount->id,
                'expense_category_id' => $categories[$i - 1]->id,
                'amount' => 100 * $i,
            ]);
        }

        // 1. Expenses pagination
        $this->getJson('/api/expenses?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/expenses?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->getJson('/api/expenses?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        // 2. Expense Categories pagination + all=1 exception
        $this->getJson('/api/expense-categories?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);

        $this->getJson('/api/expense-categories?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $this->getJson('/api/expense-categories?all=1')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissingPath('meta');

        // 3. Journal Entries pagination
        $fy = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'FY 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);
        $period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $fy->id,
            'period_number' => 11,
            'name' => 'Nov 2026',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        foreach (range(1, 3) as $i) {
            $je = JournalEntry::query()->create([
                'journal_id' => $expJournal->id,
                'fiscal_year_id' => $fy->id,
                'accounting_period_id' => $period->id,
                'entry_number' => "JE-2026-0000{$i}",
                'entry_date' => '2026-11-15',
                'created_by' => $this->user->id,
            ]);
            $je->lines()->createMany([
                ['account_id' => $expAccount->id, 'line_number' => 1, 'debit' => 100, 'credit' => 0],
                ['account_id' => $payableAccount->id, 'line_number' => 2, 'debit' => 0, 'credit' => 100],
            ]);
        }

        $this->getJson('/api/accounting/journal-entries?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->getJson('/api/accounting/journal-entries?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }
}
