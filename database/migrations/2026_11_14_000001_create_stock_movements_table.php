<?php

declare(strict_types=1);

use App\Enums\StockMovementDirection;
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
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('inventory_document_id')
                ->constrained('inventory_documents')
                ->restrictOnDelete();

            $table->foreignId('inventory_document_item_id')
                ->constrained('inventory_document_items')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->enum('direction', StockMovementDirection::values());
            $table->decimal('quantity', 15, 3);
            $table->date('movement_date');

            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('stock_movements')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            // Unique composite index: prevents duplicating movement of the same item in the same warehouse and direction
            $table->unique(
                ['inventory_document_item_id', 'warehouse_id', 'direction'],
                'uq_stock_movements_item_wh_dir'
            );

            // Unique index on reversal_of_id: prevents reversing the same original movement more than once
            $table->unique('reversal_of_id', 'uq_stock_movements_reversal_of');

            // Query optimization indexes
            $table->index(['warehouse_id', 'product_id', 'movement_date'], 'idx_stock_mov_wh_prod_date');
            $table->index(['warehouse_id', 'movement_date'], 'idx_stock_mov_wh_date');
            $table->index(['product_id', 'movement_date'], 'idx_stock_mov_prod_date');
            $table->index('inventory_document_id', 'idx_stock_mov_document');
        });

        // Add CHECK constraint if supported by driver
        if (in_array(DB::getDriverName(), ['mysql', 'pgsql'], true)) {
            try {
                DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements_positive_qty CHECK (quantity > 0)');
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
        Schema::dropIfExists('stock_movements');
    }
};
