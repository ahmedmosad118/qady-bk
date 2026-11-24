<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Support\DocumentSequenceGenerator;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDOException;
use Tests\TestCase;

class JournalEntryCreateFromSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Journal $journal;

    private FiscalYear $fiscalYear;

    private AccountingPeriod $period;

    /** @var array<int, Account> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountingSeeder::class);

        $this->user = User::factory()->create();
        $this->journal = Journal::query()->where('is_active', true)->firstOrFail();

        $this->fiscalYear = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'السنة المالية 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $this->period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'name' => 'نوفمبر 2026',
            'period_number' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open,
        ]);

        $this->accounts = Account::query()->where('is_active', true)->limit(2)->get()->all();
        $this->assertCount(2, $this->accounts);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'journal_id' => $this->journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2026-11-25',
            'reference' => 'AUTO-REF-001',
            'description' => 'قيد تلقائي من فاتورة مشتريات',
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'مدين',
                    'debit' => 500.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[1]->id,
                    'label' => 'دائن',
                    'debit' => 0,
                    'credit' => 500.00,
                ],
            ],
        ], $overrides);
    }

    public function test_create_from_source_creates_posted_entry_with_morph_source_and_audit_fields(): void
    {
        $invoice = PurchaseInvoice::factory()->create();

        $entry = app(JournalEntryService::class)->createFromSource(
            $this->validPayload(),
            $invoice,
            $this->user,
        );

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($invoice->getMorphClass(), $entry->source_type);
        $this->assertSame($invoice->id, $entry->source_id);
        $this->assertSame($this->user->id, $entry->created_by);
        $this->assertSame($this->user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);

        $this->assertTrue((bool) $entry->getAttribute('is_balanced'));
        $this->assertSame('500.00', $entry->getAttribute('total_debit'));
        $this->assertSame('500.00', $entry->getAttribute('total_credit'));
        $this->assertCount(2, $entry->lines);

        $resolvedSource = $entry->source()->first();
        $this->assertInstanceOf(PurchaseInvoice::class, $resolvedSource);
        $this->assertSame($invoice->id, $resolvedSource->id);
    }

    public function test_create_from_source_ignores_status_and_source_overrides_in_data(): void
    {
        $invoice = PurchaseInvoice::factory()->create();

        $entry = app(JournalEntryService::class)->createFromSource(
            $this->validPayload([
                'status' => JournalEntryStatus::Draft->value,
                'source_type' => 'spoofed_type',
                'source_id' => 999999,
            ]),
            $invoice,
            $this->user,
        );

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($invoice->getMorphClass(), $entry->source_type);
        $this->assertSame($invoice->id, $entry->source_id);
    }

    public function test_create_from_source_rejects_invalid_accounting_context_without_partial_records(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $this->period->update(['status' => AccountingPeriodStatus::Closed]);

        $this->expectException(ValidationException::class);

        try {
            app(JournalEntryService::class)->createFromSource(
                $this->validPayload(),
                $invoice,
                $this->user,
            );
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0, JournalEntryLine::query()->count());
        }
    }

    public function test_create_from_source_rejects_unbalanced_lines_without_partial_records(): void
    {
        $invoice = PurchaseInvoice::factory()->create();

        $payload = $this->validPayload([
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'مدين',
                    'debit' => 500.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[1]->id,
                    'label' => 'دائن',
                    'debit' => 0,
                    'credit' => 450.00,
                ],
            ],
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(JournalEntryService::class)->createFromSource(
                $payload,
                $invoice,
                $this->user,
            );
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0, JournalEntryLine::query()->count());
        }
    }

    public function test_manual_create_remains_draft_only_without_source_information(): void
    {
        $entry = app(JournalEntryService::class)->create(
            $this->validPayload([
                'source_type' => 'purchase_invoice',
                'source_id' => 123,
            ]),
            $this->user,
        );

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertNull($entry->source_type);
        $this->assertNull($entry->source_id);
        $this->assertNull($entry->posted_by);
        $this->assertNull($entry->posted_at);
        $this->assertSame($this->user->id, $entry->created_by);
    }

    public function test_create_from_source_rejects_unsaved_model_without_persisting_records(): void
    {
        $unsavedInvoice = new PurchaseInvoice;

        $this->expectException(ValidationException::class);

        try {
            app(JournalEntryService::class)->createFromSource(
                $this->validPayload(),
                $unsavedInvoice,
                $this->user,
            );
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0, JournalEntryLine::query()->count());
        }
    }

    public function test_create_from_source_rejects_model_without_valid_primary_key(): void
    {
        $invalidSource = new PurchaseInvoice;
        $invalidSource->exists = true;

        $this->expectException(ValidationException::class);

        try {
            app(JournalEntryService::class)->createFromSource(
                $this->validPayload(),
                $invalidSource,
                $this->user,
            );
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0, JournalEntryLine::query()->count());
        }
    }

    public function test_repeated_calls_with_same_source_return_same_entry_without_extra_lines_or_sequence_gap(): void
    {
        $invoice1 = PurchaseInvoice::factory()->create();
        $invoice2 = PurchaseInvoice::factory()->create();
        $service = app(JournalEntryService::class);

        $first = $service->createFromSource($this->validPayload(), $invoice1, $this->user);
        $second = $service->createFromSource($this->validPayload(), $invoice1, $this->user);
        $third = $service->createFromSource($this->validPayload(), $invoice2, $this->user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->entry_number, $second->entry_number);
        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame(4, JournalEntryLine::query()->count());
        $this->assertSame('JE-2026-00001', $first->entry_number);
        $this->assertSame('JE-2026-00002', $third->entry_number);
    }

    public function test_different_source_types_with_same_numeric_id_create_separate_entries(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $expense = Expense::factory()->create(['id' => $invoice->id]);

        $service = app(JournalEntryService::class);

        $invoiceEntry = $service->createFromSource($this->validPayload(), $invoice, $this->user);
        $expenseEntry = $service->createFromSource($this->validPayload(), $expense, $this->user);

        $this->assertNotSame($invoiceEntry->id, $expenseEntry->id);
        $this->assertSame($invoice->id, $invoiceEntry->source_id);
        $this->assertSame($expense->id, $expenseEntry->source_id);
        $this->assertNotSame($invoiceEntry->source_type, $expenseEntry->source_type);
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_different_records_of_same_source_type_create_separate_entries(): void
    {
        $invoice1 = PurchaseInvoice::factory()->create();
        $invoice2 = PurchaseInvoice::factory()->create();
        $service = app(JournalEntryService::class);

        $entry1 = $service->createFromSource($this->validPayload(), $invoice1, $this->user);
        $entry2 = $service->createFromSource($this->validPayload(), $invoice2, $this->user);

        $this->assertNotSame($entry1->id, $entry2->id);
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_direct_database_duplicate_source_insert_is_rejected_by_unique_constraint(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        app(JournalEntryService::class)->createFromSource($this->validPayload(), $invoice, $this->user);

        $this->expectException(QueryException::class);

        JournalEntry::query()->create([
            'journal_id' => $this->journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_number' => 'JE-2026-99999',
            'entry_date' => '2026-11-25',
            'source_type' => $invoice->getMorphClass(),
            'source_id' => $invoice->id,
            'status' => JournalEntryStatus::Posted->value,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_multiple_manual_entries_with_null_source_fields_can_coexist(): void
    {
        $service = app(JournalEntryService::class);

        $first = $service->create($this->validPayload(), $this->user);
        $second = $service->create($this->validPayload(), $this->user);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($first->source_type);
        $this->assertNull($first->source_id);
        $this->assertNull($second->source_type);
        $this->assertNull($second->source_id);
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_simulated_unique_constraint_collision_recovers_and_returns_existing_entry(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $existing = app(JournalEntryService::class)->createFromSource($this->validPayload(), $invoice, $this->user);

        $service = Mockery::mock(JournalEntryService::class, [app(DocumentSequenceGenerator::class)])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $service->shouldReceive('findBySource')
            ->once()
            ->with($invoice->getMorphClass(), $invoice->id)
            ->andReturn(null);

        $service->shouldReceive('findBySource')
            ->once()
            ->with($invoice->getMorphClass(), $invoice->id, true)
            ->andReturn($existing);

        $recovered = $service->createFromSource($this->validPayload(), $invoice, $this->user);

        $this->assertSame($existing->id, $recovered->id);
        $this->assertSame($existing->entry_number, $recovered->entry_number);
        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_unrelated_database_exception_is_not_swallowed_as_duplicate(): void
    {
        $invoice = PurchaseInvoice::factory()->create();

        $pdoException = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails');
        $pdoException->errorInfo = ['23000', 1452, 'Cannot add or update a child row: a foreign key constraint fails'];
        $queryException = new QueryException('mysql', 'insert into journal_entries ...', [], $pdoException);

        $sequenceMock = Mockery::mock(DocumentSequenceGenerator::class);
        $sequenceMock->shouldReceive('next')
            ->once()
            ->andThrow($queryException);

        $service = new JournalEntryService($sequenceMock);

        $this->expectException(QueryException::class);
        $service->createFromSource($this->validPayload(), $invoice, $this->user);
    }

    public function test_failed_persistence_rolls_back_and_subsequent_retry_succeeds(): void
    {
        $invoice = PurchaseInvoice::factory()->create();
        $service = app(JournalEntryService::class);

        $unbalancedPayload = $this->validPayload([
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'مدين',
                    'debit' => 500.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[1]->id,
                    'label' => 'دائن',
                    'debit' => 0,
                    'credit' => 400.00,
                ],
            ],
        ]);

        try {
            $service->createFromSource($unbalancedPayload, $invoice, $this->user);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0, JournalEntryLine::query()->count());
        }

        $retried = $service->createFromSource($this->validPayload(), $invoice, $this->user);

        $this->assertSame(JournalEntryStatus::Posted, $retried->status);
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(2, JournalEntryLine::query()->count());
    }
}
