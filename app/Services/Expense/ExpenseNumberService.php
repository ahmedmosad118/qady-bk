<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Models\Expense;
use App\Services\Support\DocumentSequenceGenerator;
use Carbon\Carbon;

class ExpenseNumberService
{
    public function __construct(
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Generate unique expense number: EXP-YYYYMMDD-XXXX
     */
    public function generateExpenseNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');

        return $this->sequenceGenerator->next(
            Expense::class,
            'expense_number',
            "EXP-{$dayStr}-",
            4
        );
    }
}
