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
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JournalEntrySourceDraftLifecycleTest extends TestCase
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
            'reference' => 'SRC-DRAFT-001',
            'description' => 'قيد مسودة من مصدر عام',
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
        ], $overrides);
    }

    public function test_create_draft_for_source_creates_linked_draft_entry_and_is_idempotent(): void
    {
        $source = Expense::factory()->create(['created_by' => $this->user->id]);
        $service = app(JournalEntryService::class);

        $first = $service->createDraftForSource($this->validPayload(), $source, $this->user);

        $this->assertSame(JournalEntryStatus::Draft, $first->status);
        $this->assertSame($source->getMorphClass(), $first->source_type);
        $this->assertSame($source->getKey(), $first->source_id);
        $this->assertNull($first->posted_at);
        $this->assertNull($first->posted_by);
        $this->assertCount(2, $first->lines);

        // Repeated call returns the existing linked draft without creating duplicates.
        $second = $service->createDraftForSource($this->validPayload(), $source, $this->user);

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->entry_number, $second->entry_number);
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->count()
        );
    }

    public function test_create_draft_for_source_rejects_unsaved_source(): void
    {
        $unsaved = new Expense;

        $this->expectException(ValidationException::class);

        try {
            app(JournalEntryService::class)->createDraftForSource($this->validPayload(), $unsaved, $this->user);
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
        }
    }

    public function test_update_draft_for_source_preserves_id_and_entry_number_and_replaces_lines(): void
    {
        $source = Expense::factory()->create(['created_by' => $this->user->id]);
        $service = app(JournalEntryService::class);

        $created = $service->createDraftForSource($this->validPayload(), $source, $this->user);

        $updated = $service->updateDraftForSource($source, $this->validPayload([
            'reference' => 'SRC-DRAFT-UPDATED',
            'description' => 'قيد محدث',
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'مدين محدث',
                    'debit' => 750.00,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[1]->id,
                    'label' => 'دائن محدث',
                    'debit' => 0,
                    'credit' => 750.00,
                ],
            ],
        ]));

        $this->assertSame($created->id, $updated->id);
        $this->assertSame($created->entry_number, $updated->entry_number);
        $this->assertSame('SRC-DRAFT-UPDATED', $updated->reference);
        $this->assertSame('750.00', $updated->lines[0]->debit);
        $this->assertSame('750.00', $updated->lines[1]->credit);
        $this->assertSame(2, JournalEntryLine::query()->where('journal_entry_id', $created->id)->count());
    }

    public function test_update_draft_for_source_rejects_missing_or_posted_entry(): void
    {
        $source = Expense::factory()->create(['created_by' => $this->user->id]);
        $service = app(JournalEntryService::class);

        try {
            $service->updateDraftForSource($source, $this->validPayload());
            $this->fail('Expected ValidationException when linked entry is missing.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_entry', $e->errors());
        }

        $service->createDraftForSource($this->validPayload(), $source, $this->user);
        $service->postForSource($source, $this->user);

        $this->expectException(ValidationException::class);
        $service->updateDraftForSource($source, $this->validPayload());
    }

    public function test_post_for_source_posts_linked_draft_entry_and_rejects_missing_or_already_posted(): void
    {
        $source = Expense::factory()->create(['created_by' => $this->user->id]);
        $service = app(JournalEntryService::class);

        try {
            $service->postForSource($source, $this->user);
            $this->fail('Expected ValidationException when linked entry is missing.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_entry', $e->errors());
        }

        $draft = $service->createDraftForSource($this->validPayload(), $source, $this->user);
        $posted = $service->postForSource($source, $this->user);

        $this->assertSame($draft->id, $posted->id);
        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertSame($this->user->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);

        $this->expectException(ValidationException::class);
        $service->postForSource($source, $this->user);
    }

    public function test_delete_draft_for_source_deletes_draft_and_is_noop_when_missing_and_rejects_posted(): void
    {
        $source = Expense::factory()->create(['created_by' => $this->user->id]);
        $service = app(JournalEntryService::class);

        // No-op when no linked entry exists.
        $service->deleteDraftForSource($source);

        $draft = $service->createDraftForSource($this->validPayload(), $source, $this->user);
        $service->deleteDraftForSource($source);

        $this->assertDatabaseMissing('journal_entries', ['id' => $draft->id]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $draft->id]);

        // Posted entry cannot be deleted.
        $service->createDraftForSource($this->validPayload(), $source, $this->user);
        $posted = $service->postForSource($source, $this->user);

        $this->expectException(ValidationException::class);

        try {
            $service->deleteDraftForSource($source);
        } finally {
            $this->assertDatabaseHas('journal_entries', [
                'id' => $posted->id,
                'status' => JournalEntryStatus::Posted->value,
            ]);
        }
    }
}
