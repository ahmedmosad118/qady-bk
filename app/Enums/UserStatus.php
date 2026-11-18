<?php

declare(strict_types=1);

namespace App\Enums;

enum UserStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

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
     * Determine whether this status allows user authentication and API access.
     */
    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Human-readable label in Arabic.
     */
    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'مفعل',
            self::INACTIVE => 'غير مفعل',
        };
    }
}
