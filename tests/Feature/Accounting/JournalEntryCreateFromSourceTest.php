<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
}
