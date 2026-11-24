<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class ActivityPolicy extends BasePolicy
{
    protected string $module = 'activities';

    /**
     * Determine whether the user can permanently clear all activity logs.
     */
    public function clear(User $user): bool
    {
        return $this->canAny($user, ['activities.clear']);
    }
}
