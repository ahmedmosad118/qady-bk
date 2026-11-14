<?php

declare(strict_types=1);

use App\Enums\StocktakeItemStatus;
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
        Schema::create('stocktake_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stocktake_id')
                ->constrained('stocktakes')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('system_quantity', 15, 3)->nullable();
            $table->decimal('counted_quantity', 15, 3)->nullable();

            $table->enum('count_status', StocktakeItemStatus::values())->default(StocktakeItemStatus::PENDING->value);

            $table->foreignId('count_started_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('count_started_at')->nullable();

            $table->foreignId('counted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('counted_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes per specification
            $table->unique(['stocktake_id', 'product_id'], 'uniq_stocktake_items_stocktake_product');
            $table->index('product_id', 'idx_stocktake_items_product');
            $table->index(['stocktake_id', 'count_status'], 'idx_stocktake_items_stocktake_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocktake_items');
    }
};
