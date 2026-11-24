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
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private User $poster;

    private Journal $journal;

    private FiscalYear $fiscalYear;

    private AccountingPeriod $period;

    /** @var array<int, Account> */
    private array $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountingSeeder::class);

        $this->creator = User::factory()->create();
        $this->poster = User::factory()->create();
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

    private function createDraftEntry(): JournalEntry
    {
        return app(JournalEntryService::class)->create([
            'journal_id' => $this->journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2026-11-24',
            'reference' => 'POST-REF-1',
            'description' => 'قيد جاهز للترحيل',
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'مدين',
                    'debit' => 300.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[1]->id,
                    'label' => 'دائن',
                    'debit' => 0,
                    'credit' => 300.00,
                ],
            ],
        ], $this->creator);
    }

    public function test_can_post_valid_balanced_draft_entry_and_records_audit_fields(): void
    {
        $entry = $this->createDraftEntry();

        $response = $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.status', JournalEntryStatus::Posted->value)
            ->assertJsonPath('data.posted_by.id', $this->poster->id)
            ->assertJsonPath('data.total_debit', '300.00')
            ->assertJsonPath('data.total_credit', '300.00')
            ->assertJsonCount(2, 'data.lines');

        $this->assertNotNull($response->json('data.posted_at'));

        $entry->refresh();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->poster->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
    }

    public function test_rejects_posting_already_posted_entry_and_repeated_attempts(): void
    {
        $entry = $this->createDraftEntry();

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertOk();

        $firstPostedAt = $entry->fresh()->posted_at;

        // Second attempt (e.g. concurrent/repeated request by another user) must fail cleanly
        $this->actingAs($this->creator)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $fresh = $entry->fresh();
        $this->assertSame(JournalEntryStatus::Posted, $fresh->status);
        $this->assertSame($this->poster->id, $fresh->posted_by);
        $this->assertEquals($firstPostedAt, $fresh->posted_at);
    }

    public function test_rejects_posting_when_fiscal_year_is_not_open(): void
    {
        $entry = $this->createDraftEntry();

        $this->fiscalYear->update(['status' => FiscalYearStatus::Draft]);
        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id']);

        $this->fiscalYear->update(['status' => FiscalYearStatus::Closed]);
        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id']);

        $this->assertStillDraft($entry);
    }

    public function test_rejects_posting_when_accounting_period_is_not_open(): void
    {
        $entry = $this->createDraftEntry();

        $this->period->update(['status' => AccountingPeriodStatus::Closed]);

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period_id']);

        $this->assertStillDraft($entry);
    }

    public function test_rejects_posting_when_journal_is_inactive(): void
    {
        $entry = $this->createDraftEntry();

        $this->journal->update(['is_active' => false]);

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_id']);

        $this->assertStillDraft($entry);
    }

    public function test_rejects_posting_when_an_account_is_inactive(): void
    {
        $entry = $this->createDraftEntry();

        $this->accounts[1]->update(['is_active' => false]);

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.account_id']);

        $this->assertStillDraft($entry);
    }

    public function test_rejects_posting_when_entry_is_unbalanced(): void
    {
        $entry = $this->createDraftEntry();

        // Simulate legacy/corrupted unbalanced line in DB before posting
        JournalEntryLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('line_number', 2)
            ->update(['credit' => '250.00']);

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->assertStillDraft($entry);
    }

    public function test_rejects_posting_when_entry_lines_are_invalid(): void
    {
        $entry = $this->createDraftEntry();

        // Case 1: fewer than 2 lines
        JournalEntryLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('line_number', 2)
            ->delete();

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->assertStillDraft($entry);

        // Case 2: line with both debit and credit zero
        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $this->accounts[1]->id,
            'line_number' => 2,
            'debit' => '0.00',
            'credit' => '0.00',
        ]);

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.debit', 'lines.1.credit']);

        $this->assertStillDraft($entry);
    }

    public function test_posted_entry_cannot_be_updated_or_deleted_via_crud_endpoints(): void
    {
        $entry = $this->createDraftEntry();

        $this->actingAs($this->poster)
            ->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertOk();

        $this->actingAs($this->creator)
            ->putJson("/api/accounting/journal-entries/{$entry->id}", [
                'journal_id' => $this->journal->id,
                'fiscal_year_id' => $this->fiscalYear->id,
                'accounting_period_id' => $this->period->id,
                'entry_date' => '2026-11-24',
                'reference' => 'TAMPERED',
                'lines' => [
                    ['account_id' => $this->accounts[0]->id, 'debit' => 100, 'credit' => 0],
                    ['account_id' => $this->accounts[1]->id, 'debit' => 0, 'credit' => 100],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->creator)
            ->deleteJson("/api/accounting/journal-entries/{$entry->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'status' => JournalEntryStatus::Posted->value,
            'reference' => 'POST-REF-1',
        ]);
        $this->assertDatabaseCount('journal_entry_lines', 2);
    }

    private function assertStillDraft(JournalEntry $entry): void
    {
        $fresh = $entry->fresh();
        $this->assertSame(JournalEntryStatus::Draft, $fresh->status);
        $this->assertNull($fresh->posted_by);
        $this->assertNull($fresh->posted_at);
    }
}
