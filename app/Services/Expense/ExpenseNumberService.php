<?php

declare(strict_types=1);

namespace App\Services\Expense;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseNumberService
{
    /**
     * Generate unique expense number: EXP-YYYYMMDD-XXXX
     */
    public function generateExpenseNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');
        $prefix = "EXP-{$dayStr}-";

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = Expense::query()
                ->where('expense_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('expense_number');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $matches)) {
                $next = (int) $matches[1] + 1;
            }

            do {
                $candidate = sprintf('%s%04d', $prefix, $next);
                $exists = Expense::query()
                    ->where('expense_number', $candidate)
                    ->exists();
                $next++;
            } while ($exists);

            return $candidate;
        });
    }
}
