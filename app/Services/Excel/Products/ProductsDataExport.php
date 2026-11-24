<?php

declare(strict_types=1);

namespace App\Services\Excel\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Repositories\ProductRepository;
use App\Services\Excel\Support\ExcelReportExport;
use App\Services\Excel\Support\ExcelSheetStyler;
use App\Support\Decimal;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class ProductsDataExport
{
    public function __construct(
        private readonly ProductRepository $productRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function build(array $filters, string $locale): ExcelReportExport
    {
        $filters['sort_by'] = $filters['sort_by'] ?? 'name';
        $filters['sort_order'] = $filters['sort_order'] ?? 'asc';

        $products = $this->productRepository
            ->filteredQuery($filters)
            ->get();

        $yesLabel = (string) __('excel.options.yes', [], $locale);
        $noLabel = (string) __('excel.options.no', [], $locale);
        $activeLabel = (string) __('excel.options.active', [], $locale);
        $inactiveLabel = (string) __('excel.options.inactive', [], $locale);
        $uncategorizedLabel = (string) __('excel.options.uncategorized', [], $locale);

        $rows = $products->toBase()->map(static function (Product $product) use (
            $yesLabel,
            $noLabel,
            $activeLabel,
            $inactiveLabel,
            $uncategorizedLabel
        ): array {
            $stockQty = $product->warehouse_stocks_sum_quantity ?? 0;
            /** @var ProductCategory|null $category */
            $category = $product->category;

            return [
                ExcelSheetStyler::sanitizeText($product->name),
                $product->barcode !== null ? ExcelSheetStyler::sanitizeText($product->barcode) : '',
                $category ? ExcelSheetStyler::sanitizeText($category->name) : $uncategorizedLabel,
                ExcelSheetStyler::sanitizeText($product->author ?? ''),
                ExcelSheetStyler::sanitizeText($product->publisher ?? ''),
                ExcelSheetStyler::sanitizeText($product->edition ?? ''),
                $product->publication_year !== null ? (int) $product->publication_year : '',
                Decimal::fromNumeric($product->purchase_price, Decimal::MONEY_SCALE),
                Decimal::fromNumeric($product->selling_price, Decimal::MONEY_SCALE),
                Decimal::fromNumeric($stockQty, Decimal::QUANTITY_SCALE),
                $product->show_in_shop ? $yesLabel : $noLabel,
                $product->is_active ? $activeLabel : $inactiveLabel,
            ];
        });

        $headings = [
            (string) __('excel.products.columns.name', [], $locale),
            (string) __('excel.products.columns.barcode', [], $locale),
            (string) __('excel.products.columns.category', [], $locale),
            (string) __('excel.products.columns.author', [], $locale),
            (string) __('excel.products.columns.publisher', [], $locale),
            (string) __('excel.products.columns.edition', [], $locale),
            (string) __('excel.products.columns.publication_year', [], $locale),
            (string) __('excel.products.columns.purchase_price', [], $locale),
            (string) __('excel.products.columns.selling_price', [], $locale),
            (string) __('excel.products.columns.stock_quantity', [], $locale),
            (string) __('excel.products.columns.show_in_shop', [], $locale),
            (string) __('excel.products.columns.is_active', [], $locale),
        ];

        return new ExcelReportExport(
            sheetTitle: (string) __('excel.products.sheet_title', [], $locale),
            headings: $headings,
            rows: $rows,
            locale: $locale,
            formats: [
                'B' => NumberFormat::FORMAT_TEXT,
                'H' => '0.00',
                'I' => '0.00',
            ],
        );
    }
}
