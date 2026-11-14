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
        Schema::create('pos_sale_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('pos_sales')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->json('product_snapshot');
            $table->decimal('quantity', 15, 3);
            $table->decimal('original_unit_price', 15, 2);
            $table->decimal('unit_price', 15, 2);
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('total', 15, 2);
            $table->decimal('allocated_invoice_discount', 15, 2)->default(0);

            $table->timestamps();

            // Unique index: same product appears once per sale
            $table->unique(['sale_id', 'product_id'], 'uq_pos_sale_items_sale_prod');
            $table->index('sale_id', 'idx_pos_sale_items_sale');
            $table->index('product_id', 'idx_pos_sale_items_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_sale_items');
    }
};
