<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public const POS_PERMISSIONS = [
        'pos.sell',
        'pos.discount',
        'pos.price_override',
        'pos.shift',
        'pos.return',
        'pos.expenses',
    ];

    public function run(): void
    {
        foreach (self::POS_PERMISSIONS as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $adminRole = Role::findOrCreate('admin', 'web');
        $adminRole->givePermissionTo(self::POS_PERMISSIONS);

        $admin = User::firstOrCreate(
            ['email' => 'admin@kady.sys'],
            [
                'name' => 'مدير النظام',
                'phone' => '01000000000',
                'status' => 'active',
                'password' => Hash::make('Admin@12345'),
                'email_verified_at' => now(),
            ],
        );

        if (! Hash::check('Admin@12345', $admin->password)) {
            $admin->update(['password' => Hash::make('Admin@12345')]);
        }

        $admin->assignRole($adminRole);

        $this->command?->info('Admin user ready: admin@kady.sys / Admin@12345');
    }
}
