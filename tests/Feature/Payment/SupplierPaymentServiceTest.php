<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

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
use App\Models\AccountType;
use App\Models\Contact;
use App\Models\FiscalYear;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\Payment\SupplierPaymentService;
use App\Services\Purchase\PurchaseInvoiceService;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    private AccountingPeriod $period;

    private Account $payableAccount;

    private PaymentMethod $cashMethod;

    private Journal $cashJournal;

    private Journal $purchaseJournal;

    private SupplierPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();
        $this->seed([WarehouseSeeder::class, AccountingSeeder::class, PaymentMethodSeeder::class]);

        $this->fiscalYear = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);

        $this->period = AccountingPeriod::query()->create([
            'fiscal_year_id' => $this->fiscalYear->id,
            'name' => 'Nov 2026',
            'period_number' => 11,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $this->payableAccount = Account::query()->where('code', '2101')->firstOrFail();
        $bankCashAccount = Account::query()
            ->whereHas('accountType', static fn ($q) => $q->where('code', AccountTypeCode::BankCash->value))
            ->where('is_active', true)
            ->firstOrFail();
        $this->cashMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();
        $this->cashMethod->update(['account_id' => $bankCashAccount->id]);
        $this->cashJournal = Journal::query()->where('type', JournalType::Cash->value)->firstOrFail();
        $this->purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();

        Setting::query()->create([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [
                'accounts_payable' => $this->payableAccount->id,
            ],
        ]);

        $this->service = app(SupplierPaymentService::class);
    }

    private function createSupplier(?int $payableAccountId = null, bool $isActive = true): Contact
    {
        return Contact::factory()->create([
            'name' => 'دار القاضي للموردين',
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => $isActive,
            'payable_account_id' => $payableAccountId,
        ]);
    }

    private function createPostedPurchaseInvoice(Contact $supplier, float $unitPrice = 500.00): PurchaseInvoice
    {
        $product = Product::factory()->create(['is_active' => true]);
        $invoiceService = app(PurchaseInvoiceService::class);

        $draft = $invoiceService->createInvoice([
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-11-10',
            'due_date' => '2026-11-25',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => $unitPrice],
            ],
        ], $this->user);

        return $invoiceService->postInvoice($draft, $this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(Contact $supplier, array $allocations, array $overrides = []): array
    {
        return array_merge([
            'payment_type' => PaymentType::SEND->value,
            'related_type' => PaymentRelatedType::SUPPLIER->value,
            'contact_id' => $supplier->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '500.00',
            'reference' => 'PAY-REF-01',
            'description' => 'سداد دفعة للمورد',
            'allocations' => $allocations,
        ], $overrides);
    }

    public function test_create_supplier_payment_creates_draft_payment_allocations_and_linked_draft_journal_entry(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 500.00);

        $payment = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '500.00'],
            ]),
            $this->user,
        );

        $this->assertSame('PAY-2026-00001', $payment->payment_number);
        $this->assertSame(PaymentType::SEND, $payment->payment_type);
        $this->assertSame(PaymentRelatedType::SUPPLIER, $payment->related_type);
        $this->assertSame(PaymentStatus::DRAFT, $payment->status);
        $this->assertSame($supplier->id, $payment->contact_id);
        $this->assertNull($payment->account_id);
        $this->assertSame('500.00', $payment->amount);
        $this->assertCount(1, $payment->allocations);

        $entry = $payment->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame($supplier->id, $entry->contact_id);
        $this->assertSame($this->cashJournal->id, $entry->journal_id);
        $this->assertSame('2026-11-26', $entry->entry_date->toDateString());
        $this->assertCount(2, $entry->lines);

        // Debit AP, Credit PaymentMethod bank_cash account
        $this->assertSame($this->payableAccount->id, $entry->lines[0]->account_id);
        $this->assertSame('500.00', $entry->lines[0]->debit);
        $this->assertSame('0.00', $entry->lines[0]->credit);

        $this->assertSame($this->cashMethod->account_id, $entry->lines[1]->account_id);
        $this->assertSame('0.00', $entry->lines[1]->debit);
        $this->assertSame('500.00', $entry->lines[1]->credit);
    }

    public function test_supplier_specific_payable_account_takes_precedence_over_default_setting(): void
    {
        $payableType = AccountType::query()->where('code', AccountTypeCode::Payable->value)->firstOrFail();
        $customPayable = Account::query()->create([
            'account_type_id' => $payableType->id,
            'code' => '2199',
            'name' => 'موردين مخصص',
            'is_active' => true,
        ]);

        $supplier = $this->createSupplier($customPayable->id);
        $invoice = $this->createPostedPurchaseInvoice($supplier, 300.00);

        $payment = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '300.00'],
            ], ['amount' => '300.00']),
            $this->user,
        );

        $this->assertSame($customPayable->id, $payment->journalEntry->lines[0]->account_id);
    }

    public function test_rejects_invalid_classifications_account_id_supplier_payment_method_and_journal(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 500.00);
        $alloc = [['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '500.00']];

        $cases = [
            ['payment_type' => PaymentType::RECEIVE->value],
            ['payment_type' => PaymentType::TRANSFER->value],
            ['related_type' => PaymentRelatedType::CUSTOMER->value],
            ['related_type' => null],
            ['account_id' => $this->payableAccount->id],
            ['journal_id' => $this->purchaseJournal->id],
            ['amount' => '0.00'],
        ];

        foreach ($cases as $override) {
            try {
                $this->service->create($this->validPayload($supplier, $alloc, $override), $this->user);
                $this->fail('Expected ValidationException for override: '.json_encode($override));
            } catch (ValidationException) {
                // Expected
            }
        }

        // Inactive supplier & non-supplier customer rejected
        $inactiveSupplier = $this->createSupplier(null, false);
        $customerOnly = Contact::factory()->create(['is_supplier' => false, 'is_customer' => true, 'is_active' => true]);

        foreach ([$inactiveSupplier->id, $customerOnly->id] as $badContactId) {
            try {
                $this->service->create($this->validPayload($supplier, $alloc, ['contact_id' => $badContactId]), $this->user);
                $this->fail('Expected ValidationException for invalid contact_id.');
            } catch (ValidationException) {
                // Expected
            }
        }

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('journal_entries', 1); // Only the 1 entry from the created+posted purchase invoice
    }

    public function test_multiple_invoices_and_partial_payments_and_draft_allocations_do_not_reduce_outstanding(): void
    {
        $supplier = $this->createSupplier();
        $inv1 = $this->createPostedPurchaseInvoice($supplier, 400.00);
        $inv2 = $this->createPostedPurchaseInvoice($supplier, 600.00);

        // First partial payment (Draft) should NOT reduce outstanding balance yet
        $draftPayment = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $inv1->id, 'allocated_amount' => '250.00'],
                ['purchase_invoice_id' => $inv2->id, 'allocated_amount' => '150.00'],
            ], ['amount' => '400.00']),
            $this->user,
        );

        $this->assertSame('400.00', $this->service->calculateInvoiceOutstandingAmount($inv1));
        $this->assertSame('600.00', $this->service->calculateInvoiceOutstandingAmount($inv2));

        // Post first partial payment
        $this->service->post($draftPayment, $this->user);

        $this->assertSame('150.00', $this->service->calculateInvoiceOutstandingAmount($inv1));
        $this->assertSame('450.00', $this->service->calculateInvoiceOutstandingAmount($inv2));

        // Second partial payment settles remaining 150.00 on inv1
        $second = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $inv1->id, 'allocated_amount' => '150.00'],
            ], ['amount' => '150.00']),
            $this->user,
        );
        $this->service->post($second, $this->user);

        $this->assertSame('0.00', $this->service->calculateInvoiceOutstandingAmount($inv1));
    }

    public function test_allocation_validations_reject_wrong_supplier_draft_cancelled_duplicate_mismatch_and_over_allocation(): void
    {
        $supplier = $this->createSupplier();
        $otherSupplier = $this->createSupplier();
        $postedInv = $this->createPostedPurchaseInvoice($supplier, 300.00);
        $otherSupplierInv = $this->createPostedPurchaseInvoice($otherSupplier, 300.00);

        $product = Product::factory()->create(['is_active' => true]);
        $invoiceService = app(PurchaseInvoiceService::class);
        $draftInv = $invoiceService->createInvoice([
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-11-10',
            'due_date' => '2026-11-25',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200]],
        ], $this->user);

        $cancelledInv = $invoiceService->cancelInvoice(
            $invoiceService->createInvoice([
                'supplier_id' => $supplier->id,
                'journal_id' => $this->purchaseJournal->id,
                'invoice_date' => '2026-11-10',
                'due_date' => '2026-11-25',
                'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200]],
            ], $this->user),
            $this->user,
        );

        $badCases = [
            // Another supplier's invoice
            ['amount' => '300.00', 'allocations' => [['purchase_invoice_id' => $otherSupplierInv->id, 'allocated_amount' => '300.00']]],
            // Draft invoice
            ['amount' => '200.00', 'allocations' => [['purchase_invoice_id' => $draftInv->id, 'allocated_amount' => '200.00']]],
            // Cancelled invoice
            ['amount' => '200.00', 'allocations' => [['purchase_invoice_id' => $cancelledInv->id, 'allocated_amount' => '200.00']]],
            // Exceeds outstanding balance
            ['amount' => '300.01', 'allocations' => [['purchase_invoice_id' => $postedInv->id, 'allocated_amount' => '300.01']]],
            // Sum mismatch (unallocated remainder)
            ['amount' => '300.00', 'allocations' => [['purchase_invoice_id' => $postedInv->id, 'allocated_amount' => '250.00']]],
            // Duplicate invoice target
            ['amount' => '200.00', 'allocations' => [
                ['purchase_invoice_id' => $postedInv->id, 'allocated_amount' => '100.00'],
                ['purchase_invoice_id' => $postedInv->id, 'allocated_amount' => '100.00'],
            ]],
        ];

        foreach ($badCases as $bad) {
            try {
                $this->service->create($this->validPayload($supplier, $bad['allocations'], ['amount' => $bad['amount']]), $this->user);
                $this->fail('Expected ValidationException for allocation case.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_update_draft_payment_synchronizes_same_journal_entry_and_replaces_allocations(): void
    {
        $supplier = $this->createSupplier();
        $inv1 = $this->createPostedPurchaseInvoice($supplier, 500.00);
        $inv2 = $this->createPostedPurchaseInvoice($supplier, 500.00);

        $payment = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $inv1->id, 'allocated_amount' => '200.00'],
            ], ['amount' => '200.00']),
            $this->user,
        );

        $originalEntryId = $payment->journalEntry->id;
        $originalEntryNumber = $payment->journalEntry->entry_number;

        $updated = $this->service->update($payment, $this->validPayload($supplier, [
            ['purchase_invoice_id' => $inv1->id, 'allocated_amount' => '150.00'],
            ['purchase_invoice_id' => $inv2->id, 'allocated_amount' => '250.00'],
        ], ['amount' => '400.00', 'reference' => 'UPD-REF']), $this->user);

        $this->assertSame('400.00', $updated->amount);
        $this->assertCount(2, $updated->allocations);
        $this->assertSame($originalEntryId, $updated->journalEntry->id);
        $this->assertSame($originalEntryNumber, $updated->journalEntry->entry_number);
        $this->assertSame('UPD-REF', $updated->journalEntry->reference);
        $this->assertSame('400.00', $updated->journalEntry->lines[0]->debit);
        $this->assertSame('400.00', $updated->journalEntry->lines[1]->credit);
    }

    public function test_post_synchronizes_and_posts_same_journal_entry_atomically_and_blocks_concurrent_over_allocation(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 500.00);

        // Two Draft payments both created while invoice has 500.00 outstanding
        $first = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '350.00'],
            ], ['amount' => '350.00']),
            $this->user,
        );

        $second = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '300.00'],
            ], ['amount' => '300.00']),
            $this->user,
        );

        $firstEntryId = $first->journalEntry->id;
        $postedFirst = $this->service->post($first, $this->user);

        $this->assertSame(PaymentStatus::POSTED, $postedFirst->status);
        $this->assertSame($this->user->id, $postedFirst->posted_by);
        $this->assertNotNull($postedFirst->posted_at);
        $this->assertSame($firstEntryId, $postedFirst->journalEntry->id);
        $this->assertSame(JournalEntryStatus::Posted, $postedFirst->journalEntry->status);

        // Posting the second draft payment must fail revalidation because only 150.00 remains
        try {
            $this->service->post($second, $this->user);
            $this->fail('Expected ValidationException when posting exceeds remaining balance.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations.0.allocated_amount', $e->errors());
        }

        $second->refresh();
        $this->assertSame(PaymentStatus::DRAFT, $second->status);
        $this->assertSame(JournalEntryStatus::Draft, $second->journalEntry->status);

        // Posted payment cannot be updated, cancelled, or deleted
        foreach ([
            fn () => $this->service->update($postedFirst, $this->validPayload($supplier, [['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '350.00']], ['amount' => '350.00']), $this->user),
            fn () => $this->service->cancel($postedFirst, $this->user),
            fn () => $this->service->delete($postedFirst),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Expected ValidationException on immutable posted payment.');
            } catch (ValidationException) {
                // Expected
            }
        }
    }

    public function test_failed_posting_rolls_back_both_payment_and_journal_entry_to_draft(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 400.00);

        $payment = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '400.00'],
            ], ['amount' => '400.00']),
            $this->user,
        );

        // Close the accounting period before posting
        $this->period->update(['status' => AccountingPeriodStatus::Closed->value]);

        try {
            $this->service->post($payment, $this->user);
            $this->fail('Expected ValidationException when accounting period is closed.');
        } catch (ValidationException) {
            // Expected
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::DRAFT, $payment->status);
        $this->assertNull($payment->posted_at);
        $this->assertSame(JournalEntryStatus::Draft, $payment->journalEntry->status);
    }

    public function test_cancel_and_delete_draft_payment_behavior(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 500.00);

        // Cancel Draft: deletes Draft JE, preserves allocation history
        $toCancel = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '200.00'],
            ], ['amount' => '200.00']),
            $this->user,
        );
        $cancelJeId = $toCancel->journalEntry->id;

        $cancelled = $this->service->cancel($toCancel, $this->user);
        $this->assertSame(PaymentStatus::CANCELLED, $cancelled->status);
        $this->assertSame($this->user->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertDatabaseMissing('journal_entries', ['id' => $cancelJeId]);
        $this->assertSame(1, PaymentAllocation::query()->where('payment_id', $cancelled->id)->count());
        $this->assertSame('500.00', $this->service->calculateInvoiceOutstandingAmount($invoice));

        // Cancelled payment cannot be updated or deleted
        try {
            $this->service->delete($cancelled);
            $this->fail('Expected ValidationException deleting cancelled payment.');
        } catch (ValidationException) {
            // Expected
        }

        // Delete Draft: deletes Draft JE, allocations, and Payment
        $toDelete = $this->service->create(
            $this->validPayload($supplier, [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '200.00'],
            ], ['amount' => '200.00']),
            $this->user,
        );
        $deleteJeId = $toDelete->journalEntry->id;
        $deletePaymentId = $toDelete->id;

        $this->assertTrue($this->service->delete($toDelete));
        $this->assertDatabaseMissing('payments', ['id' => $deletePaymentId]);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $deletePaymentId]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $deleteJeId]);
    }

    public function test_supplier_advance_without_allocations_uses_same_payable_account_and_supports_full_lifecycle(): void
    {
        $supplier = $this->createSupplier();

        // Create advance without allocations
        $advance = $this->service->create(
            $this->validPayload($supplier, [], [
                'amount' => '750.00',
                'reference' => 'ADV-001',
            ]),
            $this->user,
        );

        $this->assertSame(PaymentStatus::DRAFT, $advance->status);
        $this->assertSame('750.00', $advance->amount);
        $this->assertCount(0, $advance->allocations);
        $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $advance->id)->count());

        $entry = $advance->journalEntry;
        $this->assertNotNull($entry);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame($supplier->id, $entry->contact_id);
        $this->assertSame($this->payableAccount->id, $entry->lines[0]->account_id);
        $this->assertSame('750.00', $entry->lines[0]->debit);
        $this->assertSame($this->cashMethod->account_id, $entry->lines[1]->account_id);
        $this->assertSame('750.00', $entry->lines[1]->credit);

        // Update draft advance amount while keeping empty allocations
        $originalEntryId = $entry->id;
        $updated = $this->service->update(
            $advance,
            $this->validPayload($supplier, [], [
                'amount' => '900.00',
                'reference' => 'ADV-001-UPD',
            ]),
            $this->user,
        );

        $this->assertSame('900.00', $updated->amount);
        $this->assertCount(0, $updated->allocations);
        $this->assertSame($originalEntryId, $updated->journalEntry->id);
        $this->assertSame('900.00', $updated->journalEntry->lines[0]->debit);

        // Post advance atomically
        $posted = $this->service->post($updated, $this->user);
        $this->assertSame(PaymentStatus::POSTED, $posted->status);
        $this->assertSame($originalEntryId, $posted->journalEntry->id);
        $this->assertSame(JournalEntryStatus::Posted, $posted->journalEntry->status);
        $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $posted->id)->count());
    }
}
