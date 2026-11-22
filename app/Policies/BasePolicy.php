<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

abstract class BasePolicy
{
    use HandlesAuthorization;

    protected string $module = '';

    /** @var list<string> */
    protected array $lookupPermissions = [];

    /** @var list<string> */
    protected array $excludeFromBefore = [];

    public function before(User $user, string $ability): ?bool
    {
        if (! in_array($ability, $this->excludeFromBefore, true) && $user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function canAny(User $user, array $permissions): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyPermission($permissions);
    }

    public function viewAny(User $user): bool
    {
        return $this->canAny($user, array_merge(["{$this->module}.view"], $this->lookupPermissions));
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ["{$this->module}.view"]);
    }

    public function create(User $user): bool
    {
        return $this->canAny($user, ["{$this->module}.create"]);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ["{$this->module}.update"]);
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ["{$this->module}.delete"]);
    }
}
