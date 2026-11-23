<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('account_type_id')
                    ->constrained('account_types')
                    ->restrictOnDelete();
                $table->string('code', 30)->unique();
                $table->string('name', 150);
                $table->string('normal_balance_override', 10)->nullable();
                $table->boolean('reconcile')->default(false);
                $table->boolean('is_active')->default(true);
                $table->boolean('is_system')->default(false);
                $table->string('system_key', 100)->nullable()->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
