<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class InventoryDocumentPolicy extends BasePolicy
{
    protected string $module = 'inventory_documents';

    public function submit(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['inventory_documents.submit']);
    }

    public function approve(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['inventory_documents.approve']);
    }

    public function reject(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['inventory_documents.reject']);
    }

    public function cancel(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['inventory_documents.cancel']);
    }

    public function post(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['inventory_documents.post']);
    }

    public function reverse(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['inventory_documents.reverse']);
    }
}
