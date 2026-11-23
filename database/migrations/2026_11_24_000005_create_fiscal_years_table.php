<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_years')) {
            Schema::create('fiscal_years', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 30)->unique();
                $table->string('name', 100);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('draft');
                $table->timestamps();

                $table->index(['start_date', 'end_date'], 'idx_fiscal_years_dates');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
