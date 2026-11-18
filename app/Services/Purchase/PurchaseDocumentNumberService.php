<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Document numbers for purchase orders and purchase invoices, built from the database id:
 *
 *   PRH-ORD/2026/000036   (purchase order)
 *   BILL/2026/000074      (purchase invoice)
 *
 * Because the sequence part IS the row id, numbers can never collide, need no locking and
 * no retry loop. The number is assigned right after the INSERT (inside the caller's transaction);
 * the row is created with a throw-away unique placeholder first (the column is NOT NULL + UNIQUE).
 */
class PurchaseDocumentNumberService
{
    public const ORDER_PREFIX = 'PRH-ORD';

    public const INVOICE_PREFIX = 'BILL';

    private const ID_PAD = 6;

    /**
     * Unique placeholder for the INSERT; replaced by assign*Number() in the same transaction.
     */
    public function placeholder(): string
    {
        return 'TMP-' . Str::uuid()->toString();
    }

    public function format(string $prefix, CarbonInterface $date, int $id): string
    {
        return sprintf('%s/%s/%0' . self::ID_PAD . 'd', $prefix, $date->format('Y'), $id);
    }

    /**
     * Assign the final number to a freshly created order (year comes from the order date).
     */
    public function assignOrderNumber(PurchaseOrder $order): void
    {
        $order->update([
            'order_number' => $this->format(self::ORDER_PREFIX, Carbon::parse($order->order_date), (int) $order->id),
        ]);
    }

    /**
     * Assign the final number to a freshly created invoice (year comes from the invoice date).
     */
    public function assignInvoiceNumber(PurchaseInvoice $invoice): void
    {
        $invoice->update([
            'invoice_number' => $this->format(self::INVOICE_PREFIX, Carbon::parse($invoice->invoice_date), (int) $invoice->id),
        ]);
    }
}
