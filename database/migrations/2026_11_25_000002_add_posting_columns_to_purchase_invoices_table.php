<?php

declare(strict_types=1);

use App\Enums\PurchaseInvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            $table->enum('status', PurchaseInvoiceStatus::values())
                ->default(PurchaseInvoiceStatus::DRAFT->value)
                ->change();

            if (! Schema::hasColumn('purchase_invoices', 'posted_by')) {
                $table->foreignId('posted_by')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('purchase_invoices', 'posted_at')) {
                $table->timestamp('posted_at')
                    ->nullable()
                    ->after('posted_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            return;
        }

        if (Schema::hasColumn('purchase_invoices', 'posted_at')) {
            Schema::table('purchase_invoices', function (Blueprint $table): void {
                $table->dropColumn('posted_at');
            });
        }

        if (Schema::hasColumn('purchase_invoices', 'posted_by')) {
            $foreignKeys = Schema::getForeignKeys('purchase_invoices');
            foreach ($foreignKeys as $fk) {
                if (in_array('posted_by', $fk['columns'], true)) {
                    Schema::table('purchase_invoices', function (Blueprint $table) use ($fk): void {
                        $table->dropForeign($fk['name']);
                    });
                }
            }

            Schema::table('purchase_invoices', function (Blueprint $table): void {
                $table->dropColumn('posted_by');
            });
        }
    }
};
