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
        if (! Schema::hasColumn('purchase_orders', 'additional_expenses_total')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->decimal('additional_expenses_total', 15, 2)->default(0)->after('discount_amount');
            });
        }

        if (! Schema::hasTable('purchase_order_expenses')) {
            Schema::create('purchase_order_expenses', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
                $table->string('name', 255);
                $table->decimal('amount', 15, 2);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_expenses');

        if (Schema::hasColumn('purchase_orders', 'additional_expenses_total')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropColumn('additional_expenses_total');
            });
        }
    }
};
