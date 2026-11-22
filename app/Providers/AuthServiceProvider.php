<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Implicitly grant "Super Admin" role permission strings while preserving model policy guards.
        Gate::before(static function (User $user, string $ability, array $arguments = []): ?bool {
            if ($arguments === [] && $user->isSuperAdmin()) {
                return true;
            }

            return null;
        });
    }
}
