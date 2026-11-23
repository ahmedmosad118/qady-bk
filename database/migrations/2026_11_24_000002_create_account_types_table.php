<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_types')) {
            Schema::create('account_types', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_class_id')
                    ->constrained('account_classes')
                    ->restrictOnDelete();
                $table->string('code', 50)->unique();
                $table->string('name', 100);
                $table->boolean('include_in_balance_sheet')->default(false);
                $table->boolean('include_in_profit_loss')->default(false);
                $table->smallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_types');
    }
};
