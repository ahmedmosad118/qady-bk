<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a user holding the Super Admin role for happy-path feature tests.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function createSuperAdmin(array $attributes = []): User
    {
        $role = Role::firstOrCreate(
            ['name' => 'Super Admin', 'guard_name' => 'web'],
            [
                'description' => 'Full unrestricted system access',
                'is_super_admin' => true,
                'is_system' => true,
            ]
        );

        /** @var User $user */
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * Authenticate as a Super Admin user via Sanctum.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function actingAsSuperAdmin(array $attributes = []): User
    {
        $user = $this->createSuperAdmin($attributes);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Authenticate as a user granted only the specified permissions via Sanctum.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes
     */
    protected function actingAsUserWithPermissions(array $permissions, array $attributes = []): User
    {
        /** @var User $user */
        $user = User::factory()->create($attributes);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Sanctum::actingAs($user);

        return $user;
    }
}
