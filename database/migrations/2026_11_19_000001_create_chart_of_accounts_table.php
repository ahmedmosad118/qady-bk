<?php

declare(strict_types=1);

use App\Enums\AccountType;
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
        if (! Schema::hasTable('chart_of_accounts')) {
            Schema::create('chart_of_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('parent_id')
                    ->nullable()
                    ->constrained('chart_of_accounts')
                    ->restrictOnDelete();
                $table->string('code', 100)->unique();
                $table->string('name', 255);
                $table->enum('account_type', AccountType::values());
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index('account_type');
                $table->index('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
