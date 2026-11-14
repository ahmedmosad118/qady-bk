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
        Schema::create('pos_held_sales', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_note', 100);

            $table->foreignId('shift_id')
                ->constrained('pos_shifts')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('contacts')
                ->nullOnDelete();

            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('invoice_discount_type', 20)->nullable();
            $table->decimal('invoice_discount_value', 15, 2)->nullable();
            $table->decimal('invoice_discount_amount', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('shift_id', 'idx_pos_held_sales_shift');
        });

        Schema::create('pos_held_sale_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('pos_held_sale_id')
                ->constrained('pos_held_sales')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('total', 15, 2);

            $table->timestamps();

            $table->unique(['pos_held_sale_id', 'product_id'], 'uq_pos_held_items_sale_prod');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_held_sale_items');
        Schema::dropIfExists('pos_held_sales');
    }
};
