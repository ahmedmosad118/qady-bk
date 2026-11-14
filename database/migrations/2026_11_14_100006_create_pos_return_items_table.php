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
        Schema::create('pos_return_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('pos_return_id')
                ->constrained('pos_returns')
                ->cascadeOnDelete();

            $table->foreignId('pos_sale_item_id')
                ->constrained('pos_sale_items')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('allocated_discount', 15, 2)->default(0);
            $table->decimal('refund_amount', 15, 2);

            $table->timestamps();

            // Indexes
            $table->index('pos_return_id', 'idx_pos_return_items_return');
            $table->index('pos_sale_item_id', 'idx_pos_return_items_sale_item');
            $table->index('product_id', 'idx_pos_return_items_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_return_items');
    }
};
