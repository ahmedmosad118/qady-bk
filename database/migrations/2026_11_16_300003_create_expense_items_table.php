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
        Schema::create('expense_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_id')
                ->constrained('expenses')
                ->cascadeOnDelete();
            $table->foreignId('account_id')
                ->nullable()
                ->constrained('accounts')
                ->restrictOnDelete();
            $table->foreignId('expense_category_id')
                ->constrained('expense_categories')
                ->restrictOnDelete();
            $table->string('reference_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index('expense_id');
            $table->index('account_id');
            $table->index('expense_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_items');
    }
};
