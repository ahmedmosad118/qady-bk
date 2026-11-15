<?php

declare(strict_types=1);

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case DRAFT = 'draft';
    case CONVERTED = 'converted';
    case CANCELLED = 'cancelled';

    /**
     * Get array of all string values.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Human-readable label in Arabic.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'مسودة',
            self::CONVERTED => 'تم التحويل لفاتورة',
            self::CANCELLED => 'ملغي',
        };
    }
}
