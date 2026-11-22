<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PosReturnPolicy extends BasePolicy
{
    protected string $module = 'pos_returns';

    public function viewReturnableItems(User $user): bool
    {
        return $this->canAny($user, [
            'pos_returns.create',
            'pos_returns.view',
            'pos.sell',
        ]);
    }
}
