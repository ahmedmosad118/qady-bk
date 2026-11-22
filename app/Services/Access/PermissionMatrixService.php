<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\Permission;

class PermissionMatrixService
{
    /**
     * Return all web-guard permissions grouped by category -> module with localized labels.
     *
     * @return array{categories: list<array<string, mixed>>, total_permissions: int}
     */
    public function getMatrix(): array
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->ordered()
            ->get(['id', 'name', 'category', 'slug', 'module', 'module_name', 'action', 'sort_order']);

        $categories = [];

        foreach ($permissions as $permission) {
            $categoryKey = (string) ($permission->category ?? 'General');
            $moduleKey = (string) ($permission->module ?? 'general');
            $moduleName = (string) ($permission->module_name ?? $moduleKey);
            $actionKey = (string) ($permission->action ?? $permission->name);

            if (! isset($categories[$categoryKey])) {
                $categories[$categoryKey] = [
                    'key' => $categoryKey,
                    'label' => $this->translate("permissions.categories.{$categoryKey}", $categoryKey),
                    'modules' => [],
                ];
            }

            if (! isset($categories[$categoryKey]['modules'][$moduleName])) {
                $categories[$categoryKey]['modules'][$moduleName] = [
                    'key' => $moduleKey,
                    'slug' => (string) ($permission->slug ?? $moduleName),
                    'name' => $moduleName,
                    'label' => $this->translate("permissions.modules.{$moduleName}", $moduleName),
                    'permissions' => [],
                ];
            }

            $categories[$categoryKey]['modules'][$moduleName]['permissions'][] = [
                'id' => $permission->id,
                'name' => $permission->name,
                'action' => $actionKey,
                'label' => $this->translate("permissions.actions.{$actionKey}", $actionKey),
                'is_basic' => $permission->isBasicAction(),
            ];
        }

        $grouped = [];
        foreach ($categories as $category) {
            $category['modules'] = array_values($category['modules']);
            $grouped[] = $category;
        }

        return [
            'categories' => $grouped,
            'total_permissions' => $permissions->count(),
        ];
    }

    private function translate(string $key, string $fallback): string
    {
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : $fallback;
    }
}
