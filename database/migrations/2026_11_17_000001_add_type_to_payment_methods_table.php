<?php

declare(strict_types=1);

use App\Enums\PaymentMethodType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('payment_methods', 'type')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->enum('type', PaymentMethodType::values())
                    ->default(PaymentMethodType::CASH->value)
                    ->after('name');
                $table->index('type', 'idx_payment_methods_type');
            });
        }

        // Backfill existing seeded payment methods based on their Arabic names
        DB::table('payment_methods')
            ->where('name', PaymentMethodType::CASH->label())
            ->update(['type' => PaymentMethodType::CASH->value]);

        DB::table('payment_methods')
            ->where('name', PaymentMethodType::VISA->label())
            ->update(['type' => PaymentMethodType::VISA->value]);

        DB::table('payment_methods')
            ->where('name', PaymentMethodType::BANK_TRANSFER->label())
            ->update(['type' => PaymentMethodType::BANK_TRANSFER->value]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('payment_methods', 'type')) {
            Schema::table('payment_methods', function (Blueprint $table): void {
                $table->dropIndex('idx_payment_methods_type');
                $table->dropColumn('type');
            });
        }
    }
};
