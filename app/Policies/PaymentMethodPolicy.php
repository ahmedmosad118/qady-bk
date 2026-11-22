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
            'accounting.settings.view',
            'accounting.settings.update',
        ]);
    }

    public function view(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, [
            'accounting.settings.view',
            'accounting.settings.update',
        ]);
    }

    public function create(User $user): bool
    {
        return $this->canAny($user, ['accounting.settings.update']);
    }

    public function update(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['accounting.settings.update']);
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['accounting.settings.update']);
    }
}
