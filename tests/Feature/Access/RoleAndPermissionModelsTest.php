<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\PermissionCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleAndPermissionModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_spatie_config_and_registrar_bind_custom_role_and_permission_models(): void
    {
        $registrar = app(PermissionRegistrar::class);

        $this->assertSame(Role::class, config('permission.models.role'));
        $this->assertSame(Permission::class, config('permission.models.permission'));
        $this->assertSame(Role::class, $registrar->getRoleClass());
        $this->assertSame(Permission::class, $registrar->getPermissionClass());
        $this->assertInstanceOf(Role::class, app(RoleContract::class));
        $this->assertInstanceOf(Permission::class, app(PermissionContract::class));
    }

    public function test_permission_category_enum_metadata_and_localization(): void
    {
        $expectedValues = [
            'pos',
            'catalog',
            'contacts',
            'purchases',
            'inventory',
            'expenses',
            'accounting',
            'administration',
        ];

        $this->assertSame($expectedValues, PermissionCategory::values());

        $previousOrder = 0;
        foreach (PermissionCategory::cases() as $category) {
            $order = $category->sortOrder();
            $this->assertGreaterThan($previousOrder, $order);
            $previousOrder = $order;

            App::setLocale('ar');
            $arLabel = $category->label();
            $this->assertNotSame('', $arLabel);
            $this->assertNotSame("permissions.categories.{$category->value}", $arLabel);

            App::setLocale('en');
            $enLabel = $category->label();
            $this->assertNotSame('', $enLabel);
            $this->assertNotSame("permissions.categories.{$category->value}", $enLabel);
            $this->assertNotSame($arLabel, $enLabel);
        }
    }

    public function test_role_model_persists_metadata_casts_scopes_and_logs_activity(): void
    {
        $defaultRole = new Role(['name' => 'Draft']);
        $this->assertFalse($defaultRole->isSuperAdmin());
        $this->assertFalse($defaultRole->isSystemRole());

        /** @var Role $customRole */
        $customRole = Role::create([
            'name' => 'Cashier',
            'guard_name' => 'web',
            'description' => 'Handles POS sales and shift cash',
            'is_super_admin' => false,
            'is_system' => false,
        ]);

        /** @var Role $superAdminRole */
        $superAdminRole = Role::create([
            'name' => 'Super Admin',
            'guard_name' => 'web',
            'description' => 'Full unrestricted access',
            'is_super_admin' => true,
            'is_system' => true,
        ]);

        $customRole->refresh();
        $superAdminRole->refresh();

        $this->assertIsBool($customRole->is_super_admin);
        $this->assertIsBool($customRole->is_system);
        $this->assertFalse($customRole->isSuperAdmin());
        $this->assertFalse($customRole->isSystemRole());
        $this->assertSame('Handles POS sales and shift cash', $customRole->description);

        $this->assertTrue($superAdminRole->isSuperAdmin());
        $this->assertTrue($superAdminRole->isSystemRole());

        $this->assertSame([$superAdminRole->id], Role::query()->superAdmins()->pluck('id')->all());
        $this->assertSame([$customRole->id], Role::query()->custom()->pluck('id')->all());

        $customRole->update(['description' => 'Updated POS role description']);

        $activities = Activity::query()
            ->where('log_name', 'role')
            ->where('subject_type', Role::class)
            ->where('subject_id', $customRole->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $activities);
        $this->assertSame(['created', 'updated'], $activities->pluck('event')->all());
    }

    public function test_permission_model_persists_metadata_helpers_and_scopes(): void
    {
        /** @var Permission $approvePerm */
        $approvePerm = Permission::create([
            'name' => 'inventory_documents.approve',
            'guard_name' => 'web',
            'category' => 'Inventory',
            'slug' => 'Inventory Documents',
            'module' => 'InventoryDocument',
            'module_name' => 'Inventory Documents',
            'action' => 'approve',
            'sort_order' => 20,
        ]);

        /** @var Permission $viewPerm */
        $viewPerm = Permission::create([
            'name' => 'inventory_documents.view',
            'guard_name' => 'web',
            'category' => 'Inventory',
            'slug' => 'Inventory Documents',
            'module' => 'InventoryDocument',
            'module_name' => 'Inventory Documents',
            'action' => 'view',
            'sort_order' => 10,
        ]);

        /** @var Permission $enumCatPerm */
        $enumCatPerm = Permission::create([
            'name' => 'inventory_documents.enum_cat',
            'guard_name' => 'web',
            'category' => PermissionCategory::INVENTORY->value,
            'slug' => 'Inventory Documents',
            'module' => 'InventoryDocument',
            'module_name' => 'Inventory Documents',
            'action' => 'post',
            'sort_order' => 30,
        ]);

        /** @var Permission $rawPerm */
        $rawPerm = Permission::findOrCreate('legacy.raw_permission', 'web');

        $approvePerm->refresh();
        $viewPerm->refresh();
        $enumCatPerm->refresh();
        $rawPerm->refresh();

        $this->assertSame('Inventory', $approvePerm->category);
        $this->assertSame('Inventory Documents', $approvePerm->slug);
        $this->assertSame('Inventory Documents', $approvePerm->module_name);
        $this->assertSame(20, $approvePerm->sort_order);
        $this->assertFalse($approvePerm->isBasicAction());

        $this->assertSame('Inventory', $viewPerm->category);
        $this->assertTrue($viewPerm->isBasicAction());

        $this->assertNull($rawPerm->category);
        $this->assertSame(0, $rawPerm->sort_order);
        $this->assertFalse($rawPerm->isBasicAction());

        $orderedNames = Permission::query()
            ->inCategory('Inventory')
            ->forModule('InventoryDocument')
            ->ordered()
            ->pluck('name')
            ->all();

        $this->assertSame([
            'inventory_documents.view',
            'inventory_documents.approve',
        ], $orderedNames);

        $this->assertSame(
            ['inventory_documents.enum_cat'],
            Permission::query()->inCategory(PermissionCategory::INVENTORY)->pluck('name')->all()
        );
    }

    public function test_user_is_super_admin_works_both_lazy_and_eager_loaded_without_extra_queries(): void
    {
        $userWithoutRoles = User::factory()->create();
        $this->assertFalse($userWithoutRoles->isSuperAdmin());

        $regularRole = Role::create([
            'name' => 'Accountant',
            'guard_name' => 'web',
            'is_super_admin' => false,
        ]);

        $superAdminRole = Role::create([
            'name' => 'Super Admin',
            'guard_name' => 'web',
            'is_super_admin' => true,
        ]);

        $regularUser = User::factory()->create();
        $regularUser->assignRole($regularRole);
        $this->assertFalse($regularUser->fresh()->isSuperAdmin());

        $regularUserLoaded = $regularUser->fresh('roles');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertFalse($regularUserLoaded->isSuperAdmin());
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();

        $superUser = User::factory()->create();
        $superUser->assignRole($superAdminRole);
        $this->assertTrue($superUser->fresh()->isSuperAdmin());

        $superUserLoaded = $superUser->fresh('roles');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertTrue($superUserLoaded->isSuperAdmin());
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_user_uses_web_guard_for_spatie_roles(): void
    {
        $this->assertSame('web', Guard::getDefaultName(User::class));

        $permission = Permission::create([
            'name' => 'products.view',
            'guard_name' => 'web',
            'category' => 'Catalog',
            'slug' => 'Products',
            'module' => 'Product',
            'module_name' => 'Products',
            'action' => 'view',
            'sort_order' => 1,
        ]);

        $role = Role::create([
            'name' => 'Catalog Viewer',
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->hasRole('Catalog Viewer', 'web'));
        $this->assertTrue($user->hasPermissionTo('products.view', 'web'));
    }

    public function test_migration_is_reversible_and_reapplicable(): void
    {
        $migration = require database_path('migrations/2026_11_22_000001_add_metadata_to_roles_and_permissions_tables.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('roles', 'description'));
        $this->assertFalse(Schema::hasColumn('roles', 'is_super_admin'));
        $this->assertFalse(Schema::hasColumn('roles', 'is_system'));
        $this->assertFalse(Schema::hasIndex('roles', 'idx_roles_is_super_admin'));

        $this->assertFalse(Schema::hasColumn('permissions', 'category'));
        $this->assertFalse(Schema::hasColumn('permissions', 'slug'));
        $this->assertFalse(Schema::hasColumn('permissions', 'module'));
        $this->assertFalse(Schema::hasColumn('permissions', 'module_name'));
        $this->assertFalse(Schema::hasColumn('permissions', 'action'));
        $this->assertFalse(Schema::hasColumn('permissions', 'sort_order'));
        $this->assertFalse(Schema::hasIndex('permissions', 'idx_permissions_category'));
        $this->assertFalse(Schema::hasIndex('permissions', 'idx_permissions_module'));
        $this->assertFalse(Schema::hasIndex('permissions', 'idx_permissions_category_module_sort'));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('roles', ['description', 'is_super_admin', 'is_system']));
        $this->assertTrue(Schema::hasIndex('roles', 'idx_roles_is_super_admin'));

        $this->assertTrue(Schema::hasColumns('permissions', ['category', 'slug', 'module', 'module_name', 'action', 'sort_order']));
        $this->assertTrue(Schema::hasIndex('permissions', 'idx_permissions_category'));
        $this->assertTrue(Schema::hasIndex('permissions', 'idx_permissions_module'));
        $this->assertTrue(Schema::hasIndex('permissions', 'idx_permissions_category_module_sort'));
    }
}
