<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = (string) config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->table($tableName, function (Blueprint $table): void {
            $table->index('created_at', 'activity_log_created_at_index');
            $table->index('event', 'activity_log_event_index');
            $table->index(['causer_id', 'created_at'], 'activity_log_causer_created_at_index');
            $table->index(['log_name', 'created_at'], 'activity_log_log_name_created_at_index');
        });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = (string) config('activitylog.table_name', 'activity_log');

        Schema::connection($connection)->table($tableName, function (Blueprint $table): void {
            $table->dropIndex('activity_log_created_at_index');
            $table->dropIndex('activity_log_event_index');
            $table->dropIndex('activity_log_causer_created_at_index');
            $table->dropIndex('activity_log_log_name_created_at_index');
        });
    }
};
