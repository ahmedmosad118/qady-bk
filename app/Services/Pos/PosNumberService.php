<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\PosHeldSale;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PosShift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PosNumberService
{
    /**
     * Generate unique shift number: SH-YYYYMMDD-XXXX
     */
    public function generateShiftNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');
        $prefix = "SH-{$dayStr}-";

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = PosShift::query()
                ->where('shift_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('shift_number');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $m)) {
                $next = (int) $m[1] + 1;
            }

            return sprintf('%s%04d', $prefix, $next);
        });
    }

    /**
     * Generate unique POS sale invoice number: INV-YYYYMMDD-XXXXX
     */
    public function generateInvoiceNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');
        $prefix = "INV-{$dayStr}-";

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = PosSale::query()
                ->where('invoice_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('invoice_number');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $m)) {
                $next = (int) $m[1] + 1;
            }

            return sprintf('%s%05d', $prefix, $next);
        });
    }

    /**
     * Generate unique POS return number: RET-YYYYMMDD-XXXXX
     */
    public function generateReturnNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');
        $prefix = "RET-{$dayStr}-";

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = PosReturn::query()
                ->where('return_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('return_number');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $m)) {
                $next = (int) $m[1] + 1;
            }

            return sprintf('%s%05d', $prefix, $next);
        });
    }

    /**
     * Generate unique hold reference: HOLD-XXXXX
     */
    public function generateHoldReference(): string
    {
        $prefix = 'HOLD-';

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = PosHeldSale::query()
                ->where('reference_note', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('reference_note');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $m)) {
                $next = (int) $m[1] + 1;
            }

            return sprintf('%s%05d', $prefix, $next);
        });
    }
}
