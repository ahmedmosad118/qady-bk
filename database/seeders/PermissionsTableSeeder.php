<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class PermissionsTableSeeder extends Seeder
{
    /**
     * Seed all application permissions from resources/permissions.php.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $defaultGuard = (string) config('auth.defaults.guard', 'web');
        $permissionsConfig = require resource_path('permissions.php');

        if (! is_array($permissionsConfig)) {
            return;
        }

        $categories = $permissionsConfig['categories'] ?? [];
        $modules = collect($permissionsConfig['modules'] ?? []);
        $sortOrder = 0;

        foreach ($categories as $categoryKey => $categorySlugs) {
            foreach ($categorySlugs as $categorySlug) {
                $categoryModules = $modules->where('slug', $categorySlug);

                foreach ($categoryModules as $module) {
                    $actions = $module['actions'] ?? [];
                    $model = (string) ($module['model'] ?? '');
                    $prefix = (string) ($module['prefix'] ?? Str::snake($model));

                    foreach ($actions as $action) {
                        $sortOrder++;
                        $permissionName = "{$prefix}.{$action}";

                        Permission::updateOrCreate(
                            [
                                'name' => $permissionName,
                                'guard_name' => $defaultGuard,
                            ],
                            [
                                'category' => (string) $categoryKey,
                                'slug' => (string) $categorySlug,
                                'module' => $model,
                                'module_name' => (string) $module['name'],
                                'action' => (string) $action,
                                'sort_order' => $sortOrder,
                            ]
                        );
                    }
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
