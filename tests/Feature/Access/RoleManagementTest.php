<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionsTableSeeder::class);
        $this->seed(RolesTableSeeder::class);
    }

    public function test_permissions_matrix_returns_grouped_localized_permissions(): void
    {
        $this->actingAsSuperAdmin();

        App::setLocale('ar');
        $response = $this->getJson('/api/permissions')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 84);

        $categories = $response->json('data');
        $this->assertCount(8, $categories);

        $moduleCount = 0;
        $permissionCount = 0;
        foreach ($categories as $category) {
            $this->assertArrayHasKey('key', $category);
            $this->assertArrayHasKey('label', $category);
            $this->assertNotSame('', $category['label']);
            foreach ($category['modules'] as $module) {
                $moduleCount++;
                $permissionCount += count($module['permissions']);
            }
        }

        $this->assertSame(21, $moduleCount);
        $this->assertSame(84, $permissionCount);

        $catalogCategory = collect($categories)->firstWhere('key', 'Catalog');
        $this->assertSame('المنتجات', $catalogCategory['label']);
    }

    public function test_unauthorized_user_receives_403_for_roles_and_permissions_endpoints(): void
    {
        $role = Role::query()->where('name', 'POS Cashier')->firstOrFail();
        $this->actingAsUserWithPermissions([]);

        $this->getJson('/api/permissions')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
        $this->postJson('/api/roles', [])->assertForbidden();
        $this->getJson("/api/roles/{$role->id}")->assertForbidden();
        $this->putJson("/api/roles/{$role->id}", [])->assertForbidden();
        $this->deleteJson("/api/roles/{$role->id}")->assertForbidden();
    }

    public function test_user_manager_can_lookup_roles_list_for_dropdown(): void
    {
        $this->actingAsUserWithPermissions(['users.create']);

        $this->getJson('/api/roles?all=1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(5, 'data');
    }

    public function test_super_admin_can_crud_custom_role_and_sync_permissions(): void
    {
        $this->actingAsSuperAdmin();

        // Create
        $createRes = $this->postJson('/api/roles', [
            'name' => 'Branch Auditor',
            'description' => 'Reviews inventory and expenses',
            'permissions' => ['products.view', 'expenses.view'],
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Branch Auditor')
            ->assertJsonPath('data.is_system', false)
            ->assertJsonPath('data.is_super_admin', false)
            ->assertJsonPath('data.permissions_count', 2);

        $roleId = (int) $createRes->json('data.id');

        // Duplicate name rejected
        $this->postJson('/api/roles', [
            'name' => 'Branch Auditor',
            'permissions' => ['products.view'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        // Invalid permission rejected
        $this->postJson('/api/roles', [
            'name' => 'Bad Role',
            'permissions' => ['nonexistent.permission'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['permissions.0']);

        // Paginated list + search
        $this->getJson('/api/roles?search=Auditor')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Branch Auditor')
            ->assertJsonPath('data.0.permissions_count', 2);

        // Show
        $this->getJson("/api/roles/{$roleId}")
            ->assertOk()
            ->assertJsonPath('data.id', $roleId)
            ->assertJsonCount(2, 'data.permissions');

        // Update & re-sync permissions
        $this->putJson("/api/roles/{$roleId}", [
            'name' => 'Senior Auditor',
            'description' => 'Updated description',
            'permissions' => ['products.view', 'expenses.view', 'stocktakes.view'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Senior Auditor')
            ->assertJsonPath('data.permissions_count', 3);

        // Delete unused custom role
        $this->deleteJson("/api/roles/{$roleId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('roles', ['id' => $roleId]);
    }

    public function test_system_roles_and_roles_with_users_are_protected(): void
    {
        $this->actingAsSuperAdmin();

        $superAdminRole = Role::query()->where('is_super_admin', true)->firstOrFail();

        // Cannot modify or delete Super Admin role
        $this->putJson("/api/roles/{$superAdminRole->id}", [
            'name' => 'Hacked Admin',
            'permissions' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->deleteJson("/api/roles/{$superAdminRole->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        // System non-super-admin role cannot be renamed or deleted, but its permissions can be updated
        $systemRole = Role::create([
            'name' => 'System Cashier',
            'guard_name' => 'web',
            'is_system' => true,
            'is_super_admin' => false,
        ]);

        $this->putJson("/api/roles/{$systemRole->id}", [
            'name' => 'Renamed Cashier',
            'permissions' => ['pos.sell'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->putJson("/api/roles/{$systemRole->id}", [
            'name' => 'System Cashier',
            'description' => 'Allowed description update',
            'permissions' => ['pos.sell'],
        ])->assertOk()
            ->assertJsonPath('data.permissions_count', 1);

        $this->deleteJson("/api/roles/{$systemRole->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        // Custom role assigned to a user cannot be deleted
        $assignedRole = Role::query()->where('name', 'POS Cashier')->firstOrFail();
        $user = User::factory()->create();
        $user->assignRole($assignedRole);

        $this->deleteJson("/api/roles/{$assignedRole->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);
    }

    public function test_user_can_be_created_updated_and_filtered_with_multiple_roles_and_protects_last_super_admin(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $cashierRole = Role::query()->where('name', 'POS Cashier')->firstOrFail();
        $accountantRole = Role::query()->where('name', 'Accountant')->firstOrFail();

        // Create user with a single role (and reject multiple roles)
        $this->postJson('/api/users', [
            'name' => 'Multi Role User',
            'email' => 'multi@kady.sys',
            'phone' => '01012345678',
            'status' => 'active',
            'password' => 'Password123',
            'roles' => [$cashierRole->id, $accountantRole->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);

        $createRes = $this->postJson('/api/users', [
            'name' => 'Single Role User',
            'email' => 'single@kady.sys',
            'phone' => '01012345678',
            'status' => 'active',
            'password' => 'Password123',
            'roles' => [$accountantRole->id],
        ])->assertCreated()
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.id', $accountantRole->id);

        $newUserId = (int) $createRes->json('data.id');

        // Filter users by role_id and verify eager-loaded roles
        $this->getJson("/api/users?role_id={$accountantRole->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $newUserId)
            ->assertJsonCount(1, 'data.0.roles');

        // Update user role
        $this->putJson("/api/users/{$newUserId}", [
            'name' => 'Single Role User',
            'email' => 'single@kady.sys',
            'status' => 'active',
            'roles' => [$cashierRole->id],
        ])->assertOk()
            ->assertJsonCount(1, 'data.roles')
            ->assertJsonPath('data.roles.0.id', $cashierRole->id);

        // Cannot strip Super Admin role from the last Super Admin
        $this->putJson("/api/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'status' => 'active',
            'roles' => [$cashierRole->id],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);

        // Cannot delete the last Super Admin even by another user with users.delete
        $this->actingAsUserWithPermissions(['users.delete']);
        $this->deleteJson("/api/users/{$admin->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
    }

    public function test_only_super_admin_can_create_or_assign_super_admin_roles(): void
    {
        $superAdminRole = Role::query()->where('is_super_admin', true)->firstOrFail();

        // Super Admin can create a custom Super Admin role
        $superAdmin = $this->actingAsSuperAdmin();
        $this->postJson('/api/roles', [
            'name' => 'Co-Founder',
            'description' => 'Full executive access',
            'is_super_admin' => true,
        ])->assertCreated()
            ->assertJsonPath('data.is_super_admin', true);

        // Non-Super-Admin with roles.create cannot create an is_super_admin role
        $this->actingAsUserWithPermissions(['roles.create', 'users.create', 'users.update']);

        $this->postJson('/api/roles', [
            'name' => 'Escalated Role',
            'is_super_admin' => true,
        ])->assertForbidden();

        // Non-Super-Admin with users.create cannot assign a Super Admin role
        $this->postJson('/api/users', [
            'name' => 'Escalated User',
            'email' => 'escalated@kady.sys',
            'status' => 'active',
            'password' => 'Password123',
            'roles' => [$superAdminRole->id],
        ])->assertForbidden();

        // Non-Super-Admin with users.update cannot modify an existing Super Admin user
        $this->putJson("/api/users/{$superAdmin->id}", [
            'name' => 'Tampered Admin',
            'email' => $superAdmin->email,
            'status' => 'active',
        ])->assertForbidden();
    }
}
