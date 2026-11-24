<?php

declare(strict_types=1);

namespace App\Services\Excel\Products;

use App\Models\ProductCategory;
use App\Services\Excel\Support\ExcelSheetStyler;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class ProductsTemplateExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    /**
     * @return array<string, array{ar: string, en: string}>
     */
    public static function columnHeadings(): array
    {
        return [
            'name' => [
                'ar' => (string) __('excel.products.columns.name', [], 'ar'),
                'en' => (string) __('excel.products.columns.name', [], 'en'),
            ],
            'barcode' => [
                'ar' => (string) __('excel.products.columns.barcode', [], 'ar'),
                'en' => (string) __('excel.products.columns.barcode', [], 'en'),
            ],
            'category' => [
                'ar' => (string) __('excel.products.columns.category', [], 'ar'),
                'en' => (string) __('excel.products.columns.category', [], 'en'),
            ],
            'author' => [
                'ar' => (string) __('excel.products.columns.author', [], 'ar'),
                'en' => (string) __('excel.products.columns.author', [], 'en'),
            ],
            'publisher' => [
                'ar' => (string) __('excel.products.columns.publisher', [], 'ar'),
                'en' => (string) __('excel.products.columns.publisher', [], 'en'),
            ],
            'edition' => [
                'ar' => (string) __('excel.products.columns.edition', [], 'ar'),
                'en' => (string) __('excel.products.columns.edition', [], 'en'),
            ],
            'publication_year' => [
                'ar' => (string) __('excel.products.columns.publication_year', [], 'ar'),
                'en' => (string) __('excel.products.columns.publication_year', [], 'en'),
            ],
            'purchase_price' => [
                'ar' => (string) __('excel.products.columns.purchase_price', [], 'ar'),
                'en' => (string) __('excel.products.columns.purchase_price', [], 'en'),
            ],
            'selling_price' => [
                'ar' => (string) __('excel.products.columns.selling_price', [], 'ar'),
                'en' => (string) __('excel.products.columns.selling_price', [], 'en'),
            ],
            'show_in_shop' => [
                'ar' => (string) __('excel.products.columns.show_in_shop', [], 'ar'),
                'en' => (string) __('excel.products.columns.show_in_shop', [], 'en'),
            ],
            'is_active' => [
                'ar' => (string) __('excel.products.columns.is_active', [], 'ar'),
                'en' => (string) __('excel.products.columns.is_active', [], 'en'),
            ],
        ];
    }

    /**
     * Build active category dropdown labels, disambiguating duplicate names with "Parent > Child".
     *
     * @return array{labels: list<string>, lookup: array<string, int>}
     */
    public static function activeCategoryLookup(): array
    {
        $categories = ProductCategory::query()
            ->active()
            ->with('parent:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $nameCounts = [];
        foreach ($categories as $category) {
            $key = mb_strtolower(trim($category->name));
            $nameCounts[$key] = ($nameCounts[$key] ?? 0) + 1;
        }

        $labels = [];
        $lookup = [];

        foreach ($categories as $category) {
            $trimmedName = trim($category->name);
            $key = mb_strtolower($trimmedName);
            /** @var ProductCategory|null $parent */
            $parent = $category->parent;
            $parentName = $parent ? trim($parent->name) : null;

            $label = ($nameCounts[$key] > 1 && $parentName !== null)
                ? "{$parentName} > {$trimmedName}"
                : $trimmedName;

            $labels[] = $label;
            $lookup[mb_strtolower($label)] = $category->id;

            if ($parentName !== null) {
                $lookup[mb_strtolower("{$parentName} > {$trimmedName}")] = $category->id;
            }
            if ($nameCounts[$key] === 1) {
                $lookup[$key] = $category->id;
            }
        }

        return [
            'labels' => array_values(array_unique($labels)),
            'lookup' => $lookup,
        ];
    }

    public function __construct(
        private readonly string $locale,
    ) {}

    public function title(): string
    {
        return mb_substr((string) __('excel.products.sheet_title', [], $this->locale), 0, 31);
    }

    public function collection(): Collection
    {
        return collect([[]]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_map(
            fn (array $translations): string => $translations[$this->locale] ?? $translations['en'],
            array_values(self::columnHeadings())
        );
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $categoryLabels = self::activeCategoryLookup()['labels'];
                $yesLabel = (string) __('excel.options.yes', [], $this->locale);
                $noLabel = (string) __('excel.options.no', [], $this->locale);
                $activeLabel = (string) __('excel.options.active', [], $this->locale);
                $inactiveLabel = (string) __('excel.options.inactive', [], $this->locale);
                $headings = self::columnHeadings();

                $columns = [
                    [
                        'heading' => $headings['name'][$this->locale] ?? $headings['name']['en'],
                        'width' => 32,
                        'type' => 'text',
                    ],
                    [
                        'heading' => $headings['barcode'][$this->locale] ?? $headings['barcode']['en'],
                        'width' => 22,
                        'type' => 'text',
                    ],
                    [
                        'heading' => $headings['category'][$this->locale] ?? $headings['category']['en'],
                        'width' => 26,
                        'type' => 'dropdown',
                        'options' => $categoryLabels,
                        'default' => null,
                    ],
                    [
                        'heading' => $headings['author'][$this->locale] ?? $headings['author']['en'],
                        'width' => 24,
                        'type' => 'text',
                    ],
                    [
                        'heading' => $headings['publisher'][$this->locale] ?? $headings['publisher']['en'],
                        'width' => 24,
                        'type' => 'text',
                    ],
                    [
                        'heading' => $headings['edition'][$this->locale] ?? $headings['edition']['en'],
                        'width' => 16,
                        'type' => 'text',
                    ],
                    [
                        'heading' => $headings['publication_year'][$this->locale] ?? $headings['publication_year']['en'],
                        'width' => 15,
                        'type' => 'integer',
                    ],
                    [
                        'heading' => $headings['purchase_price'][$this->locale] ?? $headings['purchase_price']['en'],
                        'width' => 15,
                        'type' => 'decimal',
                    ],
                    [
                        'heading' => $headings['selling_price'][$this->locale] ?? $headings['selling_price']['en'],
                        'width' => 15,
                        'type' => 'decimal',
                    ],
                    [
                        'heading' => $headings['show_in_shop'][$this->locale] ?? $headings['show_in_shop']['en'],
                        'width' => 16,
                        'type' => 'dropdown',
                        'options' => [$noLabel, $yesLabel],
                        'default' => $noLabel,
                    ],
                    [
                        'heading' => $headings['is_active'][$this->locale] ?? $headings['is_active']['en'],
                        'width' => 14,
                        'type' => 'dropdown',
                        'options' => [$activeLabel, $inactiveLabel],
                        'default' => $activeLabel,
                    ],
                ];

                ExcelSheetStyler::styleTemplateSheet(
                    sheet: $event->sheet->getDelegate(),
                    columns: $columns,
                    locale: $this->locale,
                );
            },
        ];
    }
}
