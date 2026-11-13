<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryDocumentType: string
{
    case GOODS_RECEIPT = 'goods_receipt';
    case GOODS_DELIVERY = 'goods_delivery';
    case STOCK_ISSUE = 'stock_issue';
    case WAREHOUSE_TRANSFER = 'warehouse_transfer';
    case STOCK_ADJUSTMENT = 'stock_adjustment';
    case INVENTORY_RETURN = 'inventory_return';
    case STOCK_WRITE_OFF = 'stock_write_off';
    case OPENING_BALANCE = 'opening_balance';

    /**
     * Get array of all string values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
