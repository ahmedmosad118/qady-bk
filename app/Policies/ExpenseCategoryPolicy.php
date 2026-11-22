<?php

declare(strict_types=1);

namespace App\Policies;

class ExpenseCategoryPolicy extends BasePolicy
{
    protected string $module = 'expense_categories';

    /** @var list<string> */
    protected array $lookupPermissions = [
        'expenses.view',
        'expenses.create',
        'expenses.update',
    ];
}
