<?php

declare(strict_types=1);

namespace Tests\Feature\Purchase;

use App\Enums\Accounting\AccountingPeriodStatus;
use App\Enums\Accounting\AccountTypeCode;
use App\Enums\Accounting\FiscalYearStatus;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\JournalType;
use App\Enums\Accounting\PurchaseExpensesTreatment;
use App\Enums\InventoryDocumentStatus;
use App\Enums\PurchaseInvoiceStatus;
use App\Listeners\Purchase\SyncPurchaseInvoiceJournalEntry;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AccountType;
use App\Models\Contact;
use App\Models\FiscalYear;
use App\Models\InventoryDocument;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseInvoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\AccountingSettingRegistry;
use App\Services\Accounting\JournalEntryService;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseInvoiceAccountingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FiscalYear $fiscalYear;

    private AccountingPeriod $period;

    private Account $payableAccount;

    private Account $stockInputAccount;

    private Account $expenseAccount;

    private Journal $purchaseJournal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->actingAsSuperAdmin();

        $this->seed([WarehouseSeeder::class, AccountingSeeder::class]);

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
            'period_number' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => AccountingPeriodStatus::Open->value,
        ]);

        $this->payableAccount = Account::query()->where('code', '2101')->firstOrFail();
        $this->stockInputAccount = Account::query()->where('system_key', 'stock_input')->firstOrFail();
        $this->expenseAccount = Account::query()->where('code', '5204')->firstOrFail();
        $this->purchaseJournal = Journal::query()->where('code', 'PUR')->firstOrFail();

        Setting::query()->create([
            'type' => AccountingSettingRegistry::SETTING_TYPE,
            'data' => [
                'accounts_payable' => $this->payableAccount->id,
                'expenses_treatment' => PurchaseExpensesTreatment::LandedCost->value,
            ],
        ]);
    }

    private function createSupplier(?int $payableAccountId = null): Contact
    {
        return Contact::factory()->create([
            'name' => 'دار الشروق',
            'is_supplier' => true,
            'is_customer' => false,
            'is_active' => true,
            'payable_account_id' => $payableAccountId,
        ]);
    }

    private function createProduct(?ProductCategory $category = null): Product
    {
        return Product::factory()->create([
            'category_id' => $category?->id,
            'is_active' => true,
        ]);
    }

    public function test_creating_draft_purchase_invoice_creates_exactly_one_draft_journal_entry(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-30',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $invoiceId = (int) $res->json('data.id');
        $invoiceNumber = (string) $res->json('data.invoice_number');

        $this->assertSame(PurchaseInvoiceStatus::DRAFT->value, $res->json('data.status'));

        $entries = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->get();

        $this->assertCount(1, $entries);
        $entry = $entries->first();

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame($invoiceNumber, $entry->reference);
        $this->assertSame('2026-06-15', $entry->entry_date->toDateString());
        $this->assertSame($this->user->id, $entry->created_by);
        $this->assertNull($entry->posted_by);
        $this->assertNull($entry->posted_at);

        $debitLine = $entry->lines->firstWhere('account_id', $this->stockInputAccount->id);
        $creditLine = $entry->lines->firstWhere('account_id', $this->payableAccount->id);

        $this->assertNotNull($debitLine);
        $this->assertNotNull($creditLine);
        $this->assertSame('1000.00', $debitLine->debit);
        $this->assertSame('0.00', $debitLine->credit);
        $this->assertSame('0.00', $creditLine->debit);
        $this->assertSame('1000.00', $creditLine->credit);
    }

    public function test_missing_accounting_context_rolls_back_invoice_creation_completely(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2027-01-10',
            'due_date' => '2027-01-10',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 50],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_year_id']);

        $this->assertSame(0, PurchaseInvoice::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, InventoryDocument::query()->count());
    }

    public function test_updating_draft_invoice_updates_same_journal_entry_and_replaces_lines(): void
    {
        $supplier = $this->createSupplier();
        $product1 = $this->createProduct();
        $product2 = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product1->id, 'quantity' => 5, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $invoiceId = (int) $created->json('data.id');
        $originalEntryId = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->value('id');

        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-20',
            'due_date' => '2026-06-25',
            'items' => [
                ['product_id' => $product2->id, 'quantity' => 3, 'unit_price' => 200],
            ],
        ])->assertOk();

        $entries = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->get();

        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame($originalEntryId, $entry->id);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame('2026-06-20', $entry->entry_date->toDateString());
        $this->assertCount(2, $entry->lines);
        $this->assertSame('600.00', $entry->lines->firstWhere('account_id', $this->stockInputAccount->id)->debit);
        $this->assertSame('600.00', $entry->lines->firstWhere('account_id', $this->payableAccount->id)->credit);
    }

    public function test_document_discount_is_allocated_proportionally_with_remainder_absorption(): void
    {
        $currentAssets = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();
        $customClearing = Account::query()->create([
            'account_type_id' => $currentAssets->id,
            'code' => '1388',
            'name' => 'وسيط فئة ب',
            'is_active' => true,
        ]);

        $catA = ProductCategory::factory()->create(['stock_input_account_id' => $this->stockInputAccount->id]);
        $catB = ProductCategory::factory()->create(['stock_input_account_id' => $customClearing->id]);

        $prodA = $this->createProduct($catA);
        $prodB = $this->createProduct($catB);
        $supplier = $this->createSupplier();

        // 6000 + 4000 = 10000, document discount = 1000 => 5400 and 3600
        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'discount_type' => 'fixed',
            'discount_value' => 1000,
            'items' => [
                ['product_id' => $prodA->id, 'quantity' => 60, 'unit_price' => 100],
                ['product_id' => $prodB->id, 'quantity' => 40, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $res->json('data.id'))
            ->firstOrFail();

        $this->assertSame('5400.00', $entry->lines->firstWhere('account_id', $this->stockInputAccount->id)->debit);
        $this->assertSame('3600.00', $entry->lines->firstWhere('account_id', $customClearing->id)->debit);
        $this->assertSame('9000.00', $entry->lines->firstWhere('account_id', $this->payableAccount->id)->credit);
    }

    public function test_landed_cost_treatment_allocates_additional_expenses_into_stock_input(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'شحن', 'amount' => '250.00'],
            ],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $res->json('data.id'))
            ->firstOrFail();

        $this->assertCount(2, $entry->lines);
        $this->assertSame('1250.00', $entry->lines->firstWhere('account_id', $this->stockInputAccount->id)->debit);
        $this->assertSame('1250.00', $entry->lines->firstWhere('account_id', $this->payableAccount->id)->credit);
    }

    public function test_separate_expense_treatment_posts_expenses_to_configured_account_and_skips_when_zero(): void
    {
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'expenses_treatment' => PurchaseExpensesTreatment::SeparateExpense->value,
                'additional_expenses_account_id' => $this->expenseAccount->id,
            ],
        ])->assertOk();

        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        // With additional expenses -> 3 lines (Stock Input, Expense, Payable)
        $withExpenses = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'شحن', 'amount' => '250.00'],
            ],
        ])->assertCreated();

        $entryWithExp = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $withExpenses->json('data.id'))
            ->firstOrFail();

        $this->assertCount(3, $entryWithExp->lines);
        $this->assertSame('1000.00', $entryWithExp->lines->firstWhere('account_id', $this->stockInputAccount->id)->debit);
        $expenseLine = $entryWithExp->lines->firstWhere('account_id', $this->expenseAccount->id);
        $this->assertNotNull($expenseLine);
        $this->assertSame('250.00', $expenseLine->debit);
        $this->assertSame('شحن', $expenseLine->label);
        $this->assertSame('1250.00', $entryWithExp->lines->firstWhere('account_id', $this->payableAccount->id)->credit);

        // Without additional expenses -> only 2 lines (no zero expense line)
        $noExpenses = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $entryNoExp = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $noExpenses->json('data.id'))
            ->firstOrFail();

        $this->assertCount(2, $entryNoExp->lines);
        $this->assertNull($entryNoExp->lines->firstWhere('account_id', $this->expenseAccount->id));
    }

    public function test_separate_expense_without_valid_account_rejects_invoice_with_expenses(): void
    {
        Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->update([
            'data' => [
                'accounts_payable' => $this->payableAccount->id,
                'expenses_treatment' => PurchaseExpensesTreatment::SeparateExpense->value,
                'additional_expenses_account_id' => null,
            ],
        ]);

        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'شحن', 'amount' => '100.00'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['additional_expenses_account_id']);
    }

    public function test_posting_invoice_posts_linked_entry_and_prevents_duplicate_or_edits(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 4, 'unit_price' => 250],
            ],
        ])->assertCreated();

        $invoiceId = (int) $created->json('data.id');

        $posted = $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseInvoiceStatus::POSTED->value);

        $this->assertSame($this->user->id, $posted->json('data.posted_by.id'));
        $this->assertNotNull($posted->json('data.posted_at'));

        $entry = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);

        // Goods Receipt remains Draft (not posted)
        $gr = InventoryDocument::query()
            ->where('referenceable_type', 'purchase_invoice')
            ->where('referenceable_id', $invoiceId)
            ->firstOrFail();
        $this->assertSame(InventoryDocumentStatus::DRAFT, $gr->status);

        // Duplicate post rejected
        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        // Update, cancel, and delete rejected on posted invoice
        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable();

        $this->postJson("/api/purchase-invoices/{$invoiceId}/cancel")
            ->assertUnprocessable();

        $this->deleteJson("/api/purchase-invoices/{$invoiceId}")
            ->assertUnprocessable();
    }

    public function test_posting_invoice_succeeds_even_after_goods_receipt_is_posted(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $invoiceId = (int) $created->json('data.id');
        $grId = (int) $created->json('data.goods_receipt.id');

        $this->postJson("/api/inventory-documents/{$grId}/submit")->assertOk();
        $this->postJson("/api/inventory-documents/{$grId}/approve")->assertOk();
        $this->postJson("/api/inventory-documents/{$grId}/post")->assertOk();

        // Posting the invoice after GR posting still succeeds
        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseInvoiceStatus::POSTED->value);
    }

    public function test_failed_journal_posting_keeps_invoice_draft(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $invoiceId = (int) $created->json('data.id');

        // Close accounting period after creation
        $this->period->update(['status' => AccountingPeriodStatus::Closed->value]);

        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accounting_period_id']);

        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
        $this->assertSame(PurchaseInvoiceStatus::DRAFT, $invoice->status);
        $this->assertNull($invoice->posted_by);
        $this->assertNull($invoice->posted_at);
    }

    public function test_cancelling_or_deleting_draft_invoice_deletes_linked_draft_journal_entry(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $inv1 = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $inv1Id = (int) $inv1->json('data.id');
        $this->postJson("/api/purchase-invoices/{$inv1Id}/cancel")->assertOk();
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'purchase_invoice',
            'source_id' => $inv1Id,
        ]);

        $inv2 = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $inv2Id = (int) $inv2->json('data.id');
        $this->deleteJson("/api/purchase-invoices/{$inv2Id}")->assertOk();
        $this->assertDatabaseMissing('journal_entries', [
            'source_type' => 'purchase_invoice',
            'source_id' => $inv2Id,
        ]);
    }

    public function test_manual_update_delete_and_post_are_blocked_for_purchase_invoice_entries_only(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $created->json('data.id'))
            ->firstOrFail();

        $journal = Journal::query()->where('code', 'PUR')->firstOrFail();

        // Manual API actions blocked
        $this->putJson("/api/accounting/journal-entries/{$entry->id}", [
            'journal_id' => $journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2026-06-15',
            'lines' => [
                ['account_id' => $this->stockInputAccount->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $this->payableAccount->id, 'debit' => 0, 'credit' => 50],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['source']);

        $this->postJson("/api/accounting/journal-entries/{$entry->id}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source']);

        $this->deleteJson("/api/accounting/journal-entries/{$entry->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['source']);

        // Manual Draft entry still works
        $manualRes = $this->postJson('/api/accounting/journal-entries', [
            'journal_id' => $journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2026-06-15',
            'lines' => [
                ['account_id' => $this->stockInputAccount->id, 'debit' => 50, 'credit' => 0],
                ['account_id' => $this->payableAccount->id, 'debit' => 0, 'credit' => 50],
            ],
        ])->assertCreated();

        $manualId = (int) $manualRes->json('data.id');
        $this->postJson("/api/accounting/journal-entries/{$manualId}/post")->assertOk();

        // Other source type (createFromSource) still works
        $jeService = app(JournalEntryService::class);
        $otherEntry = $jeService->createFromSource([
            'journal_id' => $journal->id,
            'fiscal_year_id' => $this->fiscalYear->id,
            'accounting_period_id' => $this->period->id,
            'entry_date' => '2026-06-15',
            'lines' => [
                ['account_id' => $this->stockInputAccount->id, 'debit' => 75, 'credit' => 0],
                ['account_id' => $this->payableAccount->id, 'debit' => 0, 'credit' => 75],
            ],
        ], $product, $this->user);

        $this->assertSame(JournalEntryStatus::Posted, $otherEntry->status);
    }

    public function test_source_update_throws_if_called_for_mismatched_or_missing_entry(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $inv = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $invoiceModel = PurchaseInvoice::query()->findOrFail($inv->json('data.id'));

        $entry = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceModel->id)
            ->firstOrFail();
        $entry->lines()->delete();
        $entry->delete();

        $this->expectException(ValidationException::class);

        app(JournalEntryService::class)->postForSource($invoiceModel, $this->user);
    }

    public function test_journals_api_returns_only_active_purchase_journals(): void
    {
        $secondPurchase = Journal::query()->create([
            'code' => 'PUR2',
            'name' => 'مشتريات فرع 2',
            'type' => JournalType::Purchase->value,
            'is_active' => true,
        ]);

        $inactivePurchase = Journal::query()->create([
            'code' => 'PUR-OFF',
            'name' => 'مشتريات معطل',
            'type' => JournalType::Purchase->value,
            'is_active' => false,
        ]);

        $salesJournal = Journal::query()->where('code', 'SAL')->firstOrFail();

        $res = $this->getJson('/api/accounting/journals?type=purchase&is_active=1&per_page=100')
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertContains($this->purchaseJournal->id, $ids);
        $this->assertContains($secondPurchase->id, $ids);
        $this->assertNotContains($inactivePurchase->id, $ids);
        $this->assertNotContains($salesJournal->id, $ids);
    }

    public function test_invalid_journal_id_is_rejected_on_create_and_update(): void
    {
        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        $inactivePurchase = Journal::query()->create([
            'code' => 'PUR-INACT',
            'name' => 'مشتريات معطل',
            'type' => JournalType::Purchase->value,
            'is_active' => false,
        ]);
        $salesJournal = Journal::query()->where('code', 'SAL')->firstOrFail();

        // Missing journal_id
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);

        // Non-existent journal_id
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => 999999,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);

        // Inactive purchase journal
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $inactivePurchase->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);

        // Wrong journal type (Sales)
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $salesJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['journal_id']);
    }

    public function test_default_purchase_journal_setting_and_invoice_journal_lifecycle(): void
    {
        $secondPurchase = Journal::query()->create([
            'code' => 'PUR-B',
            'name' => 'دفتر مشتريات ب',
            'type' => JournalType::Purchase->value,
            'is_active' => true,
        ]);

        // Save default_purchase_journal_id in settings
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'default_purchase_journal_id' => $this->purchaseJournal->id,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.default_purchase_journal_id', $this->purchaseJournal->id);

        // Settings GET exposes default_purchase_journal_id and active purchase_journals
        $this->getJson('/api/settings/accounting')
            ->assertOk()
            ->assertJsonPath('data.default_purchase_journal_id', $this->purchaseJournal->id);

        $supplier = $this->createSupplier();
        $product = $this->createProduct();

        // User overrides default and selects secondPurchase on create
        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $secondPurchase->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.journal_id', $secondPurchase->id);

        $invoiceId = (int) $created->json('data.id');

        $entry = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();

        $this->assertSame($secondPurchase->id, $entry->journal_id);

        // Changing default in settings does NOT alter existing invoice's journal_id
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'default_purchase_journal_id' => null,
            ],
        ])->assertOk();

        $this->assertSame($secondPurchase->id, PurchaseInvoice::query()->findOrFail($invoiceId)->journal_id);

        // Updating draft invoice to purchaseJournal updates SAME journal entry
        $originalEntryId = $entry->id;
        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])
            ->assertOk()
            ->assertJsonPath('data.journal_id', $this->purchaseJournal->id);

        $entry->refresh();
        $this->assertSame($originalEntryId, $entry->id);
        $this->assertSame($this->purchaseJournal->id, $entry->journal_id);

        // Posting uses invoice's saved journal_id and blocks further journal_id changes
        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseInvoiceStatus::POSTED->value)
            ->assertJsonPath('data.journal_id', $this->purchaseJournal->id);

        $entry->refresh();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->purchaseJournal->id, $entry->journal_id);

        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'journal_id' => $secondPurchase->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])->assertUnprocessable();
    }

    public function test_multiple_products_sharing_same_account_generate_independent_debit_lines_with_product_labels(): void
    {
        $cat = ProductCategory::factory()->create(['stock_input_account_id' => $this->stockInputAccount->id]);
        $bookA = Product::factory()->create(['category_id' => $cat->id, 'name' => 'Book A', 'is_active' => true]);
        $bookB = Product::factory()->create(['category_id' => $cat->id, 'name' => 'Book B', 'is_active' => true]);
        $supplier = $this->createSupplier();

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $bookA->id, 'quantity' => 6, 'unit_price' => 100],
                ['product_id' => $bookB->id, 'quantity' => 4, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with(['lines' => static fn ($q) => $q->orderBy('line_number')])
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $res->json('data.id'))
            ->firstOrFail();

        // Two separate debit lines (same account 1303, never merged) + 1 payable credit line = 3 lines
        $this->assertCount(3, $entry->lines);
        $this->assertSame([1, 2, 3], $entry->lines->pluck('line_number')->all());

        $this->assertSame($this->stockInputAccount->id, $entry->lines[0]->account_id);
        $this->assertSame('Book A', $entry->lines[0]->label);
        $this->assertSame('600.00', $entry->lines[0]->debit);
        $this->assertSame('0.00', $entry->lines[0]->credit);

        $this->assertSame($this->stockInputAccount->id, $entry->lines[1]->account_id);
        $this->assertSame('Book B', $entry->lines[1]->label);
        $this->assertSame('400.00', $entry->lines[1]->debit);
        $this->assertSame('0.00', $entry->lines[1]->credit);

        $this->assertSame($this->payableAccount->id, $entry->lines[2]->account_id);
        $this->assertSame($supplier->name, $entry->lines[2]->label);
        $this->assertSame('0.00', $entry->lines[2]->debit);
        $this->assertSame('1000.00', $entry->lines[2]->credit);
    }

    public function test_stock_input_account_resolution_priority_and_lazy_default_validation(): void
    {
        $currentAssets = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();
        $fixedAssets = AccountType::query()->where('code', AccountTypeCode::FixedAssets->value)->firstOrFail();

        $customCatAccount = Account::query()->create([
            'account_type_id' => $currentAssets->id,
            'code' => '1381',
            'name' => 'وسيط فئة أ',
            'is_active' => true,
        ]);

        $customDefaultAccount = Account::query()->create([
            'account_type_id' => $currentAssets->id,
            'code' => '1382',
            'name' => 'وسيط افتراضي مخصص',
            'is_active' => true,
        ]);

        $inactiveAccount = Account::query()->create([
            'account_type_id' => $currentAssets->id,
            'code' => '1383',
            'name' => 'وسيط معطل',
            'is_active' => false,
        ]);

        $wrongTypeAccount = Account::query()->create([
            'account_type_id' => $fixedAssets->id,
            'code' => '1583',
            'name' => 'أصل ثابت غير متوافق',
            'is_active' => true,
        ]);

        $catWithAccount = ProductCategory::factory()->create(['stock_input_account_id' => $customCatAccount->id]);
        $catWithoutAccount = ProductCategory::factory()->create(['stock_input_account_id' => null]);

        $prodWithCat = Product::factory()->create(['category_id' => $catWithAccount->id, 'name' => 'كتاب الفئة', 'is_active' => true]);
        $prodNoCatAccount = Product::factory()->create(['category_id' => $catWithoutAccount->id, 'name' => 'كتاب الافتراضي', 'is_active' => true]);
        $supplier = $this->createSupplier();

        // Set custom default stock input account
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'default_stock_input_account_id' => $customDefaultAccount->id,
            ],
        ])->assertOk();

        // Category account takes priority over default; product without category account uses default
        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $prodWithCat->id, 'quantity' => 2, 'unit_price' => 150],
                ['product_id' => $prodNoCatAccount->id, 'quantity' => 1, 'unit_price' => 200],
            ],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with(['lines' => static fn ($q) => $q->orderBy('line_number')])
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $created->json('data.id'))
            ->firstOrFail();

        $this->assertSame($customCatAccount->id, $entry->lines[0]->account_id);
        $this->assertSame('300.00', $entry->lines[0]->debit);
        $this->assertSame($customDefaultAccount->id, $entry->lines[1]->account_id);
        $this->assertSame('200.00', $entry->lines[1]->debit);

        // Clear default_stock_input_account_id to null
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'default_stock_input_account_id' => null,
            ],
        ])->assertOk();

        // Missing default rejects when at least one item needs fallback
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $prodNoCatAccount->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['default_stock_input_account_id']);

        // When ALL items have valid category accounts, missing default succeeds (lazy resolution)
        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $prodWithCat->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertCreated();

        // Invalid (inactive / wrong type) category account never silently falls back to default
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'default_stock_input_account_id' => $customDefaultAccount->id,
            ],
        ])->assertOk();

        $catInactive = ProductCategory::factory()->create(['stock_input_account_id' => $inactiveAccount->id]);
        $prodInactiveCat = Product::factory()->create(['category_id' => $catInactive->id, 'is_active' => true]);

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $prodInactiveCat->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['stock_input_account_id']);

        $catWrongType = ProductCategory::factory()->create(['stock_input_account_id' => $wrongTypeAccount->id]);
        $prodWrongTypeCat = Product::factory()->create(['category_id' => $catWrongType->id, 'is_active' => true]);

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $prodWrongTypeCat->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['stock_input_account_id']);

        // Invalid default account in settings (inactive or wrong type) rejected when fallback required
        Setting::query()->where('type', AccountingSettingRegistry::SETTING_TYPE)->update([
            'data' => [
                'accounts_payable' => $this->payableAccount->id,
                'default_stock_input_account_id' => $inactiveAccount->id,
            ],
        ]);

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $prodNoCatAccount->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['default_stock_input_account_id']);
    }

    public function test_line_discounts_rounding_remainder_zero_lines_and_landed_cost_per_item(): void
    {
        $cat = ProductCategory::factory()->create(['stock_input_account_id' => $this->stockInputAccount->id]);
        $p1 = Product::factory()->create(['category_id' => $cat->id, 'name' => 'صنف 1', 'is_active' => true]);
        $p2 = Product::factory()->create(['category_id' => $cat->id, 'name' => 'صنف 2', 'is_active' => true]);
        $pZero = Product::factory()->create(['category_id' => $cat->id, 'name' => 'صنف مجاني', 'is_active' => true]);
        $supplier = $this->createSupplier();

        // p1: 100 - 10 = 90.00; p2: 100 - 0 = 100.00; pZero: 50 - 50 = 0.00 (100% item discount)
        // Net lines = 190.00; invoice discount = 10.00 => 180.00; landed cost = 19.00 => total = 199.00
        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'items' => [
                ['product_id' => $p1->id, 'quantity' => 1, 'unit_price' => 100, 'discount_type' => 'fixed', 'discount_value' => 10],
                ['product_id' => $p2->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $pZero->id, 'quantity' => 1, 'unit_price' => 50, 'discount_type' => 'fixed', 'discount_value' => 50],
            ],
            'expenses' => [
                ['name' => 'شحن', 'amount' => '19.00'],
            ],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with(['lines' => static fn ($q) => $q->orderBy('line_number')])
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $res->json('data.id'))
            ->firstOrFail();

        // Zero-value item pZero is NOT emitted as a zero-value journal line; p1 and p2 remain separate
        $this->assertCount(3, $entry->lines);
        $this->assertSame('صنف 1', $entry->lines[0]->label);
        $this->assertSame('صنف 2', $entry->lines[1]->label);
        $this->assertSame($supplier->name, $entry->lines[2]->label);

        $totalDebit = bcadd($entry->lines[0]->debit, $entry->lines[1]->debit, 2);
        $this->assertSame('199.00', $totalDebit);
        $this->assertSame('199.00', $entry->lines[2]->credit);
        $this->assertGreaterThan(0, (float) $entry->lines[0]->debit);
        $this->assertGreaterThan(0, (float) $entry->lines[1]->debit);
    }

    public function test_supplier_payable_account_and_missing_payable_account_validation(): void
    {
        $payableType = AccountType::query()->where('code', AccountTypeCode::Payable->value)->firstOrFail();
        $supplierPayable = Account::query()->create([
            'account_type_id' => $payableType->id,
            'code' => '2188',
            'name' => 'مورد خاص',
            'is_active' => true,
        ]);

        $supplier = $this->createSupplier($supplierPayable->id);
        $product = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $created->json('data.id'))
            ->firstOrFail();

        $this->assertSame('200.00', $entry->lines->firstWhere('account_id', $supplierPayable->id)->credit);

        // Supplier without payable_account_id AND accounts_payable cleared -> rejected
        $this->putJson('/api/settings/accounting', [
            'settings' => ['accounts_payable' => null],
        ])->assertOk();

        $supplierNoPayable = $this->createSupplier(null);

        $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplierNoPayable->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_three_equal_items_absorb_invoice_discount_and_landed_cost_rounding_remainder_exactly(): void
    {
        $cat = ProductCategory::factory()->create(['stock_input_account_id' => $this->stockInputAccount->id]);
        $p1 = Product::factory()->create(['category_id' => $cat->id, 'name' => 'Item 1', 'is_active' => true]);
        $p2 = Product::factory()->create(['category_id' => $cat->id, 'name' => 'Item 2', 'is_active' => true]);
        $p3 = Product::factory()->create(['category_id' => $cat->id, 'name' => 'Item 3', 'is_active' => true]);
        $supplier = $this->createSupplier();

        // 3 equal items of 100.00 = 300.00; invoice discount = 100.00 => 33.33 + 33.33 + 33.34 (remainder 0.01 absorbed by last)
        // Net after discount: 66.67, 66.67, 66.66 => sum = 200.00
        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'discount_type' => 'fixed',
            'discount_value' => 100,
            'items' => [
                ['product_id' => $p1->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $p2->id, 'quantity' => 1, 'unit_price' => 100],
                ['product_id' => $p3->id, 'quantity' => 1, 'unit_price' => 100],
            ],
        ])->assertCreated();

        $entry = JournalEntry::query()
            ->with(['lines' => static fn ($q) => $q->orderBy('line_number')])
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $res->json('data.id'))
            ->firstOrFail();

        $this->assertCount(4, $entry->lines);
        $this->assertSame('66.67', $entry->lines[0]->debit);
        $this->assertSame('66.67', $entry->lines[1]->debit);
        $this->assertSame('66.66', $entry->lines[2]->debit);
        $this->assertSame('200.00', $entry->lines[3]->credit);
    }

    public function test_separate_expense_uses_expense_names_as_line_labels_and_updates_on_edit(): void
    {
        $this->putJson('/api/settings/accounting', [
            'settings' => [
                'expenses_treatment' => PurchaseExpensesTreatment::SeparateExpense->value,
                'additional_expenses_account_id' => $this->expenseAccount->id,
            ],
        ])->assertOk();

        $supplier = $this->createSupplier();
        $product = Product::factory()->create(['name' => 'كتاب الفقه', 'is_active' => true]);

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'شحن سريع', 'amount' => '50.00'],
                ['name' => 'مصروف صفري', 'amount' => '0.00'],
                ['name' => 'تحميل وتفريغ', 'amount' => '25.00'],
            ],
        ])->assertCreated();

        $invoiceId = $res->json('data.id');
        $entry = JournalEntry::query()
            ->with(['lines' => static fn ($q) => $q->orderBy('line_number')])
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();

        // 1 item + 2 positive expenses + 1 payable = 4 lines (zero expense row skipped)
        $this->assertCount(4, $entry->lines);
        $this->assertSame('كتاب الفقه', $entry->lines[0]->label);
        $this->assertSame('200.00', $entry->lines[0]->debit);
        $this->assertSame($this->expenseAccount->id, $entry->lines[1]->account_id);
        $this->assertSame('شحن سريع', $entry->lines[1]->label);
        $this->assertSame('50.00', $entry->lines[1]->debit);
        $this->assertSame($this->expenseAccount->id, $entry->lines[2]->account_id);
        $this->assertSame('تحميل وتفريغ', $entry->lines[2]->label);
        $this->assertSame('25.00', $entry->lines[2]->debit);
        $this->assertSame('275.00', $entry->lines[3]->credit);

        // Update expenses and verify the synced journal entry picks up the updated expense name & amount
        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100],
            ],
            'expenses' => [
                ['name' => 'نقل وتخليص', 'amount' => '75.00'],
            ],
        ])->assertOk();

        $entry->refresh()->load(['lines' => static fn ($q) => $q->orderBy('line_number')]);
        $this->assertCount(3, $entry->lines);
        $this->assertSame($this->expenseAccount->id, $entry->lines[1]->account_id);
        $this->assertSame('نقل وتخليص', $entry->lines[1]->label);
        $this->assertSame('75.00', $entry->lines[1]->debit);
        $this->assertSame('275.00', $entry->lines[2]->credit);
    }

    public function test_posting_synchronizes_existing_draft_journal_entry_with_updated_category_mapping(): void
    {
        $category = ProductCategory::factory()->create([
            'stock_input_account_id' => $this->stockInputAccount->id,
        ]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'كتاب المرافعات',
            'is_active' => true,
        ]);
        $supplier = $this->createSupplier();

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 150],
            ],
        ])->assertCreated();

        $invoiceId = $res->json('data.id');

        $draftEntry = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Draft, $draftEntry->status);
        $originalEntryId = $draftEntry->id;
        $originalEntryNumber = $draftEntry->entry_number;

        // Create a new active CurrentAssets account and reassign the category's stock input account.
        $currentAssetsType = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();
        $updatedStockAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1149',
            'name' => 'مخزون الكتب القانونية المحدث',
            'is_active' => true,
        ]);
        $category->update(['stock_input_account_id' => $updatedStockAccount->id]);

        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertOk()
            ->assertJsonPath('data.status', PurchaseInvoiceStatus::POSTED->value);

        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', 'purchase_invoice')
                ->where('source_id', $invoiceId)
                ->count()
        );

        $postedEntry = JournalEntry::query()
            ->with(['lines' => static fn ($q) => $q->orderBy('line_number')])
            ->findOrFail($originalEntryId);

        $this->assertSame($originalEntryId, $postedEntry->id);
        $this->assertSame($originalEntryNumber, $postedEntry->entry_number);
        $this->assertSame(JournalEntryStatus::Posted, $postedEntry->status);
        $this->assertNotNull($postedEntry->posted_at);
        $this->assertCount(2, $postedEntry->lines);
        $this->assertSame($updatedStockAccount->id, $postedEntry->lines[0]->account_id);
        $this->assertSame('300.00', $postedEntry->lines[0]->debit);
        $this->assertSame($this->payableAccount->id, $postedEntry->lines[1]->account_id);
        $this->assertSame('300.00', $postedEntry->lines[1]->credit);
    }

    public function test_posting_with_invalid_mapping_fails_and_rolls_back_invoice_and_journal_entry_to_draft(): void
    {
        $currentAssetsType = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();
        $categoryAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1148',
            'name' => 'حساب مخزون مخصص',
            'is_active' => true,
        ]);

        $category = ProductCategory::factory()->create([
            'stock_input_account_id' => $categoryAccount->id,
        ]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'كتاب الدستور',
            'is_active' => true,
        ]);
        $supplier = $this->createSupplier();

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 200],
            ],
        ])->assertCreated();

        $invoiceId = $res->json('data.id');
        $entry = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();

        $this->assertSame(JournalEntryStatus::Draft, $entry->status);

        // Invalidate the accounting mapping by deactivating the category's stock input account.
        $categoryAccount->update(['is_active' => false]);

        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_input_account_id']);

        $invoice = PurchaseInvoice::query()->findOrFail($invoiceId);
        $entry->refresh();

        $this->assertSame(PurchaseInvoiceStatus::DRAFT, $invoice->status);
        $this->assertNull($invoice->posted_by);
        $this->assertNull($invoice->posted_at);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertNull($entry->posted_by);
        $this->assertNull($entry->posted_at);
    }

    public function test_build_entry_payload_loads_nested_product_and_category_when_items_already_loaded(): void
    {
        $currentAssetsType = AccountType::query()->where('code', AccountTypeCode::CurrentAssets->value)->firstOrFail();
        $customCategoryAccount = Account::query()->create([
            'account_type_id' => $currentAssetsType->id,
            'code' => '1147',
            'name' => 'مخزون كتب متخصصة',
            'is_active' => true,
        ]);

        $category = ProductCategory::factory()->create([
            'stock_input_account_id' => $customCategoryAccount->id,
        ]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'موسوعة القانون',
            'is_active' => true,
        ]);
        $supplier = $this->createSupplier();

        $res = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplier->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 125],
            ],
        ])->assertCreated();

        $invoiceId = $res->json('data.id');

        /** @var PurchaseInvoice $invoiceWithItemsOnly */
        $invoiceWithItemsOnly = PurchaseInvoice::query()
            ->with('items')
            ->findOrFail($invoiceId);

        $this->assertTrue($invoiceWithItemsOnly->relationLoaded('items'));
        $this->assertFalse($invoiceWithItemsOnly->items->first()->relationLoaded('product'));

        /** @var SyncPurchaseInvoiceJournalEntry $listener */
        $listener = app(SyncPurchaseInvoiceJournalEntry::class);
        $payload = $listener->buildEntryPayload($invoiceWithItemsOnly);

        $this->assertTrue($invoiceWithItemsOnly->items->first()->relationLoaded('product'));
        $this->assertTrue($invoiceWithItemsOnly->items->first()->product->relationLoaded('category'));
        $this->assertSame($customCategoryAccount->id, $payload['lines'][0]['account_id']);
        $this->assertSame('250.00', $payload['lines'][0]['debit']);
        $this->assertSame($this->payableAccount->id, $payload['lines'][1]['account_id']);
        $this->assertSame('250.00', $payload['lines'][1]['credit']);
    }

    public function test_purchase_invoice_journal_entry_persists_and_retains_supplier_contact_id_across_lifecycle(): void
    {
        $supplierA = $this->createSupplier();
        $supplierB = $this->createSupplier();
        $product = $this->createProduct();

        $created = $this->postJson('/api/purchase-invoices', [
            'supplier_id' => $supplierA->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertCreated();

        $invoiceId = $created->json('data.id');
        $entry = JournalEntry::query()
            ->where('source_type', 'purchase_invoice')
            ->where('source_id', $invoiceId)
            ->firstOrFail();

        $this->assertSame($supplierA->id, $entry->contact_id);
        $this->assertSame($supplierA->id, $entry->contact?->id);
        $originalEntryId = $entry->id;
        $originalEntryNumber = $entry->entry_number;

        // Update Draft invoice to a different supplier
        $this->putJson("/api/purchase-invoices/{$invoiceId}", [
            'supplier_id' => $supplierB->id,
            'journal_id' => $this->purchaseJournal->id,
            'invoice_date' => '2026-06-15',
            'due_date' => '2026-06-15',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertOk();

        $entry->refresh();
        $this->assertSame($originalEntryId, $entry->id);
        $this->assertSame($originalEntryNumber, $entry->entry_number);
        $this->assertSame($supplierB->id, $entry->contact_id);

        // Post invoice and confirm contact_id and identity are preserved
        $this->postJson("/api/purchase-invoices/{$invoiceId}/post")->assertOk();

        $entry->refresh();
        $this->assertSame($originalEntryId, $entry->id);
        $this->assertSame($originalEntryNumber, $entry->entry_number);
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($supplierB->id, $entry->contact_id);
    }
}
