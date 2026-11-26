<?php

declare(strict_types=1);

use App\Enums\ExpenseStatus;
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
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('expense_number', 100)->unique();
            $table->foreignId('contact_id')
                ->nullable()
                ->constrained('contacts')
                ->restrictOnDelete();
            $table->foreignId('journal_id')
                ->nullable()
                ->constrained('journals')
                ->restrictOnDelete();
            $table->date('expense_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->string('status', 20)->default(ExpenseStatus::DRAFT->value);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index('expense_date');
            $table->index('status');
            $table->index('contact_id');
            $table->index('journal_id');
            $table->index('created_by');
            $table->index('posted_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
