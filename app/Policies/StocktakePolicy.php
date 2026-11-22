<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class StocktakePolicy extends BasePolicy
{
    protected string $module = 'stocktakes';

    public function start(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['stocktakes.start']);
    }

    public function submit(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['stocktakes.submit']);
    }

    public function approve(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['stocktakes.approve']);
    }

    public function reject(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['stocktakes.reject']);
    }

    public function cancel(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['stocktakes.cancel']);
    }

    public function count(User $user): bool
    {
        return $this->canAny($user, ['stocktakes.count']);
    }

    public function reviewResults(User $user): bool
    {
        return $this->canAny($user, ['stocktakes.review_results']);
    }
}
