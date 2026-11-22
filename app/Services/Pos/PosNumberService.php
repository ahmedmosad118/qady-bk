<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Services\Support\DocumentSequenceGenerator;
use Carbon\Carbon;

class PosNumberService
{
    public function __construct(
        protected DocumentSequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Generate unique shift number: SH-YYYYMMDD-XXXX
     */
    public function generateShiftNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');

        return $this->sequenceGenerator->next(
            PosShift::class,
            'shift_number',
            "SH-{$dayStr}-",
            4
        );
    }

    /**
     * Generate unique POS sale invoice number: INV-YYYYMMDD-XXXXX
     */
    public function generateInvoiceNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');

        return $this->sequenceGenerator->next(
            PosSale::class,
            'invoice_number',
            "INV-{$dayStr}-",
            5
        );
    }

    /**
     * Generate unique POS return number: RET-YYYYMMDD-XXXXX
     */
    public function generateReturnNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');

        return $this->sequenceGenerator->next(
            PosReturn::class,
            'return_number',
            "RET-{$dayStr}-",
            5
        );
    }
}
