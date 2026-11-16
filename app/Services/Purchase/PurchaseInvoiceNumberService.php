<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\PurchaseInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceNumberService
{
    /**
     * Generate unique purchase invoice number: PI-YYYYMMDD-XXXX
     */
    public function generateInvoiceNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');
        $prefix = "PI-{$dayStr}-";

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = PurchaseInvoice::withTrashed()
                ->where('invoice_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('invoice_number');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $matches)) {
                $next = (int) $matches[1] + 1;
            }

            do {
                $candidate = sprintf('%s%04d', $prefix, $next);
                $exists = PurchaseInvoice::withTrashed()
                    ->where('invoice_number', $candidate)
                    ->exists();
                $next++;
            } while ($exists);

            return $candidate;
        });
    }
}
