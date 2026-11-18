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
    }
}

