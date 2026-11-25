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
use App\Models\JournalEntry;
use App\Models\Payment;
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

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    private AccountingPeriod $period;

    private Account $payableAccount;

    private Account $receivableAccount;

    private Account $sourceBankCashAccount;

    private Account $destinationBankCashAccount;

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

        $receivableType = AccountType::query()->where('code', AccountTypeCode::Receivable->value)->firstOrFail();
        $this->receivableAccount = Account::query()
            ->where('account_type_id', $receivableType->id)
            ->where('is_active', true)
            ->first()
            ?? Account::query()->create([
                'account_type_id' => $receivableType->id,
                'code' => '1201',
                'name' => 'Accounts Receivable',
                'is_active' => true,
            ]);

        $bankCashType = AccountType::query()->where('code', AccountTypeCode::BankCash->value)->firstOrFail();
        $bankCashAccounts = Account::query()
            ->where('account_type_id', $bankCashType->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $this->sourceBankCashAccount = $bankCashAccounts->firstOrFail();
        $this->destinationBankCashAccount = $bankCashAccounts->count() > 1
            ? $bankCashAccounts->get(1)
            : Account::query()->create([
                'account_type_id' => $bankCashType->id,
                'code' => '1109',
                'name' => 'Secondary Bank Account',
                'is_active' => true,
            ]);

        $this->cashMethod = PaymentMethod::query()->where('is_active', true)->firstOrFail();
        $this->cashMethod->update(['account_id' => $this->sourceBankCashAccount->id]);

        $this->cashJournal = Journal::query()->where('type', JournalType::Cash->value)->firstOrFail();
        $this->bankJournal = Journal::query()->where('type', JournalType::Bank->value)->firstOrFail();
        $this->purchaseJournal = Journal::query()->where('type', JournalType::Purchase->value)->firstOrFail();

        Setting::query()->create([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [
                'accounts_payable' => $this->payableAccount->id,
                'accounts_receivable' => $this->receivableAccount->id,
            ],
        ]);
    }

    private function createSupplier(string $name = 'مورد دار القاضي'): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
        ]);
    }

    private function createCustomer(string $name = 'عميل النور', ?int $receivableAccountId = null): Contact
    {
        return Contact::factory()->create([
            'name' => $name,
            'is_supplier' => false,
            'is_customer' => true,
            'is_active' => true,
            'receivable_account_id' => $receivableAccountId,
        ]);
    }

    private function createPostedPurchaseInvoice(Contact $supplier, float $unitPrice = 200.00): PurchaseInvoice
    {
        $product = Product::factory()->create(['is_active' => true]);
        $service = app(PurchaseInvoiceService::class);
        $invoice = $service->createInvoice([
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-11-10',
            'due_date' => '2026-11-25',
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                ],
            ],
        ], $this->user);

        return $service->postInvoice($invoice, $this->user);
    }

    public function test_generic_listing_returns_all_payment_types_with_filters_and_pagination(): void
    {
        $supplier = $this->createSupplier('مورد الأمل');
        $customer = $this->createCustomer('عميل السلام');

        $this->postJson('/api/payments', [
            'payment_type' => PaymentType::SEND->value,
            'related_type' => PaymentRelatedType::SUPPLIER->value,
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-10',
            'amount' => '150.00',
            'reference' => 'REF-SEND',
        ])->assertCreated();

        $this->postJson('/api/payments', [
            'payment_type' => PaymentType::RECEIVE->value,
            'related_type' => PaymentRelatedType::CUSTOMER->value,
            'contact_id' => $customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-12',
            'amount' => '250.00',
            'reference' => 'REF-RCV',
        ])->assertCreated();

        $this->postJson('/api/payments', [
            'payment_type' => PaymentType::TRANSFER->value,
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '350.00',
            'reference' => 'REF-TRF',
        ])->assertCreated();

        $all = $this->getJson('/api/payments?per_page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/payments?payment_type=receive&related_type=customer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_type', 'receive')
            ->assertJsonPath('data.0.related_type', 'customer')
            ->assertJsonPath('data.0.customer.id', $customer->id);

        $this->getJson('/api/payments?payment_type=transfer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.payment_type', 'transfer')
            ->assertJsonPath('data.0.account.id', $this->destinationBankCashAccount->id);

        $this->getJson('/api/payments?search=REF-RCV&payment_date_from=2026-11-11&payment_date_to=2026-11-13')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', 'REF-RCV');

        $this->assertNotEmpty($all->json('data'));
    }

    public function test_customer_receipt_lifecycle_creates_updates_posts_and_resolves_receivable_account(): void
    {
        $receivableType = AccountType::query()->where('code', AccountTypeCode::Receivable->value)->firstOrFail();
        $customReceivable = Account::query()->create([
            'account_type_id' => $receivableType->id,
            'code' => '1209',
            'name' => 'Custom Customer Receivable',
            'is_active' => true,
        ]);

        $customerWithFallback = $this->createCustomer('عميل افتراضي');
        $customerWithCustom = $this->createCustomer('عميل مخصص', $customReceivable->id);

        $createResponse = $this->postJson('/api/payments', [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customerWithFallback->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-14',
            'amount' => '300.00',
            'reference' => 'RCV-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_type', 'receive')
            ->assertJsonPath('data.related_type', 'customer')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.customer.id', $customerWithFallback->id);

        $paymentId = (int) $createResponse->json('data.id');
        $jeId = (int) $createResponse->json('data.journal_entry.id');
        $this->assertGreaterThan(0, $jeId);

        // Update to customer with custom receivable account and verify same Journal Entry is updated
        $this->putJson("/api/payments/{$paymentId}", [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customerWithCustom->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '450.00',
            'reference' => 'RCV-01-UPD',
        ])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.id', $jeId)
            ->assertJsonPath('data.customer.id', $customerWithCustom->id);

        // Post and verify balanced lines: Debit Bank/Cash, Credit Custom Receivable, contact_id set
        $this->postJson("/api/payments/{$paymentId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.journal_entry.status', JournalEntryStatus::Posted->value);

        $entry = JournalEntry::query()->with('lines')->findOrFail($jeId);
        $this->assertSame($customerWithCustom->id, $entry->contact_id);
        $debitLine = $entry->lines->firstWhere('debit', '450.00');
        $creditLine = $entry->lines->firstWhere('credit', '450.00');
        $this->assertSame($this->sourceBankCashAccount->id, $debitLine->account_id);
        $this->assertSame($customReceivable->id, $creditLine->account_id);

        // Posted payment is immutable
        $this->putJson("/api/payments/{$paymentId}", [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customerWithCustom->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '500.00',
        ])->assertStatus(422);

        $this->deleteJson("/api/payments/{$paymentId}")->assertStatus(422);
    }

    public function test_transfer_validation_and_accounting_lines(): void
    {
        // Same source and destination account rejected
        $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'account_id' => $this->sourceBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '100.00',
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);

        // Non-bank_cash account rejected
        $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'account_id' => $this->payableAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '100.00',
        ])->assertStatus(422)->assertJsonValidationErrors(['account_id']);

        // Non-cash/bank journal rejected
        $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->purchaseJournal->id,
            'payment_date' => '2026-11-15',
            'amount' => '100.00',
        ])->assertStatus(422)->assertJsonValidationErrors(['journal_id']);

        // Valid transfer creation + post
        $res = $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '500.00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.payment_type', 'transfer')
            ->assertJsonPath('data.related_type', null)
            ->assertJsonPath('data.contact_id', null)
            ->assertJsonPath('data.account_id', $this->destinationBankCashAccount->id);

        $paymentId = (int) $res->json('data.id');
        $jeId = (int) $res->json('data.journal_entry.id');

        $this->postJson("/api/payments/{$paymentId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $entry = JournalEntry::query()->with('lines')->findOrFail($jeId);
        $this->assertNull($entry->contact_id);
        $debitLine = $entry->lines->firstWhere('debit', '500.00');
        $creditLine = $entry->lines->firstWhere('credit', '500.00');
        $this->assertSame($this->destinationBankCashAccount->id, $debitLine->account_id);
        $this->assertSame($this->sourceBankCashAccount->id, $creditLine->account_id);
    }

    public function test_changing_draft_payment_type_clears_incompatible_data_and_syncs_journal_entry(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 200.00);

        // Start as Send + Supplier with invoice allocation
        $createRes = $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '200.00',
            'allocations' => [
                [
                    'purchase_invoice_id' => $invoice->id,
                    'allocated_amount' => '200.00',
                ],
            ],
        ])->assertCreated();

        $paymentId = (int) $createRes->json('data.id');
        $jeId = (int) $createRes->json('data.journal_entry.id');
        $this->assertDatabaseCount('payment_allocations', 1);

        // Switch Draft payment from Send -> Transfer
        $this->putJson("/api/payments/{$paymentId}", [
            'payment_type' => 'transfer',
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-17',
            'amount' => '200.00',
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_type', 'transfer')
            ->assertJsonPath('data.related_type', null)
            ->assertJsonPath('data.contact_id', null)
            ->assertJsonPath('data.account_id', $this->destinationBankCashAccount->id)
            ->assertJsonPath('data.journal_entry.id', $jeId);

        $this->assertDatabaseCount('payment_allocations', 0);

        $entry = JournalEntry::query()->with('lines')->findOrFail($jeId);
        $this->assertNull($entry->contact_id);
        $debitLine = $entry->lines->firstWhere('debit', '200.00');
        $this->assertSame($this->destinationBankCashAccount->id, $debitLine->account_id);
    }

    public function test_cancel_and_delete_draft_payment_remove_draft_journal_entry(): void
    {
        $customer = $this->createCustomer();

        $draft1 = $this->postJson('/api/payments', [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '120.00',
        ])->assertCreated();

        $id1 = (int) $draft1->json('data.id');
        $je1 = (int) $draft1->json('data.journal_entry.id');

        $this->postJson("/api/payments/{$id1}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::CANCELLED->value);

        $this->assertDatabaseMissing('journal_entries', ['id' => $je1]);

        $draft2 = $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-16',
            'amount' => '90.00',
        ])->assertCreated();

        $id2 = (int) $draft2->json('data.id');
        $je2 = (int) $draft2->json('data.journal_entry.id');

        $this->deleteJson("/api/payments/{$id2}")->assertOk();

        $this->assertDatabaseMissing('payments', ['id' => $id2]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $je2]);
    }

    public function test_journals_endpoint_supports_comma_separated_cash_and_bank_filter(): void
    {
        $res = $this->getJson('/api/accounting/journals?type=cash,bank&is_active=1')
            ->assertOk()
            ->assertJsonPath('success', true);

        $types = collect($res->json('data'))->pluck('type')->unique()->values()->all();
        $this->assertNotContains(JournalType::Purchase->value, $types);
        $this->assertContains(JournalType::Cash->value, $types);
        $this->assertContains(JournalType::Bank->value, $types);
    }

    public function test_customer_receipt_posting_uses_default_accounts_receivable_when_customer_has_no_custom_account(): void
    {
        $customer = $this->createCustomer('عميل بدون حساب مخصص', null);

        $createRes = $this->postJson('/api/payments', [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customer->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '325.00',
            'allocations' => [],
        ])->assertCreated();

        $paymentId = (int) $createRes->json('data.id');
        $jeId = (int) $createRes->json('data.journal_entry.id');
        $this->assertGreaterThan(0, $jeId);
        $this->assertSame(JournalEntryStatus::Draft->value, $createRes->json('data.journal_entry.status'));

        $this->postJson("/api/payments/{$paymentId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::POSTED->value);

        $entry = JournalEntry::query()->with('lines')->findOrFail($jeId);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($customer->id, $entry->contact_id);

        $debitLine = $entry->lines->firstWhere('debit', '325.00');
        $creditLine = $entry->lines->firstWhere('credit', '325.00');
        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame($this->sourceBankCashAccount->id, $debitLine->account_id);
        $this->assertSame($this->receivableAccount->id, $creditLine->account_id);
        $this->assertSame('325.00', $debitLine->debit);
        $this->assertSame('325.00', $creditLine->credit);
    }

    public function test_invalid_transfer_payload_rejects_contact_related_type_and_allocations(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 150.00);

        $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '150.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '150.00'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['related_type', 'contact_id', 'allocations']);

        $this->assertDatabaseCount('payments', 0);
        // Only the posted Purchase Invoice's own Journal Entry exists
        $this->assertDatabaseMissing('journal_entries', ['source_type' => Payment::class]);
    }

    public function test_send_and_receive_reject_non_null_account_id(): void
    {
        $supplier = $this->createSupplier();
        $customer = $this->createCustomer();

        $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '100.00',
            'allocations' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);

        $this->postJson('/api/payments', [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customer->id,
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '100.00',
            'allocations' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_id']);

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_valid_null_and_empty_values_are_accepted(): void
    {
        $supplier = $this->createSupplier();
        $customer = $this->createCustomer();

        $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'related_type' => null,
            'contact_id' => null,
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '100.00',
            'allocations' => [],
        ])->assertCreated();

        $this->postJson('/api/payments', [
            'payment_type' => 'receive',
            'related_type' => 'customer',
            'contact_id' => $customer->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '100.00',
            'allocations' => [],
        ])->assertCreated();

        $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'account_id' => null,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '100.00',
            'allocations' => [],
        ])->assertCreated();
    }

    public function test_generic_cancel_and_delete_for_all_payment_types_and_immutability(): void
    {
        $supplier = $this->createSupplier();
        $invoice = $this->createPostedPurchaseInvoice($supplier, 180.00);

        // Supplier payment with allocation: cancel preserves allocation & removes Draft JE
        $supCancel = $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '180.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '180.00'],
            ],
        ])->assertCreated();

        $supCancelId = (int) $supCancel->json('data.id');
        $supCancelJeId = (int) $supCancel->json('data.journal_entry.id');

        $this->postJson("/api/payments/{$supCancelId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PaymentStatus::CANCELLED->value);

        $this->assertDatabaseMissing('journal_entries', ['id' => $supCancelJeId]);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $supCancelId]);

        // Cancelled payment is immutable
        $this->putJson("/api/payments/{$supCancelId}", [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '180.00',
        ])->assertStatus(422);
        $this->deleteJson("/api/payments/{$supCancelId}")->assertStatus(422);

        // Supplier payment with allocation: delete removes payment, allocation, and Draft JE
        $supDelete = $this->postJson('/api/payments', [
            'payment_type' => 'send',
            'related_type' => 'supplier',
            'contact_id' => $supplier->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->cashJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '180.00',
            'allocations' => [
                ['purchase_invoice_id' => $invoice->id, 'allocated_amount' => '180.00'],
            ],
        ])->assertCreated();

        $supDeleteId = (int) $supDelete->json('data.id');
        $supDeleteJeId = (int) $supDelete->json('data.journal_entry.id');

        $this->deleteJson("/api/payments/{$supDeleteId}")->assertOk();
        $this->assertDatabaseMissing('payments', ['id' => $supDeleteId]);
        $this->assertDatabaseMissing('payment_allocations', ['payment_id' => $supDeleteId]);
        $this->assertDatabaseMissing('journal_entries', ['id' => $supDeleteJeId]);

        // Posted payment cannot be cancelled
        $transfer = $this->postJson('/api/payments', [
            'payment_type' => 'transfer',
            'account_id' => $this->destinationBankCashAccount->id,
            'payment_method_id' => $this->cashMethod->id,
            'journal_id' => $this->bankJournal->id,
            'payment_date' => '2026-11-18',
            'amount' => '75.00',
        ])->assertCreated();

        $transferId = (int) $transfer->json('data.id');
        $this->postJson("/api/payments/{$transferId}/post")->assertOk();
        $this->postJson("/api/payments/{$transferId}/cancel")->assertStatus(422);
    }
}
