<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
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

        if (! Hash::check('Admin@12345', $admin->password)) {
            $admin->update(['password' => Hash::make('Admin@12345')]);
        }

        $this->command?->info('Admin user ready: admin@kady.sys / Admin@12345');
    }
}
