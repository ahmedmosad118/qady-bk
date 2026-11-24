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
use App\Models\JournalEntryLine;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class JournalEntryCrudTest extends TestCase
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

        $this->accounts = Account::query()->where('is_active', true)->limit(3)->get()->all();
        $this->assertCount(3, $this->accounts);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'journal_id' => $this->journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2026-11-24',
            'reference' => 'REF-100',
            'description' => 'قيد يومية اختباري',
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'من حساب الصندوق',
                    'debit' => 250.50,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[1]->id,
                    'label' => 'إلى حساب المبيعات',
                    'debit' => 0,
                    'credit' => 250.50,
                ],
            ],
        ], $overrides);
    }

    public function test_can_create_valid_draft_journal_entry_with_totals(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', JournalEntryStatus::Draft->value)
            ->assertJsonPath('data.entry_number', 'JE-2026-00001')
            ->assertJsonPath('data.total_debit', '250.50')
            ->assertJsonPath('data.total_credit', '250.50')
            ->assertJsonPath('data.difference', '0.00')
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonPath('data.created_by.id', $this->user->id)
            ->assertJsonCount(2, 'data.lines');

        $entryId = $response->json('data.id');
        $this->assertDatabaseHas('journal_entries', [
            'id' => $entryId,
            'entry_number' => 'JE-2026-00001',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entryId,
            'line_number' => 1,
            'label' => 'من حساب الصندوق',
            'debit' => 250.50,
            'credit' => 0,
        ]);
    }

    public function test_cannot_create_journal_entry_directly_as_posted(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload([
                'status' => JournalEntryStatus::Posted->value,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_rejects_entry_with_fewer_than_two_lines(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload([
                'lines' => [
                    [
                        'account_id' => $this->accounts[0]->id,
                        'debit' => 100,
                        'credit' => 0,
                    ],
                ],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);
    }

    public function test_rejects_line_with_both_debit_and_credit_positive(): void
    {
        $payload = $this->validPayload();
        $payload['lines'][1]['debit'] = 100;
        $payload['lines'][1]['credit'] = 50;

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.debit', 'lines.1.credit']);
    }

    public function test_rejects_line_with_zero_in_both_debit_and_credit(): void
    {
        $payload = $this->validPayload();
        $payload['lines'][0]['debit'] = 0;
        $payload['lines'][0]['credit'] = 0;

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.debit', 'lines.0.credit']);
    }

    public function test_rejects_negative_debit_or_credit_values(): void
    {
        $payload = $this->validPayload();
        $payload['lines'][0]['debit'] = -100;

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0.debit']);
    }

    public function test_rejects_unbalanced_journal_entry_on_create_and_persists_nothing(): void
    {
        $debitGreaterPayload = $this->validPayload([
            'lines' => [
                ['account_id' => $this->accounts[0]->id, 'debit' => 300.00, 'credit' => 0],
                ['account_id' => $this->accounts[1]->id, 'debit' => 0, 'credit' => 250.00],
            ],
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $debitGreaterPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $creditGreaterPayload = $this->validPayload([
            'lines' => [
                ['account_id' => $this->accounts[0]->id, 'debit' => 150.00, 'credit' => 0],
                ['account_id' => $this->accounts[1]->id, 'debit' => 0, 'credit' => 200.00],
            ],
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $creditGreaterPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_entry_lines', 0);
    }

    public function test_rejects_unbalanced_journal_entry_on_update_and_preserves_existing_data(): void
    {
        $entry = app(JournalEntryService::class)->create($this->validPayload(), $this->user);

        $debitGreaterPayload = $this->validPayload([
            'reference' => 'SHOULD-NOT-SAVE',
            'lines' => [
                ['account_id' => $this->accounts[0]->id, 'label' => 'جديد', 'debit' => 400.00, 'credit' => 0],
                ['account_id' => $this->accounts[1]->id, 'label' => 'جديد 2', 'debit' => 0, 'credit' => 350.00],
            ],
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/journal-entries/{$entry->id}", $debitGreaterPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $creditGreaterPayload = $this->validPayload([
            'reference' => 'SHOULD-NOT-SAVE',
            'lines' => [
                ['account_id' => $this->accounts[0]->id, 'label' => 'جديد', 'debit' => 100.00, 'credit' => 0],
                ['account_id' => $this->accounts[1]->id, 'label' => 'جديد 2', 'debit' => 0, 'credit' => 120.00],
            ],
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/journal-entries/{$entry->id}", $creditGreaterPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines']);

        $this->assertDatabaseHas('journal_entries', [
            'id' => $entry->id,
            'reference' => 'REF-100',
        ]);
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'label' => 'من حساب الصندوق',
            'debit' => 250.50,
        ]);
        $this->assertDatabaseCount('journal_entry_lines', 2);
    }

    public function test_rejects_inactive_account(): void
    {
        $this->accounts[1]->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.1.account_id']);
    }

    public function test_rejects_inactive_journal(): void
    {
        $this->journal->update(['is_active' => false]);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['journal_id']);
    }

    public function test_rejects_entry_date_outside_fiscal_year_or_accounting_period(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload([
                'entry_date' => '2026-10-15',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry_date']);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload([
                'entry_date' => '2025-11-15',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry_date']);
    }

    public function test_rejects_closed_accounting_period_and_closed_fiscal_year(): void
    {
        $this->period->update(['status' => AccountingPeriodStatus::Closed]);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period_id']);

        $this->period->update(['status' => AccountingPeriodStatus::Open]);
        $this->fiscalYear->update(['status' => FiscalYearStatus::Closed]);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journal-entries', $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id']);
    }

    public function test_can_show_journal_entry_with_relations_and_totals(): void
    {
        $created = app(JournalEntryService::class)->create($this->validPayload(), $this->user);

        $this->actingAs($this->user)
            ->getJson("/api/accounting/journal-entries/{$created->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $created->id)
            ->assertJsonPath('data.journal.id', $this->journal->id)
            ->assertJsonPath('data.fiscal_year.id', $this->fiscalYear->id)
            ->assertJsonPath('data.accounting_period.id', $this->period->id)
            ->assertJsonPath('data.created_by.id', $this->user->id)
            ->assertJsonPath('data.lines.0.account.id', $this->accounts[0]->id)
            ->assertJsonPath('data.total_debit', '250.50')
            ->assertJsonPath('data.total_credit', '250.50')
            ->assertJsonPath('data.difference', '0.00');
    }

    public function test_can_update_draft_journal_entry_and_replace_lines(): void
    {
        $entry = app(JournalEntryService::class)->create($this->validPayload(), $this->user);

        $updatePayload = $this->validPayload([
            'reference' => 'REF-UPDATED',
            'description' => 'قيد معدل',
            'lines' => [
                [
                    'account_id' => $this->accounts[0]->id,
                    'label' => 'سطر معدل 1',
                    'debit' => 500,
                    'credit' => 0,
                ],
                [
                    'account_id' => $this->accounts[2]->id,
                    'label' => 'سطر معدل 2',
                    'debit' => 0,
                    'credit' => 500,
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/journal-entries/{$entry->id}", $updatePayload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.reference', 'REF-UPDATED')
            ->assertJsonPath('data.total_debit', '500.00')
            ->assertJsonPath('data.total_credit', '500.00')
            ->assertJsonPath('data.difference', '0.00')
            ->assertJsonPath('data.is_balanced', true)
            ->assertJsonCount(2, 'data.lines');

        $this->assertDatabaseCount('journal_entry_lines', 2);
        $this->assertDatabaseMissing('journal_entry_lines', [
            'journal_entry_id' => $entry->id,
            'label' => 'من حساب الصندوق',
        ]);
    }

    public function test_can_delete_draft_journal_entry_and_its_lines(): void
    {
        $entry = app(JournalEntryService::class)->create($this->validPayload(), $this->user);

        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/journal-entries/{$entry->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('journal_entry_lines', ['journal_entry_id' => $entry->id]);
    }

    public function test_cannot_update_or_delete_posted_journal_entry(): void
    {
        $entry = app(JournalEntryService::class)->create($this->validPayload(), $this->user);
        $entry->update([
            'status' => JournalEntryStatus::Posted,
            'posted_by' => $this->user->id,
            'posted_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/journal-entries/{$entry->id}", $this->validPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/journal-entries/{$entry->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'status' => 'posted']);
        $this->assertDatabaseCount('journal_entry_lines', 2);
    }

    public function test_index_supports_backend_pagination_and_combined_filters(): void
    {
        $service = app(JournalEntryService::class);
        $e1 = $service->create($this->validPayload([
            'entry_date' => '2026-11-10',
            'reference' => 'INV-111',
            'description' => 'مبيعات نقدية',
        ]), $this->user);
        $e2 = $service->create($this->validPayload([
            'entry_date' => '2026-11-15',
            'reference' => 'INV-222',
            'description' => 'مشتريات كتب',
            'lines' => [
                ['account_id' => $this->accounts[0]->id, 'label' => 'بند خاص بالبحث', 'debit' => 100, 'credit' => 0],
                ['account_id' => $this->accounts[1]->id, 'label' => 'دائن', 'debit' => 0, 'credit' => 100],
            ],
        ]), $this->user);
        $e2->update(['status' => JournalEntryStatus::Posted]);
        $service->create($this->validPayload([
            'entry_date' => '2026-11-20',
            'reference' => 'INV-333',
            'description' => 'تسوية',
        ]), $this->user);

        // Pagination check
        $this->actingAs($this->user)
            ->getJson('/api/accounting/journal-entries?per_page=2&page=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 2)
            ->assertJsonStructure(['meta' => ['links']]);

        // Combined filters check (status + label + entry_date + journal_id + fiscal_year_id + accounting_period_id)
        $query = http_build_query([
            'status' => 'posted',
            'label' => 'خاص بالبحث',
            'reference' => 'INV-222',
            'entry_number' => $e2->entry_number,
            'entry_date' => '2026-11-15',
            'journal_id' => $this->journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/accounting/journal-entries?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $e2->id);

        // Safe sorting: invalid sort_by must not error and falls back cleanly
        $this->actingAs($this->user)
            ->getJson('/api/accounting/journal-entries?sort_by=non_existent_column;DROP_TABLE&sort_order=asc')
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $this->assertNotNull($e1);
    }

    public function test_rolls_back_transaction_if_line_insertion_fails(): void
    {
        JournalEntryLine::creating(static function (): void {
            throw new RuntimeException('Simulated line failure');
        });

        // Since JournalEntryService uses bulk insert(), simulate failure via DB::listen on insert into journal_entry_lines
        Event::listen('Illuminate\Database\Events\QueryExecuted', static function ($event): void {
            if (str_contains($event->sql, 'insert into "journal_entry_lines"')) {
                throw new RuntimeException('Simulated line insert failure');
            }
        });

        $this->expectException(RuntimeException::class);

        try {
            app(JournalEntryService::class)->create($this->validPayload(), $this->user);
        } finally {
            $this->assertDatabaseCount('journal_entries', 0);
            $this->assertDatabaseCount('journal_entry_lines', 0);
        }
    }
}
