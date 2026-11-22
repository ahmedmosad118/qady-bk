<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Stocktake;
use App\Services\Support\DocumentSequenceGenerator;
use Carbon\Carbon;

class StocktakeNumberService
{
    public function __construct(
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Generate the next unique stocktake number atomically for the given date.
     * Format: STK-YYYY-XXXXX (e.g. STK-2026-00001)
     */
    public function generate(?Carbon $date = null): string
    {
        $year = ($date ?? Carbon::now())->format('Y');

        return $this->sequenceGenerator->next(
            Stocktake::class,
            'stocktake_number',
            "STK-{$year}-",
            5
        );
    }
}
