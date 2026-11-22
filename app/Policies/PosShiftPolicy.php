<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PosShiftPolicy extends BasePolicy
{
    protected string $module = 'pos_shifts';

    public function view(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, [
            'pos_shifts.view',
            'pos_shifts.manage',
            'pos.sell',
        ]);
    }

    public function viewOpen(User $user): bool
    {
        return $this->canAny($user, [
            'pos.sell',
            'pos_shifts.view',
            'pos_shifts.manage',
            'pos_returns.create',
        ]);
    }

    public function manage(User $user): bool
    {
        return $this->canAny($user, ['pos_shifts.manage', 'pos.sell']);
    }

    public function manageCash(User $user): bool
    {
        return $this->canAny($user, ['pos_shifts.manage_cash']);
    }
}
