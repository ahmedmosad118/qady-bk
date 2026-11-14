<?php

declare(strict_types=1);

use App\Enums\StocktakeScope;
use App\Enums\StocktakeStatus;
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
        Schema::create('stocktakes', function (Blueprint $table): void {
            $table->id();
            $table->string('stocktake_number', 100)->unique();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->enum('scope', StocktakeScope::values());
            $table->enum('status', StocktakeStatus::values())->default(StocktakeStatus::DRAFT->value);
            $table->date('stocktake_date');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('started_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('started_at')->nullable();

            $table->foreignId('submitted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('rejected_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('completed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Composite indexes per specification
            $table->index(['warehouse_id', 'status'], 'idx_stocktakes_warehouse_status');
            $table->index(['warehouse_id', 'stocktake_date'], 'idx_stocktakes_warehouse_date');
            $table->index(['status', 'stocktake_date'], 'idx_stocktakes_status_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocktakes');
    }
};
