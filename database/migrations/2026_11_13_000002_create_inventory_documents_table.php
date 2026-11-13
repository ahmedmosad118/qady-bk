<?php

declare(strict_types=1);

use App\Enums\InventoryDocumentStatus;
use App\Enums\InventoryDocumentType;
use App\Enums\InventoryReturnDirection;
use App\Enums\StockAdjustmentDirection;
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
        Schema::create('inventory_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('document_number', 100)->unique();
            $table->enum('document_type', InventoryDocumentType::values());
            $table->nullableMorphs('referenceable');

            $table->foreignId('source_warehouse_id')
                ->nullable()
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('destination_warehouse_id')
                ->nullable()
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->enum('adjustment_direction', StockAdjustmentDirection::values())->nullable();
            $table->enum('return_direction', InventoryReturnDirection::values())->nullable();
            $table->enum('status', InventoryDocumentStatus::values())->default(InventoryDocumentStatus::DRAFT->value);

            $table->date('document_date');
            $table->text('notes')->nullable();
            $table->text('write_off_reason')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('rejected_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('reversed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('document_type', 'idx_inventory_documents_type');
            $table->index('status', 'idx_inventory_documents_status');
            $table->index('document_date', 'idx_inventory_documents_date');
            $table->index(['status', 'document_date'], 'idx_inventory_documents_status_date');
            $table->index(['document_type', 'document_date'], 'idx_inventory_documents_type_date');
            $table->index(['source_warehouse_id', 'document_date'], 'idx_inventory_documents_source_date');
            $table->index(['destination_warehouse_id', 'document_date'], 'idx_inventory_documents_destination_date');
            $table->index('created_by', 'idx_inventory_documents_created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_documents');
    }
};
