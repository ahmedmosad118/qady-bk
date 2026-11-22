<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PaymentMethodPolicy extends BasePolicy
{
    protected string $module = 'pos';

    public function viewAny(User $user): bool
    {
        return $this->canAny($user, [
            'pos.sell',
            'pos_sales.view',
            'pos_returns.view',
            'pos_shifts.view',
            'pos.view_dashboard',
        ]);
    }
}
