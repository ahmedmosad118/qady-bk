<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalType;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalsFiscalYearsPeriodsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccountingSeeder::class);
        $this->user = User::factory()->create();
    }

    public function test_journals_index_create_show_update_and_delete(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/accounting/journals?type=Sales')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'SAL');

        $createRes = $this->actingAs($this->user)
            ->postJson('/api/accounting/journals', [
                'code' => 'BNK2',
                'name' => 'دفتر البنك الأهلي',
                'type' => JournalType::Bank->value,
                'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'BNK2')
            ->assertJsonPath('data.type', 'Bank');

        $journalId = (int) $createRes->json('data.id');

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journals', [
                'code' => 'BNK2',
                'name' => 'مكرر',
                'type' => JournalType::Bank->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->actingAs($this->user)
            ->postJson('/api/accounting/journals', [
                'code' => 'BAD',
                'name' => 'نوع غير صالح',
                'type' => 'sales',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/journals/{$journalId}", [
                'name' => 'دفتر البنك المحدث',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'دفتر البنك المحدث')
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/journals/{$journalId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertNull(Journal::query()->find($journalId));
    }

    public function test_fiscal_years_crud_overlap_closing_and_delete_rules(): void
    {
        $fy2026Res = $this->actingAs($this->user)
            ->postJson('/api/accounting/fiscal-years', [
                'code' => 'FY2026',
                'name' => 'السنة المالية 2026',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => FiscalYearStatus::Open->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.code', 'FY2026');

        $fy2026Id = (int) $fy2026Res->json('data.id');

        // Cannot close a fiscal year with no periods
        $this->actingAs($this->user)
            ->putJson("/api/accounting/fiscal-years/{$fy2026Id}", [
                'status' => FiscalYearStatus::Closed->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Invalid date range
        $this->actingAs($this->user)
            ->postJson('/api/accounting/fiscal-years', [
                'code' => 'FYBAD',
                'name' => 'سنة غير صالحة',
                'start_date' => '2027-12-31',
                'end_date' => '2027-01-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);

        // Overlapping fiscal year rejected on create
        $this->actingAs($this->user)
            ->postJson('/api/accounting/fiscal-years', [
                'code' => 'FY2026-OV',
                'name' => 'سنة متداخلة',
                'start_date' => '2026-06-01',
                'end_date' => '2027-05-31',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        // Create FY2027 and test overlap during update
        $fy2027Res = $this->actingAs($this->user)
            ->postJson('/api/accounting/fiscal-years', [
                'code' => 'FY2027',
                'name' => '2027',
                'start_date' => '2027-01-01',
                'end_date' => '2027-12-31',
            ])
            ->assertCreated();
        $fy2027Id = (int) $fy2027Res->json('data.id');

        $this->actingAs($this->user)
            ->putJson("/api/accounting/fiscal-years/{$fy2027Id}", [
                'start_date' => '2026-12-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        // Unused fiscal year can be deleted
        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/fiscal-years/{$fy2027Id}")
            ->assertOk();

        // Add an open period to FY2026
        $p1Res = $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy2026Id,
                'name' => 'الربع الأول',
                'period_number' => 1,
                'start_date' => '2026-01-01',
                'end_date' => '2026-03-31',
            ])
            ->assertCreated();

        $p1Id = (int) $p1Res->json('data.id');

        // Cannot close fiscal year while period is open
        $this->actingAs($this->user)
            ->putJson("/api/accounting/fiscal-years/{$fy2026Id}", [
                'status' => FiscalYearStatus::Closed->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Simulate period closed by dedicated future closing process
        AccountingPeriod::query()->whereKey($p1Id)->update([
            'status' => AccountingPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);

        // Now fiscal year can be closed
        $this->actingAs($this->user)
            ->putJson("/api/accounting/fiscal-years/{$fy2026Id}", [
                'status' => FiscalYearStatus::Closed->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        // Cannot modify a closed fiscal year
        $this->actingAs($this->user)
            ->putJson("/api/accounting/fiscal-years/{$fy2026Id}", [
                'name' => 'محاولة تعديل',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Cannot add a period to a closed fiscal year
        $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy2026Id,
                'name' => 'الربع الثاني',
                'period_number' => 2,
                'start_date' => '2026-04-01',
                'end_date' => '2026-06-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id']);
    }

    public function test_accounting_periods_validation_closing_separation_and_delete_rules(): void
    {
        $fy = FiscalYear::query()->create([
            'code' => 'FY2028',
            'name' => '2028',
            'start_date' => '2028-01-01',
            'end_date' => '2028-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        // Generic CRUD must reject creating a closed period
        $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy->id,
                'name' => 'فترة مغلقة',
                'period_number' => 1,
                'start_date' => '2028-01-01',
                'end_date' => '2028-03-31',
                'status' => AccountingPeriodStatus::Closed->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Outside fiscal year bounds rejected
        $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy->id,
                'name' => 'فترة خارج النطاق',
                'period_number' => 1,
                'start_date' => '2027-12-15',
                'end_date' => '2028-01-15',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        // Valid Q1
        $q1Res = $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy->id,
                'name' => 'الربع الأول',
                'period_number' => 1,
                'start_date' => '2028-01-01',
                'end_date' => '2028-03-31',
            ])
            ->assertCreated();
        $q1Id = (int) $q1Res->json('data.id');

        // Partial update with start_date > existing end_date rejected
        $this->actingAs($this->user)
            ->putJson("/api/accounting/accounting-periods/{$q1Id}", [
                'start_date' => '2028-05-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date']);

        // Generic CRUD must reject closing an existing period
        $this->actingAs($this->user)
            ->putJson("/api/accounting/accounting-periods/{$q1Id}", [
                'status' => AccountingPeriodStatus::Closed->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Duplicate period_number in same fiscal year rejected
        $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy->id,
                'name' => 'فترة مكررة الرقم',
                'period_number' => 1,
                'start_date' => '2028-04-01',
                'end_date' => '2028-06-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['period_number']);

        // Overlapping dates in same fiscal year rejected
        $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy->id,
                'name' => 'فترة متداخلة',
                'period_number' => 2,
                'start_date' => '2028-03-15',
                'end_date' => '2028-06-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        // Closed period cannot be edited, reopened, or deleted
        AccountingPeriod::query()->whereKey($q1Id)->update([
            'status' => AccountingPeriodStatus::Closed->value,
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/accounting-periods/{$q1Id}", [
                'name' => 'تعديل فترة مغلقة',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->user)
            ->putJson("/api/accounting/accounting-periods/{$q1Id}", [
                'status' => AccountingPeriodStatus::Open->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/accounting-periods/{$q1Id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period']);

        // Create Q2 (open) and verify deletion & fiscal year deletion protection
        $q2Res = $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy->id,
                'name' => 'الربع الثاني',
                'period_number' => 2,
                'start_date' => '2028-04-01',
                'end_date' => '2028-06-30',
            ])
            ->assertCreated();
        $q2Id = (int) $q2Res->json('data.id');

        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/fiscal-years/{$fy->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year']);

        $this->actingAs($this->user)
            ->deleteJson("/api/accounting/accounting-periods/{$q2Id}")
            ->assertOk();

        $this->assertNull(AccountingPeriod::query()->find($q2Id));
    }

    public function test_fiscal_year_show_returns_periods_and_period_move_between_years_is_validated(): void
    {
        $fy1 = FiscalYear::query()->create([
            'code' => 'FY2029',
            'name' => '2029',
            'start_date' => '2029-01-01',
            'end_date' => '2029-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $fy2 = FiscalYear::query()->create([
            'code' => 'FY2030',
            'name' => '2030',
            'start_date' => '2030-01-01',
            'end_date' => '2030-12-31',
            'status' => FiscalYearStatus::Open,
        ]);

        $periodRes = $this->actingAs($this->user)
            ->postJson('/api/accounting/accounting-periods', [
                'fiscal_year_id' => $fy1->id,
                'name' => 'فترة 1',
                'period_number' => 1,
                'start_date' => '2029-01-01',
                'end_date' => '2029-06-30',
                'closed_at' => '2029-06-30 00:00:00', // Must be ignored
                'closed_by' => $this->user->id, // Must be ignored
            ])
            ->assertCreated();

        $periodId = (int) $periodRes->json('data.id');
        $createdPeriod = AccountingPeriod::query()->findOrFail($periodId);
        $this->assertNull($createdPeriod->closed_at);
        $this->assertNull($createdPeriod->closed_by);

        $this->actingAs($this->user)
            ->getJson("/api/accounting/fiscal-years/{$fy1->id}")
            ->assertOk()
            ->assertJsonPath('data.accounting_periods_count', 1)
            ->assertJsonCount(1, 'data.accounting_periods');

        // Moving period to fy2 without updating dates to 2030 fails bounds validation
        $this->actingAs($this->user)
            ->putJson("/api/accounting/accounting-periods/{$periodId}", [
                'fiscal_year_id' => $fy2->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['start_date']);

        // Moving period to fy2 with valid 2030 dates succeeds
        $this->actingAs($this->user)
            ->putJson("/api/accounting/accounting-periods/{$periodId}", [
                'fiscal_year_id' => $fy2->id,
                'start_date' => '2030-01-01',
                'end_date' => '2030-06-30',
            ])
            ->assertOk()
            ->assertJsonPath('data.fiscal_year_id', $fy2->id);
    }
}
