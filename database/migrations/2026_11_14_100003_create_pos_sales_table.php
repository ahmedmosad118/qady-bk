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
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_number', 50)->unique();

            $table->foreignId('shift_id')
                ->constrained('pos_shifts')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();

            $table->foreignId('payment_method_id')
                ->constrained('payment_methods')
                ->restrictOnDelete();

            $table->decimal('subtotal', 15, 2);
            $table->decimal('items_discount_total', 15, 2)->default(0);
            $table->string('invoice_discount_type', 20)->nullable();
            $table->decimal('invoice_discount_value', 15, 2)->nullable();
            $table->decimal('invoice_discount_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('sold_at')->useCurrent();
            $table->string('client_uuid', 36)->nullable()->unique();
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_pos_sales_shift');
            $table->index('customer_id', 'idx_pos_sales_customer');
            $table->index('payment_method_id', 'idx_pos_sales_payment_method');
            $table->index('sold_at', 'idx_pos_sales_sold_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};
