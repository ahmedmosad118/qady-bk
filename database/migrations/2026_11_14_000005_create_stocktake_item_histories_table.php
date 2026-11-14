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
        Schema::create('stocktake_item_histories', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stocktake_id')
                ->constrained('stocktakes')
                ->cascadeOnDelete();

            $table->foreignId('stocktake_item_id')
                ->nullable()
                ->constrained('stocktake_items')
                ->cascadeOnDelete();

            $table->string('action', 50);
            $table->decimal('previous_quantity', 15, 3)->nullable();
            $table->decimal('new_quantity', 15, 3)->nullable();
            $table->string('previous_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->text('reason')->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['stocktake_id', 'created_at'], 'idx_stocktake_histories_stocktake_time');
            $table->index(['stocktake_item_id', 'created_at'], 'idx_stocktake_histories_item_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocktake_item_histories');
    }
};
