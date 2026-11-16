<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('expenses.view');
    }

    public function view(User $user, Expense $expense): bool
    {
        return Gate::forUser($user)->allows('expenses.view');
    }

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return Gate::forUser($user)->allows('expenses.update') && $expense->isEditable();
    }

    public function delete(User $user, Expense $expense): bool
    {
        return Gate::forUser($user)->allows('expenses.delete') && $expense->isEditable();
    }

    public function viewAttachment(User $user, Expense $expense): bool
    {
        return Gate::forUser($user)->allows('expenses.view');
    }

    public function manageAttachments(User $user, Expense $expense): bool
    {
        return Gate::forUser($user)->allows('expenses.update') && $expense->isEditable();
    }
}
