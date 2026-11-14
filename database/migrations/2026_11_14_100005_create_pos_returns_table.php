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
        Schema::create('pos_returns', function (Blueprint $table): void {
            $table->id();
            $table->string('return_number', 50)->unique();

            $table->foreignId('pos_sale_id')
                ->constrained('pos_sales')
                ->restrictOnDelete();

            $table->foreignId('shift_id')
                ->constrained('pos_shifts')
                ->restrictOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->decimal('total_returned', 15, 2);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('returned_at')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->index('pos_sale_id', 'idx_pos_returns_sale');
            $table->index('shift_id', 'idx_pos_returns_shift');
            $table->index('payment_method_id', 'idx_pos_returns_payment_method');
            $table->index('returned_at', 'idx_pos_returns_returned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_returns');
    }
};
