<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesTableSeeder extends Seeder
{
    /**
     * Seed Super Admin and starter roles, and assign Super Admin to the default admin user.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard', 'web');
        $allPermissions = Permission::query()
            ->where('guard_name', $guard)
            ->get()
            ->keyBy('name');

        /** @var Role $superAdmin */
        $superAdmin = Role::updateOrCreate(
            [
                'name' => 'Super Admin',
                'guard_name' => $guard,
            ],
            [
                'description' => 'Full unrestricted system access',
                'is_super_admin' => true,
                'is_system' => true,
            ]
        );

        $superAdmin->syncPermissions($allPermissions->values());

        $starterRoles = [
            'POS Cashier' => [
                'description' => 'Point of sale selling, returns, shifts, and customer lookup',
                'permissions' => [
                    'pos.sell',
                    'pos_sales.view',
                    'pos_returns.view',
                    'pos_returns.create',
                    'pos_shifts.view',
                    'pos_shifts.manage',
                    'pos_shifts.manage_cash',
                    'contacts.view',
                    'contacts.create',
                ],
            ],
            'Warehouse Keeper' => [
                'description' => 'Warehouse stock management, inventory documents, and stocktaking',
                'permissions' => [
                    'products.view',
                    'product_categories.view',
                    'warehouses.view',
                    'inventory_documents.view',
                    'inventory_documents.create',
                    'inventory_documents.update',
                    'inventory_documents.submit',
                    'warehouse_stocks.view',
                    'warehouse_stocks.view_movements',
                    'stocktakes.view',
                    'stocktakes.create',
                    'stocktakes.start',
                    'stocktakes.count',
                    'stocktakes.submit',
                ],
            ],
            'Accountant' => [
                'description' => 'Chart of accounts, accounting settings, expenses, and financial review',
                'permissions' => [
                    'accounting.accounts.view',
                    'accounting.accounts.create',
                    'accounting.accounts.update',
                    'accounting.accounts.delete',
                    'accounting.settings.view',
                    'accounting.settings.update',
                    'expenses.view',
                    'expenses.create',
                    'expenses.update',
                    'expenses.delete',
                    'expense_categories.view',
                    'expense_categories.create',
                    'expense_categories.update',
                    'expense_categories.delete',
                    'purchase_orders.view',
                    'purchase_invoices.view',
                    'pos_sales.view',
                    'pos_returns.view',
                    'pos_shifts.view',
                    'pos.view_dashboard',
                ],
            ],
            'Purchasing Officer' => [
                'description' => 'Suppliers, catalog items, purchase orders, and purchase invoices',
                'permissions' => [
                    'contacts.view',
                    'contacts.create',
                    'contacts.update',
                    'products.view',
                    'products.create',
                    'products.update',
                    'product_categories.view',
                    'purchase_orders.view',
                    'purchase_orders.create',
                    'purchase_orders.update',
                    'purchase_orders.confirm',
                    'purchase_orders.cancel',
                    'purchase_invoices.view',
                    'purchase_invoices.create',
                    'purchase_invoices.update',
                    'purchase_invoices.cancel',
                    'purchase_settings.view',
                    'purchase_settings.update',
                ],
            ],
        ];

        foreach ($starterRoles as $roleName => $meta) {
            /** @var Role $role */
            $role = Role::firstOrCreate(
                [
                    'name' => $roleName,
                    'guard_name' => $guard,
                ],
                [
                    'description' => $meta['description'],
                    'is_super_admin' => false,
                    'is_system' => false,
                ]
            );

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions(
                    $allPermissions->whereIn('name', $meta['permissions'])->values()
                );
            }
        }

        $admin = User::query()->where('email', 'admin@kady.sys')->first();
        if ($admin !== null && ! $admin->hasRole($superAdmin)) {
            $admin->assignRole($superAdmin);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
