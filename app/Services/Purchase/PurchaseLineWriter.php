<?php

declare(strict_types=1);

namespace App\Services\Purchase;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceExpense;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderExpense;
use App\Models\PurchaseOrderItem;

/**
 * Writes the child rows of purchase documents with ONE bulk INSERT per table
 * (instead of one `create()` per line).
 *
 * `insert()` bypasses Eloquent casts and automatic timestamps, so this class does both explicitly:
 * the snapshot is JSON-encoded exactly like the `array` cast, and created_at/updated_at are filled.
 */
class PurchaseLineWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  Calculator item rows.
     */
    public function insertOrderItems(PurchaseOrder $order, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        PurchaseOrderItem::query()->insert($this->prepare($rows, ['purchase_order_id' => $order->id]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Calculator expense rows.
     */
    public function insertOrderExpenses(PurchaseOrder $order, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        PurchaseOrderExpense::query()->insert($this->prepare($rows, ['purchase_order_id' => $order->id]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Calculator item rows.
     */
    public function insertInvoiceItems(PurchaseInvoice $invoice, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        PurchaseInvoiceItem::query()->insert($this->prepare($rows, ['purchase_invoice_id' => $invoice->id]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Calculator expense rows.
     */
    public function insertInvoiceExpenses(PurchaseInvoice $invoice, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        PurchaseInvoiceExpense::query()->insert($this->prepare($rows, ['purchase_invoice_id' => $invoice->id]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $foreignKey
     * @return array<int, array<string, mixed>>
     */
    protected function prepare(array $rows, array $foreignKey): array
    {
        $now = now()->toDateTimeString();

        return array_map(function (array $row) use ($foreignKey, $now): array {
            if (isset($row['product_snapshot']) && is_array($row['product_snapshot'])) {
                $row['product_snapshot'] = json_encode($row['product_snapshot'], JSON_THROW_ON_ERROR);
            }

            return $foreignKey + $row + ['created_at' => $now, 'updated_at' => $now];
        }, array_values($rows));
    }
}
