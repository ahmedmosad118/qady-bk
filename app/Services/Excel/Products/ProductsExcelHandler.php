<?php

declare(strict_types=1);

namespace App\Services\Excel\Products;

use App\Enums\ExcelModuleType;
use App\Models\Product;
use App\Services\Excel\Contracts\ExcelModuleHandler;
use Maatwebsite\Excel\Concerns\Export;

class ProductsExcelHandler implements ExcelModuleHandler
{
    public function __construct(
        private readonly ProductsDataExport $exporter,
        private readonly ProductsDataImport $importer,
    ) {}

    public function type(): ExcelModuleType
    {
        return ExcelModuleType::Products;
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
        return Product::class;
    }

    public function makeTemplate(string $locale): Export
    {
        return new ProductsTemplateExport($locale);
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
