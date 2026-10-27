<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A contact represents an individual or a company and can act as a customer,
     * a supplier, or both. Phone numbers and registration numbers are stored as
     * text to preserve leading zeros and formatting.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->default('individual');
            $table->string('name', 255);
            $table->string('company_contact_person', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('tax_number', 100)->nullable();
            $table->string('tax_registration_type', 50)->nullable();
            $table->string('commercial_register', 100)->nullable();
            $table->boolean('is_customer')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('phone');
            $table->index('mobile');
            $table->index('tax_number');
            $table->index('is_customer');
            $table->index('is_supplier');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
