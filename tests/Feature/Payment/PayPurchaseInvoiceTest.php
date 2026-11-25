<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
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
use App\Services\Purchase\PurchaseInvoiceService;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayPurchaseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $payableAccount;

    private PaymentMethod $cashMethod;

    private Journal $cashJournal;

    private Journal $purchaseJournal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();
        $this->seed([WarehouseSeeder::class, AccountingSeeder::class, PaymentMethodSeeder::class]);

        $fiscalYear = FiscalYear::query()->create([
            'code' => 'FY-2026',
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => FiscalYearStatus::Open->value,
        ]);

        AccountingPeriod::query()->create([
            'fiscal_year_id' => $fiscalYear->id,
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
    }

    private function createSupplier(string $name = 'مورد النخبة'): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);
    }

    private function createInvoice(Contact $supplier, float $unitPrice = 1000.00, bool $post = true): PurchaseInvoice
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

        return $post ? $invoiceService->postInvoice($draft, $this->user) : $draft;
    }

    public function test_partial_invoice_payments_reduce_outstanding_after_posting_and_support_second_payment(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createInvoice($supplier, 1000.00, true);

        // Initial outstanding on purchase invoice show & outstanding-invoices endpoint
        $this->getJson("/api/purchase-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.outstanding_amount', '1000.00');

        // 1. Create first partial payment (300.00) as Draft
        $p1Res = $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '300.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '300.00'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::DRAFT->value)
            ->assertJsonPath('data.amount', '300.00')
            ->assertJsonPath('data.journal_entry.status', JournalEntryStatus::Draft->value)
            ->assertJsonPath('data.allocations.0.allocatable_id', $invoice->id)
            ->assertJsonPath('data.allocations.0.allocated_amount', '300.00');

        $p1Id = (int) $p1Res->json('data.id');

        // Draft payment does NOT reduce outstanding amount yet
        $this->getJson("/api/purchase-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '0.00')
            ->assertJsonPath('data.outstanding_amount', '1000.00');

        // 2. Post first partial payment -> outstanding becomes 700.00
        $this->postJson("/api/payments/{$p1Id}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::POSTED->value);

        $this->getJson("/api/purchase-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '300.00')
            ->assertJsonPath('data.outstanding_amount', '700.00');

        $this->getJson("/api/supplier-payments/outstanding-invoices?supplier_id={$supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $invoice->id)
            ->assertJsonPath('data.0.paid_amount', '300.00')
            ->assertJsonPath('data.0.outstanding_amount', '700.00');

        // 3. Reject amount exceeding new remaining outstanding (750.00 > 700.00)
        $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '750.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '750.00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allocations.0.allocated_amount']);

        $this->assertDatabaseCount('payments', 1);

        // 4. Create and post second payment for exact remaining balance (700.00)
        $p2Res = $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '700.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '700.00'],
            ],
        ])->assertCreated();

        $p2Id = (int) $p2Res->json('data.id');
        $this->postJson("/api/payments/{$p2Id}/post")->assertOk();

        // Fully paid -> outstanding is 0.00 and excluded from outstanding-invoices
        $this->getJson("/api/purchase-invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.paid_amount', '1000.00')
            ->assertJsonPath('data.outstanding_amount', '0.00');

        $this->getJson("/api/supplier-payments/outstanding-invoices?supplier_id={$supplier->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(2, PaymentAllocation::query()->where('allocatable_id', $invoice->id)->count());
    }

    public function test_rejects_invalid_amounts_ineligible_invoices_and_wrong_supplier_without_persisting_records(): void
    {
        $supplierA = $this->createSupplier('مورد أ');
        $supplierB = $this->createSupplier('مورد ب');

        $draftInvoice = $this->createInvoice($supplierA, 500.00, false);
        $postedInvoiceA = $this->createInvoice($supplierA, 500.00, true);
        $cancelledInvoice = $this->createInvoice($supplierA, 500.00, false);
        app(PurchaseInvoiceService::class)->cancelInvoice($cancelledInvoice, $this->user, 'Test cancel');

        // Zero and negative amounts
        foreach (['0', '0.00', '-50.00'] as $badAmount) {
            $this->postJson('/api/payments', [
                'payment_type' => 'send',
                'related_type' => 'supplier',
                'contact_id' => $supplierA->id,
                'account_id' => null,
                'payment_method_id' => $this->cashMethod->id,
                'journal_id' => $this->cashJournal->id,
                'payment_date' => '2026-11-16',
                'amount' => $badAmount,
                'allocations' => [
                    ['purchase_invoice_id' => $postedInvoiceA->id, 'allocated_amount' => $badAmount],
                ],
            ])->assertStatus(422);
        }

        // Draft invoice
        $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplierA->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '100.00',
            'allocations' => [
                ['purchase_invoice_id' => $draftInvoice->id, 'allocated_amount' => '100.00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allocations.0.purchase_invoice_id']);

        // Cancelled invoice
        $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplierA->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '100.00',
            'allocations' => [
                ['purchase_invoice_id' => $cancelledInvoice->id, 'allocated_amount' => '100.00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allocations.0.purchase_invoice_id']);

        // Wrong supplier
        $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplierB->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '100.00',
            'allocations' => [
                ['purchase_invoice_id' => $postedInvoiceA->id, 'allocated_amount' => '100.00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allocations.0.purchase_invoice_id']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseMissing('journal_entries', ['source_type' => Payment::class]);
    }
}
