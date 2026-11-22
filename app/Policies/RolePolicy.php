<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class RolePolicy extends BasePolicy
{
    protected string $module = 'roles';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'users.view',
        'users.create',
        'users.update',
    ];

    public function viewPermissions(User $user): bool
    {
        return $this->canAny($user, [
            'roles.view',
            'roles.create',
            'roles.update',
        ]);
    }
}
