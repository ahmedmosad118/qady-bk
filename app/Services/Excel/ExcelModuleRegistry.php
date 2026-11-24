<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Enums\ExcelModuleType;
use App\Services\Excel\Contracts\ExcelModuleHandler;
use App\Services\Excel\ProductCategories\ProductCategoriesExcelHandler;
use App\Services\Excel\Products\ProductsExcelHandler;

class ExcelModuleRegistry
{
    public function resolve(ExcelModuleType $type): ExcelModuleHandler
    {
        return match ($type) {
            ExcelModuleType::Products => app(ProductsExcelHandler::class),
            ExcelModuleType::ProductCategories => app(ProductCategoriesExcelHandler::class),
        };
    }
}
