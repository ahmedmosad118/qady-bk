<?php

declare(strict_types=1);

namespace App\Enums;

enum InventoryDocumentStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case POSTED = 'posted';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case REVERSED = 'reversed';

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
