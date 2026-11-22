<?php

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
        if (Schema::hasColumn('products', 'show_in_shop')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('show_in_shop')->default(false)->after('is_active')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('products', 'show_in_shop')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['show_in_shop']);
            $table->dropColumn('show_in_shop');
        });
    }
};
