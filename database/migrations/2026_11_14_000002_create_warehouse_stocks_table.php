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
        Schema::create('warehouse_stocks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 3)->default(0);
            $table->timestamps();

            // Unique composite index: exactly one stock record per warehouse and product
            $table->unique(['warehouse_id', 'product_id'], 'uq_warehouse_stocks_wh_prod');

            // Index to query product stock across all warehouses
            $table->index('product_id', 'idx_warehouse_stocks_product');
        });

        // Add CHECK constraint if supported by driver
        if (in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            try {
                DB::statement('ALTER TABLE warehouse_stocks ADD CONSTRAINT chk_warehouse_stocks_non_negative CHECK (quantity >= 0)');
            } catch (\Throwable) {
                // Ignore if unsupported by engine version
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_stocks');
    }
};
