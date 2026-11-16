<?php

declare(strict_types=1);

use App\Enums\InventoryDocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_documents')) {
            return;
        }

        DB::table('inventory_documents')
            ->where('document_type', 'stock_issue')
            ->update(['document_type' => InventoryDocumentType::GOODS_DELIVERY->value]);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $enumValues = implode("', '", InventoryDocumentType::values());
            DB::statement("ALTER TABLE inventory_documents MODIFY COLUMN document_type ENUM('{$enumValues}') NOT NULL");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_documents')) {
            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $values = array_unique([...InventoryDocumentType::values(), 'stock_issue']);
            $enumValues = implode("', '", $values);
            DB::statement("ALTER TABLE inventory_documents MODIFY COLUMN document_type ENUM('{$enumValues}') NOT NULL");
        }
    }
};
