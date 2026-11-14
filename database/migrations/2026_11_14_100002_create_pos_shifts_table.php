<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->string('shift_number', 50)->unique();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('expected_cash', 15, 2)->default(0);
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();

            $table->foreignId('opened_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('opened_at')->useCurrent();

            $table->foreignId('closed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('closed_at', 'idx_pos_shifts_closed_at');
            $table->index('opened_by', 'idx_pos_shifts_opened_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};
