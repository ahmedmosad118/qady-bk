<?php

declare(strict_types=1);

use App\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        if (! Schema::hasColumn('journal_entries', 'contact_id')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                $table->foreignId('contact_id')
                    ->nullable()
                    ->after('accounting_period_id')
                    ->constrained('contacts')
                    ->restrictOnDelete();

                $table->index('contact_id', 'journal_entries_contact_id_index');
            });
        }

        $this->backfillPurchaseInvoiceContacts();
    }

    public function down(): void
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasColumn('journal_entries', 'contact_id')) {
            return;
        }

        $foreignKeys = Schema::getForeignKeys('journal_entries');
        foreach ($foreignKeys as $fk) {
            if (in_array('contact_id', $fk['columns'], true)) {
                Schema::table('journal_entries', function (Blueprint $table) use ($fk): void {
                    $table->dropForeign($fk['name']);
                });
            }
        }

        $indexes = Schema::getIndexes('journal_entries');
        foreach ($indexes as $index) {
            if ($index['primary']) {
                continue;
            }
            if ($index['columns'] === ['contact_id']) {
                Schema::table('journal_entries', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index['name']);
                });
            }
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropColumn('contact_id');
        });
    }

    private function backfillPurchaseInvoiceContacts(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        $purchaseInvoiceSources = array_values(array_unique(array_filter([
            'purchase_invoice',
            PurchaseInvoice::class,
            Relation::getMorphedModel('purchase_invoice'),
        ])));

        $entries = DB::table('journal_entries')
            ->whereNull('contact_id')
            ->whereIn('source_type', $purchaseInvoiceSources)
            ->whereNotNull('source_id')
            ->get(['id', 'source_id']);

        if ($entries->isEmpty()) {
            return;
        }

        $invoiceIds = $entries->pluck('source_id')->unique()->values()->all();
        $suppliersByInvoiceId = DB::table('purchase_invoices')
            ->whereIn('id', $invoiceIds)
            ->pluck('supplier_id', 'id');

        foreach ($entries as $entry) {
            $supplierId = $suppliersByInvoiceId->get($entry->source_id);
            if ($supplierId !== null) {
                DB::table('journal_entries')
                    ->where('id', $entry->id)
                    ->whereNull('contact_id')
                    ->update(['contact_id' => (int) $supplierId]);
            }
        }
    }
};
