<?php

declare(strict_types=1);

namespace App\Enums;

enum StocktakeStatus: string
{
    case DRAFT = 'draft';
    case IN_PROGRESS = 'in_progress';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case COMPLETED = 'completed';

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
     * Check if this status locks the warehouse from having another active stocktake.
     * Per requirement 24: in_progress, pending_approval, approved, rejected (if resubmitted).
     */
    public function isActiveLock(): bool
    {
        return in_array($this, [
            self::IN_PROGRESS,
            self::PENDING_APPROVAL,
            self::APPROVED,
            self::REJECTED,
        ], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'مسودة',
            self::IN_PROGRESS => 'قيد التنفيذ',
            self::PENDING_APPROVAL => 'قيد الاعتماد',
            self::APPROVED => 'معتمد',
            self::REJECTED => 'مرفوض',
            self::CANCELLED => 'ملغي',
            self::COMPLETED => 'مكتمل',
        };
    }
}
