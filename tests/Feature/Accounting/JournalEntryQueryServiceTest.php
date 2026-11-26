<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentRelatedType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\PaymentType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Contact;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Services\Accounting\JournalEntryQueryService;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fy;

    private AccountingPeriod $period;

    private Journal $journal;

    private Account $expenseAccount;

    private Account $payableAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountingSeeder::class);
        $this->user = $this->createSuperAdmin();

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

        $this->journal = Journal::query()->where('code', 'MSC')->firstOrFail();

        $this->expenseAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Expenses->value))
            ->firstOrFail();

        $this->payableAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::Payable->value))
            ->firstOrFail();
    }

    public function test_paginate_supports_pages_clamping_filters_sorting_and_totals(): void
    {
        Sanctum::actingAs($this->user);

        foreach ([
            ['num' => 'JE-2026-00001', 'date' => '2026-11-05', 'ref' => 'REF-A', 'amount' => '100.00', 'status' => JournalEntryStatus::Draft],
            ['num' => 'JE-2026-00002', 'date' => '2026-11-10', 'ref' => 'REF-B', 'amount' => '200.00', 'status' => JournalEntryStatus::Posted],
            ['num' => 'JE-2026-00003', 'date' => '2026-11-15', 'ref' => 'REF-C', 'amount' => '300.00', 'status' => JournalEntryStatus::Draft],
        ] as $row) {
            $je = JournalEntry::query()->create([
                'journal_id' => $this->journal->id,
                'fiscal_year_id' => $this->fy->id,
                'accounting_period_id' => $this->period->id,
                'entry_number' => $row['num'],
                'entry_date' => $row['date'],
                'reference' => $row['ref'],
                'status' => $row['status']->value,
                'created_by' => $this->user->id,
            ]);
            $je->lines()->createMany([
                ['account_id' => $this->expenseAccount->id, 'line_number' => 1, 'label' => "Debit {$row['ref']}", 'debit' => $row['amount'], 'credit' => '0.00'],
                ['account_id' => $this->payableAccount->id, 'line_number' => 2, 'label' => "Credit {$row['ref']}", 'debit' => '0.00', 'credit' => $row['amount']],
            ]);
        }

        /** @var JournalEntryQueryService $queryService */
        $queryService = app(JournalEntryQueryService::class);
        /** @var JournalEntryService $facade */
        $facade = app(JournalEntryService::class);

        // Default ordering: entry_date desc -> 00003, 00002, 00001
        $page1 = $queryService->paginate([], 2);
        $this->assertSame(3, $page1->total());
        $this->assertSame(2, $page1->lastPage());
        $this->assertSame(['JE-2026-00003', 'JE-2026-00002'], $page1->getCollection()->pluck('entry_number')->all());
        $this->assertSame('300.00', $page1->getCollection()->first()->getAttribute('total_debit'));
        $this->assertSame('300.00', $page1->getCollection()->first()->getAttribute('total_credit'));
        $this->assertSame('0.00', $page1->getCollection()->first()->getAttribute('difference'));
        $this->assertTrue($page1->getCollection()->first()->getAttribute('is_balanced'));

        // Clamping per_page between 1 and 100
        $this->assertSame(1, $queryService->paginate([], 0)->perPage());
        $this->assertSame(100, $queryService->paginate([], 500)->perPage());

        // Search & filters + delegation parity
        $filtered = $queryService->paginate(['search' => 'REF-B', 'status' => 'posted'], 15);
        $delegated = $facade->paginate(['search' => 'REF-B', 'status' => 'posted'], 15);
        $this->assertSame(1, $filtered->total());
        $this->assertSame($filtered->getCollection()->pluck('id')->all(), $delegated->getCollection()->pluck('id')->all());

        // Ascending sort
        $asc = $queryService->paginate(['sort_by' => 'entry_date', 'sort_order' => 'asc'], 15);
        $this->assertSame(['JE-2026-00001', 'JE-2026-00002', 'JE-2026-00003'], $asc->getCollection()->pluck('entry_number')->all());

        // Page 2 via HTTP endpoint
        $this->getJson('/api/accounting/journal-entries?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.entry_number', 'JE-2026-00001')
            ->assertJsonPath('meta.total', 3);
    }

    public function test_get_details_loads_payment_and_purchase_invoice_sources_and_totals(): void
    {
        /** @var JournalEntryQueryService $queryService */
        $queryService = app(JournalEntryQueryService::class);
        /** @var JournalEntryService $facade */
        $facade = app(JournalEntryService::class);

        $supplier = Contact::factory()->create(['is_supplier' => true, 'is_active' => true]);
        $purJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();
        $cashJournal = Journal::query()->where('type', JournalType::Cash->value)->firstOrFail();
        $bankCashAccount = Account::query()
            ->where('is_active', true)
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::BankCash->value))
            ->firstOrFail();
        $paymentMethod = PaymentMethod::query()->create([
            'name' => 'Cash',
            'account_id' => $bankCashAccount->id,
            'is_active' => true,
        ]);

        // PurchaseInvoice source
        $invoice = PurchaseInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'journal_id' => $purJournal->id,
            'created_by' => $this->user->id,
        ]);
        $invEntry = $facade->createDraftForSource([
            'journal_id' => $purJournal->id,
            'fiscal_year_id' => $this->fy->id,
            'accounting_period_id' => $this->period->id,
            'contact_id' => $supplier->id,
            'entry_date' => '2026-11-15',
            'lines' => [
                ['account_id' => $this->expenseAccount->id, 'debit' => '150.00', 'credit' => '0.00'],
                ['account_id' => $this->payableAccount->id, 'debit' => '0.00', 'credit' => '150.00'],
            ],
        ], $invoice, $this->user);

        $invDetails = $queryService->getDetails($invEntry->fresh());
        $this->assertTrue($invDetails->relationLoaded('lines'));
        $this->assertTrue($invDetails->source->relationLoaded('supplier'));
        $this->assertTrue($invDetails->source->relationLoaded('journal'));
        $this->assertSame('150.00', $invDetails->getAttribute('total_debit'));
        $this->assertSame('150.00', $invDetails->getAttribute('total_credit'));
        $this->assertSame('0.00', $invDetails->getAttribute('difference'));
        $this->assertTrue($invDetails->getAttribute('is_balanced'));

        // Payment source
        $payment = Payment::query()->create([
            'payment_number' => 'PAY-20261115-0001',
            'payment_type' => PaymentType::SEND,
            'related_type' => PaymentRelatedType::SUPPLIER,
            'contact_id' => $supplier->id,
            'payment_method_id' => $paymentMethod->id,
            'journal_id' => $cashJournal->id,
            'account_id' => $bankCashAccount->id,
            'payment_date' => '2026-11-15',
            'amount' => 150.00,
            'status' => PaymentStatus::DRAFT,
            'created_by' => $this->user->id,
        ]);
        $payEntry = $facade->createDraftForSource([
            'journal_id' => $cashJournal->id,
            'fiscal_year_id' => $this->fy->id,
            'accounting_period_id' => $this->period->id,
            'contact_id' => $supplier->id,
            'entry_date' => '2026-11-15',
            'lines' => [
                ['account_id' => $this->payableAccount->id, 'debit' => '150.00', 'credit' => '0.00'],
                ['account_id' => $bankCashAccount->id, 'debit' => '0.00', 'credit' => '150.00'],
            ],
        ], $payment, $this->user);

        $payDetails = $facade->getDetails($payEntry->fresh());
        $this->assertTrue($payDetails->source->relationLoaded('contact'));
        $this->assertTrue($payDetails->source->relationLoaded('paymentMethod'));
        $this->assertTrue($payDetails->source->relationLoaded('journal'));
        $this->assertTrue($payDetails->source->relationLoaded('allocations'));
    }
}
