<?php

declare(strict_types=1);

use App\Enums\Payment\PaymentStatus;
use App\Enums\Payment\PaymentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table): void {
                $table->id();
                $table->string('payment_number', 50)->unique();
                $table->string('payment_type', 30)->default(PaymentType::SEND->value);
                $table->string('related_type', 20)->nullable();
                $table->foreignId('contact_id')
                    ->nullable()
                    ->constrained('contacts')
                    ->restrictOnDelete();
                $table->foreignId('account_id')
                    ->nullable()
                    ->constrained('accounts')
                    ->restrictOnDelete();
                $table->foreignId('payment_method_id')
                    ->constrained('payment_methods')
                    ->restrictOnDelete();
                $table->foreignId('journal_id')
                    ->constrained('journals')
                    ->restrictOnDelete();
                $table->date('payment_date');
                $table->decimal('amount', 15, 2);
                $table->string('reference', 100)->nullable();
                $table->text('description')->nullable();
                $table->string('status', 20)->default(PaymentStatus::DRAFT->value);
                $table->foreignId('created_by')
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->foreignId('posted_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('cancelled_by')
                    ->nullable()
                    ->constrained('users')
                    ->restrictOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->index('payment_type', 'payments_payment_type_index');
                $table->index('related_type', 'payments_related_type_index');
                $table->index('status', 'payments_status_index');
                $table->index('payment_date', 'payments_payment_date_index');
                $table->index('contact_id', 'payments_contact_id_index');
                $table->index('account_id', 'payments_account_id_index');
                $table->index('payment_method_id', 'payments_payment_method_id_index');
                $table->index('journal_id', 'payments_journal_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
