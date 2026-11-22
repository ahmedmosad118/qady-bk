<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\UserStatus;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionsTableSeeder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_resources_permissions_file_has_valid_structure_and_all_slugs_resolve(): void
    {
        $config = require resource_path('permissions.php');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('categories', $config);
        $this->assertArrayHasKey('modules', $config);

        $categories = $config['categories'];
        $modules = collect($config['modules']);

        $this->assertCount(8, $categories);
        $this->assertCount(20, $modules);

        $seenNames = [];

        foreach ($categories as $categoryKey => $slugs) {
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $categoryKey);
            $this->assertIsArray($slugs);
            $this->assertNotEmpty($slugs);

            foreach ($slugs as $slug) {
                $matched = $modules->where('slug', $slug);
                $this->assertGreaterThan(0, $matched->count(), "Slug '{$slug}' has no matching modules.");

                foreach ($matched as $module) {
                    $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $module['name']);
                    $this->assertNotEmpty($module['actions']);
                    $prefix = $module['prefix'];

                    foreach ($module['actions'] as $action) {
                        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $action);
                        $fullName = "{$prefix}.{$action}";
                        $this->assertArrayNotHasKey($fullName, $seenNames, "Duplicate permission '{$fullName}'.");
                        $seenNames[$fullName] = true;
                    }
                }
            }
        }

        $this->assertCount(82, $seenNames);
    }

    public function test_permissions_table_seeder_seeds_all_permissions_idempotently(): void
    {
        $this->seed(PermissionsTableSeeder::class);
        $this->seed(PermissionsTableSeeder::class);

        $permissions = Permission::query()->ordered()->get();

        $this->assertCount(82, $permissions);
        $this->assertSame(82, $permissions->pluck('name')->unique()->count());
        $this->assertTrue($permissions->every(static fn (Permission $p): bool => $p->guard_name === 'web'));
        $this->assertTrue($permissions->every(static fn (Permission $p): bool => ! empty($p->category) && ! empty($p->slug) && ! empty($p->module) && ! empty($p->module_name) && ! empty($p->action) && $p->sort_order > 0));

        $sample = Permission::query()->where('name', 'pos.override_price')->first();
        $this->assertNotNull($sample);
        $this->assertSame('Sales & POS', $sample->category);
        $this->assertSame('POS Terminal', $sample->slug);
        $this->assertSame('PosSale', $sample->module);
        $this->assertSame('POS Terminal', $sample->module_name);
        $this->assertSame('override_price', $sample->action);
    }

    public function test_roles_table_seeder_creates_super_admin_and_starter_roles_and_assigns_admin_user(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@kady.sys',
            'status' => UserStatus::ACTIVE,
            'password' => Hash::make('Password123'),
        ]);

        $this->seed(PermissionsTableSeeder::class);
        $this->seed(RolesTableSeeder::class);

        /** @var Role|null $superAdmin */
        $superAdmin = Role::query()->where('name', 'Super Admin')->first();
        $this->assertNotNull($superAdmin);
        $this->assertTrue($superAdmin->isSuperAdmin());
        $this->assertTrue($superAdmin->isSystemRole());
        $this->assertCount(82, $superAdmin->permissions);

        $this->assertTrue($admin->fresh()->isSuperAdmin());
        $this->assertTrue($admin->fresh()->hasRole('Super Admin'));

        $starterRoleNames = ['POS Cashier', 'Warehouse Keeper', 'Accountant', 'Purchasing Officer'];
        foreach ($starterRoleNames as $roleName) {
            /** @var Role|null $role */
            $role = Role::query()->where('name', $roleName)->first();
            $this->assertNotNull($role, "Expected starter role '{$roleName}' to exist.");
            $this->assertFalse($role->isSuperAdmin());
            $this->assertFalse($role->isSystemRole());
            $this->assertGreaterThan(0, $role->permissions()->count());
        }

        // Customize a starter role and re-run seeder; existing customizations must be preserved.
        $cashier = Role::query()->where('name', 'POS Cashier')->firstOrFail();
        $cashier->syncPermissions(['pos.sell']);

        $this->seed(RolesTableSeeder::class);

        $this->assertSame(['pos.sell'], $cashier->fresh()->permissions->pluck('name')->all());
    }

    public function test_permissions_sync_artisan_command_succeeds(): void
    {
        $this->artisan('permissions:sync')
            ->expectsOutputToContain('Synced permissions successfully (82 total permissions).')
            ->assertSuccessful();

        $this->assertSame(82, Permission::query()->count());
    }
}
