<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy extends BasePolicy
{
    protected string $module = 'expenses';

    /** @var list<string> */
    protected array $excludeFromBefore = ['update', 'delete', 'manageAttachments'];

    public function update(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['expenses.update'])
            && (! $model instanceof Expense || $model->isEditable());
    }

    public function delete(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['expenses.delete'])
            && (! $model instanceof Expense || $model->isEditable());
    }

    public function viewAttachment(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['expenses.view']);
    }

    public function manageAttachments(User $user, mixed $model = null): bool
    {
        return $this->canAny($user, ['expenses.update'])
            && (! $model instanceof Expense || $model->isEditable());
    }
}
