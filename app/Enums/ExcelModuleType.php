<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Product;
use App\Models\ProductCategory;

enum ExcelModuleType: string
{
    case Products = 'products';
    case ProductCategories = 'product-categories';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return class-string
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::Products => Product::class,
            self::ProductCategories => ProductCategory::class,
        };
    }

    public function templateFilename(): string
    {
        return match ($this) {
            self::Products => 'products_template.xlsx',
            self::ProductCategories => 'product_categories_template.xlsx',
        };
    }

    public function exportFilename(): string
    {
        $date = now()->format('Y-m-d');

        return match ($this) {
            self::Products => "products_export_{$date}.xlsx",
            self::ProductCategories => "product_categories_export_{$date}.xlsx",
        };
    }
}
