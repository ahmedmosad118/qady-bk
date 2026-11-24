<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            Schema::create('journal_entries', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('journal_id')
                    ->constrained('journals')
                    ->restrictOnDelete();
                $table->foreignId('fiscal_year_id')
                    ->constrained('fiscal_years')
                    ->restrictOnDelete();
                $table->foreignId('accounting_period_id')
                    ->constrained('accounting_periods')
                    ->restrictOnDelete();
                $table->string('entry_number', 50);
                $table->date('entry_date');
                $table->string('reference', 100)->nullable();
                $table->text('description')->nullable();
                $table->string('source_type', 255)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('status', 20)->default('draft');
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('posted_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->index('entry_date', 'journal_entries_entry_date_index');
                $table->unique(['source_type', 'source_id'], 'journal_entries_source_unique');
                $table->index('status', 'journal_entries_status_index');
                $table->index('journal_id', 'journal_entries_journal_id_index');
                $table->index('fiscal_year_id', 'journal_entries_fiscal_year_id_index');
                $table->index('accounting_period_id', 'journal_entries_accounting_period_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
