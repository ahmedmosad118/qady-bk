<?php

declare(strict_types=1);

namespace App\Services\Excel\ProductCategories;

use App\Models\ProductCategory;
use App\Services\Excel\Support\ExcelReportExport;
use App\Services\Excel\Support\ExcelSheetStyler;
use Illuminate\Database\Eloquent\Builder;

class ProductCategoriesDataExport
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function build(array $filters, string $locale): ExcelReportExport
    {
        $query = ProductCategory::query()
            ->with('parent:id,name')
            ->withCount('products');

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('parent', fn (Builder $pq) => $pq->where('name', 'like', "%{$search}%"));
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (($filters['scope'] ?? null) === 'top') {
            $query->whereNull('parent_id');
        } elseif (($filters['scope'] ?? null) === 'sub') {
            $query->whereNotNull('parent_id');
        }

        $categories = $query
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('name')
            ->get();

        $activeLabel = (string) __('excel.options.active', [], $locale);
        $inactiveLabel = (string) __('excel.options.inactive', [], $locale);
        $mainCategoryLabel = (string) __('excel.options.main_category', [], $locale);

        $rows = $categories->toBase()->map(static function (ProductCategory $category) use ($activeLabel, $inactiveLabel, $mainCategoryLabel): array {
            /** @var ProductCategory|null $parent */
            $parent = $category->parent;

            return [
                ExcelSheetStyler::sanitizeText($category->name),
                $parent ? ExcelSheetStyler::sanitizeText($parent->name) : $mainCategoryLabel,
                $category->is_active ? $activeLabel : $inactiveLabel,
                (int) $category->products_count,
            ];
        });

        $headings = [
            (string) __('excel.product_categories.columns.name', [], $locale),
            (string) __('excel.product_categories.columns.parent', [], $locale),
            (string) __('excel.product_categories.columns.is_active', [], $locale),
            (string) __('excel.product_categories.columns.products_count', [], $locale),
        ];

        return new ExcelReportExport(
            sheetTitle: (string) __('excel.product_categories.sheet_title', [], $locale),
            headings: $headings,
            rows: $rows,
            locale: $locale,
        );
    }
}
