<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    /**
     * List all web-guard roles for dropdowns and selectors.
     *
     * @return Collection<int, Role>
     */
    public function listAll(): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->orderByDesc('is_super_admin')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'is_super_admin', 'is_system']);
    }

    /**
     * Paginate web-guard roles with search, sorting, and user/permission counts.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Role::query()
            ->where('guard_name', 'web')
            ->withCount(['users', 'permissions']);

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(static function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $allowedSorts = ['name', 'created_at', 'users_count', 'permissions_count'];
        $sortBy = in_array($filters['sort_by'] ?? null, $allowedSorts, true)
            ? (string) $filters['sort_by']
            : null;
        $sortOrder = strtolower((string) ($filters['sort_order'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortBy !== null) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderByDesc('is_super_admin')
                ->orderByDesc('is_system')
                ->orderBy('id');
        }

        $limit = min(max($perPage, 1), 100);

        return $query->paginate($limit);
    }

    /**
     * Load a role with its permissions and usage counts.
     */
    public function findWithDetails(Role $role): Role
    {
        return $role
            ->load('permissions:id,name,category,module,module_name,action')
            ->loadCount(['users', 'permissions']);
    }

    /**
     * Create a new custom role and sync its permissions.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Role
    {
        $isSuperAdmin = (bool) ($data['is_super_admin'] ?? false);

        if ($isSuperAdmin && ($actor === null || ! $actor->isSuperAdmin())) {
            throw new AuthorizationException((string) __('roles.only_super_admin_can_assign_super_admin'));
        }

        return DB::transaction(function () use ($data, $isSuperAdmin): Role {
            /** @var Role $role */
            $role = Role::create([
                'name' => (string) $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
                'is_super_admin' => $isSuperAdmin,
                'is_system' => false,
            ]);

            if (! $isSuperAdmin && ! empty($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $this->findWithDetails($role);
        });
    }

    /**
     * Update an existing role and sync its permissions while protecting system roles.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, array $data): Role
    {
        if ($role->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'role' => [__('roles.cannot_modify_super_admin')],
            ]);
        }

        if ($role->isSystemRole() && (string) $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => [__('roles.cannot_rename_system_role')],
            ]);
        }

        return DB::transaction(function () use ($role, $data): Role {
            $role->name = (string) $data['name'];
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            $role->save();

            if (array_key_exists('permissions', $data)) {
                $role->syncPermissions($data['permissions'] ?? []);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $this->findWithDetails($role);
        });
    }

    /**
     * Delete a custom role that has no assigned users.
     */
    public function delete(Role $role): void
    {
        if ($role->isSuperAdmin() || $role->isSystemRole()) {
            throw ValidationException::withMessages([
                'role' => [__('roles.cannot_delete_system_role')],
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => [__('roles.cannot_delete_role_with_users')],
            ]);
        }

        DB::transaction(static function () use ($role): void {
            $role->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
    }
}
