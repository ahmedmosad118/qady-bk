<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\FiscalYearStatus;
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
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PurchaseInvoice;
use App\Models\Stocktake;
use App\Models\User;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentSchemaAndModelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([AccountingSeeder::class, PaymentMethodSeeder::class]);
    }

    public function test_payments_and_payment_allocations_tables_have_expected_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('payments'));
        $this->assertTrue(Schema::hasColumns('payments', [
            'id',
            'payment_number',
            'payment_type',
            'related_type',
            'contact_id',
            'account_id',
            'payment_method_id',
            'journal_id',
            'payment_date',
            'amount',
            'reference',
            'description',
            'status',
            'created_by',
            'posted_by',
            'posted_at',
            'cancelled_by',
            'cancelled_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertFalse(Schema::hasColumn('payments', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('payments', 'direction'));
        $this->assertFalse(Schema::hasColumn('payments', 'journal_entry_id'));
        $this->assertFalse(Schema::hasColumn('payments', 'paid_amount'));
        $this->assertFalse(Schema::hasColumn('payments', 'remaining_amount'));

        $paymentIndexes = collect(Schema::getIndexes('payments'))->pluck('name')->all();
        foreach ([
            'payments_payment_type_index',
            'payments_related_type_index',
            'payments_status_index',
            'payments_payment_date_index',
            'payments_contact_id_index',
            'payments_account_id_index',
            'payments_payment_method_id_index',
            'payments_journal_id_index',
        ] as $indexName) {
            $this->assertContains($indexName, $paymentIndexes);
        }

        $this->assertTrue(Schema::hasTable('payment_allocations'));
        $this->assertTrue(Schema::hasColumns('payment_allocations', [
            'id',
            'payment_id',
            'allocatable_type',
            'allocatable_id',
            'allocated_amount',
            'created_at',
            'updated_at',
        ]));

        $allocationIndexes = collect(Schema::getIndexes('payment_allocations'))->pluck('name')->all();
        foreach ([
            'payment_allocations_payment_target_unique',
            'payment_allocations_allocatable_index',
        ] as $indexName) {
            $this->assertContains($indexName, $allocationIndexes);
        }
    }

    public function test_enums_and_morph_map_and_no_soft_deletes_match_approved_design(): void
    {
        $this->assertSame(['send', 'receive', 'transfer'], PaymentType::values());
        $this->assertSame(['customer', 'supplier'], PaymentRelatedType::values());
        $this->assertSame(['draft', 'posted', 'cancelled'], PaymentStatus::values());

        $this->assertNotContains(SoftDeletes::class, class_uses_recursive(Payment::class));

        $this->assertSame(Payment::class, Relation::getMorphedModel('payment'));
        $this->assertSame(Stocktake::class, Relation::getMorphedModel('stocktake'));
        $this->assertSame(PosSale::class, Relation::getMorphedModel('pos_sale'));
        $this->assertSame(PosReturn::class, Relation::getMorphedModel('pos_return'));
        $this->assertSame(PurchaseInvoice::class, Relation::getMorphedModel('purchase_invoice'));
    }

    public function test_payment_and_allocation_relationships_and_casts_and_constraints_work(): void
    {
        $creator = User::factory()->create();
        $poster = User::factory()->create();
        $canceller = User::factory()->create();
        $supplier = Contact::factory()->create(['is_supplier' => true]);
        $account = Account::query()->where('is_active', true)->firstOrFail();
        $paymentMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();
        $journal = Journal::query()->where('type', JournalType::Cash->value)->firstOrFail();
        $purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();

        $fiscalYear = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);
        $period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'name' => 'Nov 2026',
            'period_number' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $invoice = PurchaseInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'journal_id' => $purchaseJournal->id,
            'created_by' => $creator->id,
        ]);

        $payment = Payment::query()->create([
            'payment_number' => 'PAY-2026-00001',
            'payment_type' => PaymentType::SEND,
            'related_type' => PaymentRelatedType::SUPPLIER,
            'contact_id' => $supplier->id,
            'account_id' => $account->id,
            'payment_method_id' => $paymentMethod->id,
            'journal_id' => $journal->id,
            'payment_date' => '2026-11-26',
            'amount' => '500.00',
            'reference' => 'CHK-100',
            'description' => 'سداد فاتورة مورد',
            'status' => PaymentStatus::POSTED,
            'created_by' => $creator->id,
            'posted_by' => $poster->id,
            'posted_at' => now(),
            'cancelled_by' => $canceller->id,
            'cancelled_at' => now(),
        ]);

        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'allocatable_type' => $invoice->getMorphClass(),
            'allocatable_id' => $invoice->id,
            'allocated_amount' => '500.00',
        ]);

        $entry = JournalEntry::query()->create([
            'journal_id' => $journal->id,
            'fiscal_year_id' => $fiscalYear->id,
            'accounting_period_id' => $period->id,
            'contact_id' => $supplier->id,
            'entry_number' => 'JE-2026-00001',
            'entry_date' => '2026-11-26',
            'source_type' => $payment->getMorphClass(),
            'source_id' => $payment->id,
        ]);

        $freshPayment = Payment::query()->findOrFail($payment->id);

        $this->assertSame(PaymentType::SEND, $freshPayment->payment_type);
        $this->assertSame(PaymentRelatedType::SUPPLIER, $freshPayment->related_type);
        $this->assertSame(PaymentStatus::POSTED, $freshPayment->status);
        $this->assertSame('500.00', $freshPayment->amount);
        $this->assertSame('2026-11-26', $freshPayment->payment_date->toDateString());

        $this->assertSame($supplier->id, $freshPayment->contact?->id);
        $this->assertSame($account->id, $freshPayment->account?->id);
        $this->assertSame($paymentMethod->id, $freshPayment->paymentMethod?->id);
        $this->assertSame($journal->id, $freshPayment->journal?->id);
        $this->assertSame($creator->id, $freshPayment->creator?->id);
        $this->assertSame($poster->id, $freshPayment->postedBy?->id);
        $this->assertSame($canceller->id, $freshPayment->cancelledBy?->id);
        $this->assertCount(1, $freshPayment->allocations);
        $this->assertSame($entry->id, $freshPayment->journalEntry?->id);

        $this->assertSame($payment->id, $allocation->payment?->id);
        $this->assertInstanceOf(PurchaseInvoice::class, $allocation->allocatable);
        $this->assertSame($invoice->id, $allocation->allocatable->id);
        $this->assertCount(1, $invoice->paymentAllocations);
        $this->assertCount(1, $supplier->payments);
        $this->assertCount(1, $account->payments);

        // Unique constraint prevents duplicate target allocation on same payment
        try {
            PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'allocatable_type' => $invoice->getMorphClass(),
                'allocatable_id' => $invoice->id,
                'allocated_amount' => '100.00',
            ]);
            $this->fail('Expected QueryException for duplicate payment allocation target.');
        } catch (QueryException) {
            // Expected
        }

        // restrictOnDelete prevents deleting payment while allocations exist
        $this->expectException(QueryException::class);
        $freshPayment->delete();
    }
}
