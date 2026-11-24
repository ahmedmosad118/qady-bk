<?php

declare(strict_types=1);

namespace App\Services\Excel\ProductCategories;

use App\Enums\ExcelModuleType;
use App\Models\ProductCategory;
use App\Services\Excel\Contracts\ExcelModuleHandler;
use Maatwebsite\Excel\Concerns\Export;

class ProductCategoriesExcelHandler implements ExcelModuleHandler
{
    public function __construct(
        private readonly ProductCategoriesDataExport $exporter,
        private readonly ProductCategoriesDataImport $importer,
    ) {}

    public function type(): ExcelModuleType
    {
        return ExcelModuleType::ProductCategories;
    }

    public function exportAbility(): string
    {
        return 'viewAny';
    }

    public function importAbility(): string
    {
        return 'create';
    }

    public function modelClass(): string
    {
        return ProductCategory::class;
    }

    public function makeTemplate(string $locale): Export
    {
        return new ProductCategoriesTemplateExport($locale);
    }

    public function makeExporter(array $filters, string $locale): Export
    {
        return $this->exporter->build($filters, $locale);
    }

    public function import(string $filePath, string $locale): array
    {
        return $this->importer->import($filePath, $locale);
    }
}
