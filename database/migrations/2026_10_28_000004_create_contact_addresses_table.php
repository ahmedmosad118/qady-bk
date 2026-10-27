<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A contact can have multiple addresses. Foreign key deletion is restricted
     * to prevent silent loss of contact records with linked addresses.
     * Soft deletes ensure historical addresses remain auditable.
     */
    public function up(): void
    {
        Schema::create('contact_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')
                ->constrained('contacts')
                ->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('governorate', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('مصر');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_addresses');
    }
};
