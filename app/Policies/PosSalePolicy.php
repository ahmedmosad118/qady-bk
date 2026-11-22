<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PosSalePolicy extends BasePolicy
{
    protected string $module = 'pos_sales';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'pos.sell',
        'pos_returns.create',
    ];

    public function view(User $user, mixed $model = null): bool
    {
        return $this->viewAny($user);
    }

    public function sell(User $user): bool
    {
        return $this->canAny($user, ['pos.sell']);
    }
}
