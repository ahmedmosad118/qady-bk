<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return Gate::forUser($user)->allows('expense_categories.view');
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return Gate::forUser($user)->allows('expense_categories.view');
    }

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('expense_categories.create');
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        return Gate::forUser($user)->allows('expense_categories.update');
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return Gate::forUser($user)->allows('expense_categories.delete');
    }
}
