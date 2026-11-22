<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionCategory: string
{
    case POS = 'pos';
    case CATALOG = 'catalog';
    case CONTACTS = 'contacts';
    case PURCHASES = 'purchases';
    case INVENTORY = 'inventory';
    case EXPENSES = 'expenses';
    case ACCOUNTING = 'accounting';
    case ADMINISTRATION = 'administration';

    /**
     * Get all enum string values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Display order for the category in the permissions matrix.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::POS => 10,
            self::CATALOG => 20,
            self::CONTACTS => 30,
            self::PURCHASES => 40,
            self::INVENTORY => 50,
            self::EXPENSES => 60,
            self::ACCOUNTING => 70,
            self::ADMINISTRATION => 80,
        };
    }

    /**
     * Localized human-readable category label.
     */
    public function label(): string
    {
        return (string) __("permissions.categories.{$this->value}");
    }
}
