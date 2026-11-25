<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\JournalType;
use App\Enums\Payment\PaymentStatus;
use App\Enums\UserStatus;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Contact;
use App\Models\FiscalYear;
use App\Models\Journal;
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

class SupplierPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    private AccountingPeriod $period;

    private Account $payableAccount;

    private PaymentMethod $cashMethod;

    private Journal $cashJournal;

    private Journal $bankJournal;

    private Journal $purchaseJournal;

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
        $this->bankJournal = Journal::query()->where('type', JournalType::Bank->value)->firstOrFail();
        $this->purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();

        Setting::query()->create([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [
                'accounts_payable' => $this->payableAccount->id,
            ],
        ]);
    }

    private function createSupplier(string $name = 'دار القاضي'): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);
    }

    private function createInvoice(Contact $supplier, float $unitPrice = 500.00, bool $post = true): PurchaseInvoice
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

    public function test_unauthenticated_and_inactive_users_are_blocked(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/supplier-payments')->assertUnauthorized();
        $this->postJson('/api/supplier-payments', [])->assertUnauthorized();
        $this->getJson('/api/supplier-payments/outstanding-invoices')->assertUnauthorized();

        $inactiveUser = User::factory()->create(['status' => UserStatus::INACTIVE]);
        $this->actingAs($inactiveUser)
            ->getJson('/api/supplier-payments')
            ->assertUnauthorized();
    }

    public function test_can_create_show_update_post_supplier_payment_with_allocations(): void
    {
        $supplier = $this->createSupplier('مكتبة النهضة');
        $inv1 = $this->createInvoice($supplier, 400.00);
        $inv2 = $this->createInvoice($supplier, 600.00);

        $createRes = $this->actingAs($this->user)
            ->postJson('/api/supplier-payments', [
                'contact_id' => $supplier->id,
                'payment_method_id' => $this->cashMethod->id,
                'journal_id' => $this->cashJournal->id,
                'payment_date' => '2026-11-26',
                'amount' => '300.00',
                'reference' => 'REF-API-1',
                'description' => 'دفعة جزئية',
                'allocations' => [
                    ['purchase_invoice_id' => $inv1->id, 'allocated_amount' => '300.00'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', PaymentStatus::DRAFT->value)
            ->assertJsonPath('data.supplier.id', $supplier->id)
            ->assertJsonPath('data.journal_entry.status', JournalEntryStatus::Draft->value)
            ->assertJsonCount(1, 'data.allocations');

        $paymentId = (int) $createRes->json('data.id');
        $entryId = (int) $createRes->json('data.journal_entry.id');

        // Show
        $this->getJson("/api/supplier-payments/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('data.id', $paymentId)
            ->assertJsonPath('data.allocations.0.purchase_invoice.id', $inv1->id);

        // Update Draft
        $this->putJson("/api/supplier-payments/{$paymentId}", [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '500.00',
            'reference' => 'REF-API-2',
            'allocations' => [
                ['purchase_invoice_id' => $inv1->id, 'allocated_amount' => '200.00'],
                ['purchase_invoice_id' => $inv2->id, 'allocated_amount' => '300.00'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.journal_entry.id', $entryId)
            ->assertJsonCount(2, 'data.allocations');

        // Post Draft
        $this->postJson("/api/supplier-payments/{$paymentId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::POSTED->value)
            ->assertJsonPath('data.journal_entry.id', $entryId)
            ->assertJsonPath('data.journal_entry.status', JournalEntryStatus::Posted->value);

        // Posted payment rejects update, post, cancel, delete
        $this->putJson("/api/supplier-payments/{$paymentId}", [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '500.00',
            'allocations' => [],
        ])->assertUnprocessable();
        $this->postJson("/api/supplier-payments/{$paymentId}/post")->assertUnprocessable();
        $this->postJson("/api/supplier-payments/{$paymentId}/cancel")->assertUnprocessable();
        $this->deleteJson("/api/supplier-payments/{$paymentId}")->assertUnprocessable();
    }

    public function test_can_create_supplier_advance_without_allocations_and_returns_empty_allocations(): void
    {
        $supplier = $this->createSupplier();

        $res = $this->actingAs($this->user)
            ->postJson('/api/supplier-payments', [
                'contact_id' => $supplier->id,
                'payment_method_id' => $this->cashMethod->id,
                'journal_id' => $this->cashJournal->id,
                'payment_date' => '2026-11-26',
                'amount' => '450.00',
                'allocations' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PaymentStatus::DRAFT->value)
            ->assertJsonPath('data.allocations', [])
            ->assertJsonPath('data.journal_entry.status', JournalEntryStatus::Draft->value);

        $paymentId = (int) $res->json('data.id');
        $this->assertSame(0, PaymentAllocation::query()->where('payment_id', $paymentId)->count());
    }

    public function test_can_cancel_and_delete_draft_payments(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createInvoice($supplier, 500.00);

        $cancelRes = $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '200.00',
            'allocations' => [['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '200.00']],
        ])->assertCreated();

        $cancelId = (int) $cancelRes->json('data.id');
        $cancelJeId = (int) $cancelRes->json('data.journal_entry.id');

        $this->postJson("/api/supplier-payments/{$cancelId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::CANCELLED->value);

        $this->assertDatabaseMissing('journal_entries', ['id' => $cancelJeId]);
        $this->assertSame(1, PaymentAllocation::query()->where('payment_id', $cancelId)->count());
        $this->deleteJson("/api/supplier-payments/{$cancelId}")->assertUnprocessable();

        $deleteRes = $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '100.00',
            'allocations' => [],
        ])->assertCreated();

        $deleteId = (int) $deleteRes->json('data.id');
        $deleteJeId = (int) $deleteRes->json('data.journal_entry.id');

        $this->deleteJson("/api/supplier-payments/{$deleteId}")->assertOk();
        $this->assertDatabaseMissing('payments', ['id' => $deleteId]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $deleteJeId]);
    }

    public function test_listing_supports_pagination_search_and_filters(): void
    {
        $supplier1 = $this->createSupplier('مورد الأمل');
        $supplier2 = $this->createSupplier('مورد الفجر');

        $p1 = $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier1->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-10',
            'amount' => '100.00',
            'reference' => 'UNIQUE-ALPHA',
        ])->assertCreated()->json('data.id');

        $p2 = $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier2->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-20',
            'amount' => '200.00',
            'reference' => 'UNIQUE-BETA',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/supplier-payments/{$p2}/post")->assertOk();

        // Pagination
        $this->getJson('/api/supplier-payments?per_page=1&page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2);

        // Search
        $this->getJson('/api/supplier-payments?search=ALPHA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p1);

        // Filters: supplier, status, date range, journal
        $this->getJson("/api/supplier-payments?contact_id={$supplier2->id}&status=posted&payment_date_from=2026-11-15&payment_date_to=2026-11-25&journal_id={$this->bankJournal->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $p2);
    }

    public function test_outstanding_invoices_endpoint_returns_only_eligible_posted_balances(): void
    {
        $supplier = $this->createSupplier();
        $otherSupplier = $this->createSupplier('مورد آخر');

        $postedPartial = $this->createInvoice($supplier, 500.00, true);
        $postedSettled = $this->createInvoice($supplier, 300.00, true);
        $draftInv = $this->createInvoice($supplier, 200.00, false);
        $otherSupplierInv = $this->createInvoice($otherSupplier, 400.00, true);

        $cancelledInv = $this->createInvoice($supplier, 250.00, false);
        app(PurchaseInvoiceService::class)->cancelInvoice($cancelledInv, $this->user);

        // Post partial payment (200 on postedPartial, 300 on postedSettled)
        $postedPaymentId = (int) $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '500.00',
            'allocations' => [
                ['purchase_invoice_id' => $postedPartial->id, 'allocated_amount' => '200.00'],
                ['purchase_invoice_id' => $postedSettled->id, 'allocated_amount' => '300.00'],
            ],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/supplier-payments/{$postedPaymentId}/post")->assertOk();

        // Create Draft payment & Posted Advance — neither should reduce postedPartial's remaining 300.00
        $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '100.00',
            'allocations' => [['purchase_invoice_id' => $postedPartial->id, 'allocated_amount' => '100.00']],
        ])->assertCreated();

        $advanceId = (int) $this->postJson('/api/supplier-payments', [
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-26',
            'amount' => '1000.00',
            'allocations' => [],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/supplier-payments/{$advanceId}/post")->assertOk();

        $res = $this->getJson("/api/supplier-payments/outstanding-invoices?supplier_id={$supplier->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $postedPartial->id)
            ->assertJsonPath('data.0.total', '500.00')
            ->assertJsonPath('data.0.paid_amount', '200.00')
            ->assertJsonPath('data.0.outstanding_amount', '300.00');

        $returnedIds = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($postedSettled->id, $returnedIds);
        $this->assertNotContains($draftInv->id, $returnedIds);
        $this->assertNotContains($cancelledInv->id, $returnedIds);
        $this->assertNotContains($otherSupplierInv->id, $returnedIds);
    }

    public function test_validation_rejects_invalid_request_inputs(): void
    {
        $supplier = $this->createSupplier();
        $customerOnly = Contact::factory()->create(['is_supplier' => false, 'is_customer' => true, 'is_active' => true]);
        $unlinkedMethod = PaymentMethod::query()->create(['name' => 'غير مربوط', 'type' => 'cash', 'account_id' => null, 'is_active' => true]);

        $this->postJson('/api/supplier-payments', [
            'contact_id' => $customerOnly->id,
            'payment_method_id' => $unlinkedMethod->id,
            'journal_id' => $this->purchaseJournal->id,
            'account_id' => $this->payableAccount->id,
            'payment_date' => '2026-11-26',
            'amount' => 0,
            'allocations' => [['purchase_invoice_id' => 999999, 'allocated_amount' => 0]],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'contact_id',
                'payment_method_id',
                'journal_id',
                'account_id',
                'amount',
                'allocations.0.purchase_invoice_id',
                'allocations.0.allocated_amount',
            ]);
    }
}
