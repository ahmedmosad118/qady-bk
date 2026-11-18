<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $configuredPassword = env('ADMIN_INITIAL_PASSWORD');
        $generated = false;

        if (! is_string($configuredPassword) || trim($configuredPassword) === '') {
            $password = Str::password(20);
            $generated = true;
        } else {
            $password = $configuredPassword;
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@kady.sys'],
            [
                'name' => 'مدير النظام',
                'phone' => '01000000000',
                'status' => UserStatus::ACTIVE,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        if ($admin->wasRecentlyCreated && $generated) {
            $this->command?->warn("Generated initial admin password for admin@kady.sys: {$password}");
        }
    }
}

