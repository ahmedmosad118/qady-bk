<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseOrderNumberService
{
    /**
     * Generate unique purchase order number: PO-YYYYMMDD-XXXX
     */
    public function generateOrderNumber(?Carbon $date = null): string
    {
        $dayStr = ($date ?? Carbon::now())->format('Ymd');
        $prefix = "PO-{$dayStr}-";

        return DB::transaction(function () use ($prefix): string {
            $lastNumber = PurchaseOrder::withTrashed()
                ->where('order_number', 'like', "{$prefix}%")
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('order_number');

            $next = 1;
            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $m)) {
                $next = (int) $m[1] + 1;
            }

            return sprintf('%s%04d', $prefix, $next);
        });
    }
}
