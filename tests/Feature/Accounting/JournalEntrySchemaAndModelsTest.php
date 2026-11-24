<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Activity;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class JournalEntrySchemaAndModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountingSeeder::class);
    }

    public function test_journal_entries_and_lines_tables_have_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('journal_entries'));
        $this->assertTrue(Schema::hasColumns('journal_entries', [
            'id',
            'journal_id',
            'fiscal_year_id',
            'accounting_period_id',
            'entry_number',
            'entry_date',
            'reference',
            'description',
            'source_type',
            'source_id',
            'status',
            'created_by',
            'posted_by',
            'posted_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('journal_entry_lines'));
        $this->assertTrue(Schema::hasColumns('journal_entry_lines', [
            'id',
            'journal_entry_id',
            'account_id',
            'line_number',
            'label',
            'debit',
            'credit',
            'created_at',
            'updated_at',
        ]));

        $entryIndexes = collect(Schema::getIndexes('journal_entries'))->pluck('name')->all();
        foreach ([
            'journal_entries_entry_date_index',
            'journal_entries_source_index',
            'journal_entries_status_index',
            'journal_entries_journal_id_index',
            'journal_entries_fiscal_year_id_index',
            'journal_entries_accounting_period_id_index',
        ] as $indexName) {
            $this->assertContains($indexName, $entryIndexes);
        }

        $lineIndexes = collect(Schema::getIndexes('journal_entry_lines'))->pluck('name')->all();
        foreach ([
            'journal_entry_lines_journal_entry_id_index',
            'journal_entry_lines_account_id_index',
        ] as $indexName) {
            $this->assertContains($indexName, $lineIndexes);
        }
    }

    public function test_journal_entry_and_line_relationships_and_defaults_work(): void
    {
        $creator = User::factory()->create();
        $poster = User::factory()->create();

        $journal = Journal::query()->firstOrFail();
        $fiscalYear = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => 'السنة المالية 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);
        $period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'نوفمبر 2026',
            'period_number' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open,
        ]);

        $entry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-2026-0001',
            'entry_date' => '2026-11-24',
            'reference' => 'REF-001',
            'description' => 'قيد اختباري',
            'source_type' => $journal->getMorphClass(),
            'source_id' => $journal->id,
            'created_by' => $creator->id,
            'posted_by' => $poster->id,
            'posted_at' => now(),
        ]);

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertTrue($entry->isDraft());
        $this->assertFalse($entry->isPosted());

        $accounts = Account::query()->limit(2)->get();
        $this->assertCount(2, $accounts);

        $line1 = JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $accounts[0]->id,
            'line_number' => 1,
            'label' => 'مدين',
            'debit' => '150.50',
        ]);

        $line2 = JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $accounts[1]->id,
            'line_number' => 2,
            'label' => 'دائن',
            'credit' => '150.50',
        ]);

        $this->assertSame('0.00', $line1->credit);
        $this->assertSame('0.00', $line2->debit);

        $entry->refresh();
        $this->assertTrue($entry->journal->is($journal));
        $this->assertTrue($entry->fiscalYear->is($fiscalYear));
        $this->assertTrue($entry->accountingPeriod->is($period));
        $this->assertTrue($entry->createdBy->is($creator));
        $this->assertTrue($entry->postedBy->is($poster));
        $this->assertTrue($entry->source->is($journal));
        $this->assertCount(2, $entry->lines);

        $this->assertTrue($line1->journalEntry->is($entry));
        $this->assertTrue($line1->account->is($accounts[0]));

        // Multiple journal entries may reference the same polymorphic source (no unique constraint).
        $secondEntry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-2026-0002',
            'entry_date' => '2026-11-24',
            'source_type' => $journal->getMorphClass(),
            'source_id' => $journal->id,
            'status' => JournalEntryStatus::Posted,
        ]);

        $this->assertTrue($secondEntry->isPosted());

        $activity = Activity::query()->where('log_name', 'journal_entry')->where('subject_id', $entry->id)->first();
        $this->assertNotNull($activity);
        $this->assertSame('book-open-check', $activity->icon);
        $this->assertSame('/accounting/journal-entries?showId='.$entry->id, $activity->link);
        $this->assertSame('JE-2026-0001', $activity->subject_name);
    }

    public function test_foreign_keys_enforce_restrict_on_delete(): void
    {
        $journal = Journal::query()->firstOrFail();
        $fiscalYear = FiscalYear::query()->create([
            'code' => 'FY-TST',
            'name' => 'سنة اختبار',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open,
        ]);
        $period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'فترة 1',
            'period_number' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'status' => AccountingPeriodStatus::Open,
        ]);
        $account = Account::query()->firstOrFail();

        $entry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'entry_number' => 'JE-RESTRICT-1',
            'entry_date' => '2026-01-15',
        ]);

        JournalEntryLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'line_number' => 1,
            'debit' => '100.00',
            'credit' => '0.00',
        ]);

        $this->expectException(QueryException::class);
        $entry->delete();
    }
}
