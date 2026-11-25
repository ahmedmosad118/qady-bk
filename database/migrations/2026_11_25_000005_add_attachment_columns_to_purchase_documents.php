<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'purchase_orders',
        'purchase_invoices',
    ];

    /**
     * @var list<string>
     */
    private array $columns = [
        'attachment_disk',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime_type',
        'attachment_size',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'attachment_disk')) {
                    $table->string('attachment_disk', 50)->nullable()->after('notes');
                }
                if (! Schema::hasColumn($tableName, 'attachment_path')) {
                    $table->string('attachment_path', 500)->nullable()->after('attachment_disk');
                }
                if (! Schema::hasColumn($tableName, 'attachment_original_name')) {
                    $table->string('attachment_original_name', 255)->nullable()->after('attachment_path');
                }
                if (! Schema::hasColumn($tableName, 'attachment_mime_type')) {
                    $table->string('attachment_mime_type', 150)->nullable()->after('attachment_original_name');
                }
                if (! Schema::hasColumn($tableName, 'attachment_size')) {
                    $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime_type');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $existing = array_values(array_filter(
                $this->columns,
                static fn (string $column): bool => Schema::hasColumn($tableName, $column)
            ));

            if ($existing !== []) {
                Schema::table($tableName, function (Blueprint $table) use ($existing): void {
                    $table->dropColumn($existing);
                });
            }
        }
    }
};
