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
        Schema::create('pos_sync_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('client_uuid', 36)->unique();

            $table->foreignId('shift_id')
                ->nullable()
                ->constrained('pos_shifts')
                ->nullOnDelete();

            $table->foreignId('pos_sale_id')
                ->nullable()
                ->constrained('pos_sales')
                ->nullOnDelete();

            $table->json('payload');
            $table->string('status', 20); // synced, conflict
            $table->text('conflict_reason')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('status', 'idx_pos_sync_tx_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_sync_transactions');
    }
};
