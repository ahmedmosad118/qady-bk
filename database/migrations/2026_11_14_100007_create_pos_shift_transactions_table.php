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
        Schema::create('pos_shift_transactions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shift_id')
                ->constrained('pos_shifts')
                ->restrictOnDelete();

            $table->string('type', 30); // cash_sale, cash_return, expense, withdrawal
            $table->decimal('amount', 15, 2);

            $table->foreignId('pos_sale_id')
                ->nullable()
                ->unique()
                ->constrained('pos_sales')
                ->restrictOnDelete();

            $table->foreignId('pos_return_id')
                ->nullable()
                ->unique()
                ->constrained('pos_returns')
                ->restrictOnDelete();

            $table->string('name')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('shift_id', 'idx_pos_shift_tx_shift');
            $table->index('type', 'idx_pos_shift_tx_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_shift_transactions');
    }
};
