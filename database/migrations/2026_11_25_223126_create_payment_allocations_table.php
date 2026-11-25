<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_allocations')) {
            Schema::create('payment_allocations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payment_id')
                    ->constrained('payments')
                    ->restrictOnDelete();
                $table->string('allocatable_type', 255);
                $table->unsignedBigInteger('allocatable_id');
                $table->decimal('allocated_amount', 15, 2);
                $table->timestamps();

                $table->unique(
                    ['payment_id', 'allocatable_type', 'allocatable_id'],
                    'payment_allocations_payment_target_unique'
                );
                $table->index(
                    ['allocatable_type', 'allocatable_id'],
                    'payment_allocations_allocatable_index'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
