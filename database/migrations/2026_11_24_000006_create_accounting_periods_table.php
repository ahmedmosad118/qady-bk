<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_periods')) {
            Schema::create('accounting_periods', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('fiscal_year_id')
                    ->constrained('fiscal_years')
                    ->restrictOnDelete();
                $table->string('name', 100);
                $table->unsignedSmallInteger('period_number');
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('open');
                $table->timestamp('closed_at')->nullable();
                $table->foreignId('closed_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamps();

                $table->unique(['fiscal_year_id', 'period_number'], 'uq_accounting_periods_fy_number');
                $table->index(['fiscal_year_id', 'start_date', 'end_date'], 'idx_accounting_periods_fy_dates');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};
