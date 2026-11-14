<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Stocktake;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StocktakeNumberService
{
    /**
     * Generate the next unique stocktake number atomically for the given date.
     * Format: STK-YYYY-XXXXX (e.g. STK-2026-00001)
     */
    public function generate(?Carbon $date = null): string
    {
        $year = ($date ?? Carbon::now())->format('Y');
        $searchPrefix = "STK-{$year}-";

        return DB::transaction(function () use ($searchPrefix): string {
            $lastNumber = Stocktake::query()
                ->where('stocktake_number', 'like', "{$searchPrefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('stocktake_number');

            $nextSequence = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $matches)) {
                $nextSequence = (int) $matches[1] + 1;
            }

            return sprintf('%s%05d', $searchPrefix, $nextSequence);
        });
    }
}
