<?php

declare(strict_types=1);

namespace App\Events\Expense;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

class ExpenseCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Expense $expense,
        public readonly User $user,
    ) {}
}
