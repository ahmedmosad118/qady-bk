<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entry_lines')) {
            Schema::create('journal_entry_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('journal_entry_id')
                    ->constrained('journal_entries')
                    ->restrictOnDelete();
                $table->foreignId('account_id')
                    ->constrained('accounts')
                    ->restrictOnDelete();
                $table->unsignedSmallInteger('line_number');
                $table->string('label', 255)->nullable();
                $table->decimal('debit', 18, 2)->default(0);
                $table->decimal('credit', 18, 2)->default(0);
                $table->timestamps();

                $table->index('journal_entry_id', 'journal_entry_lines_journal_entry_id_index');
                $table->index('account_id', 'journal_entry_lines_account_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
