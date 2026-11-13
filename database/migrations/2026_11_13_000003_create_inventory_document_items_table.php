<?php

declare(strict_types=1);

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
        Schema::create('inventory_document_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('inventory_document_id')
                ->constrained('inventory_documents')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 15, 3);
            $table->text('notes')->nullable();

            $table->timestamps();

            // Composite unique index to prevent duplicate product in same document
            $table->unique(['inventory_document_id', 'product_id'], 'uq_inv_doc_items_doc_prod');
            $table->index('product_id', 'idx_inv_doc_items_product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_document_items');
    }
};
