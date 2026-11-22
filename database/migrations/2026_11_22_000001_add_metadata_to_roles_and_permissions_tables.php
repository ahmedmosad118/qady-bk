<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $permissionsTable = (string) config('permission.table_names.permissions', 'permissions');

        Schema::table($rolesTable, static function (Blueprint $table): void {
            $table->text('description')->nullable()->after('guard_name');
            $table->boolean('is_super_admin')->default(false)->after('description');
            $table->boolean('is_system')->default(false)->after('is_super_admin');
            $table->index('is_super_admin', 'idx_roles_is_super_admin');
        });

        Schema::table($permissionsTable, static function (Blueprint $table): void {
            $table->string('category', 64)->nullable()->after('guard_name');
            $table->string('slug', 100)->nullable()->after('category');
            $table->string('module', 64)->nullable()->after('slug');
            $table->string('module_name', 100)->nullable()->after('module');
            $table->string('action', 64)->nullable()->after('module_name');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('action');

            $table->index('category', 'idx_permissions_category');
            $table->index('module', 'idx_permissions_module');
            $table->index(['category', 'module', 'sort_order'], 'idx_permissions_category_module_sort');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        $permissionsTable = (string) config('permission.table_names.permissions', 'permissions');

        Schema::table($permissionsTable, static function (Blueprint $table): void {
            $table->dropIndex('idx_permissions_category_module_sort');
            $table->dropIndex('idx_permissions_category');
            $table->dropIndex('idx_permissions_module');
            $table->dropColumn(['sort_order', 'action', 'module_name', 'module', 'slug', 'category']);
        });

        Schema::table($rolesTable, static function (Blueprint $table): void {
            $table->dropIndex('idx_roles_is_super_admin');
            $table->dropColumn(['is_system', 'is_super_admin', 'description']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
