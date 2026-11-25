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
        if (! Schema::hasColumn('journal_entries', 'contact_id')) {
            return;
        }

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['contact_id']);
            $table->dropIndex('journal_entries_contact_id_index');
            $table->dropColumn('contact_id');
        });
    }

    public function backfillPurchaseInvoiceContacts(): void
    {
        $sources = array_values(array_unique(array_filter([
            'purchase_invoice',
            PurchaseInvoice::class,
            Relation::getMorphedModel('purchase_invoice'),
        ])));

        $entries = DB::table('journal_entries')
            ->whereNull('contact_id')
            ->whereIn('source_type', $sources)
            ->whereNotNull('source_id')
            ->get(['id', 'source_id']);

        if ($entries->isEmpty()) {
            return;
        }

        $suppliers = DB::table('purchase_invoices')
            ->whereIn('id', $entries->pluck('source_id')->unique()->all())
            ->pluck('supplier_id', 'id');

        foreach ($entries as $entry) {
            if (($supplierId = $suppliers->get($entry->source_id)) !== null) {
                DB::table('journal_entries')
                    ->where('id', $entry->id)
                    ->whereNull('contact_id')
                    ->update(['contact_id' => (int) $supplierId]);
            }
        }
    }
};
