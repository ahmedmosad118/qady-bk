<?php

declare(strict_types=1);

namespace App\Services\Excel\ProductCategories;

use App\Models\ProductCategory;
use App\Services\Excel\Support\ExcelSheetStyler;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

class ProductCategoriesTemplateExport implements FromCollection, WithEvents, WithHeadings, WithTitle
{
    /**
     * @return array<string, array{ar: string, en: string}>
     */
    public static function columnHeadings(): array
    {
        return [
            'name' => [
                'ar' => (string) __('excel.product_categories.columns.name', [], 'ar'),
                'en' => (string) __('excel.product_categories.columns.name', [], 'en'),
            ],
            'parent' => [
                'ar' => (string) __('excel.product_categories.columns.parent', [], 'ar'),
                'en' => (string) __('excel.product_categories.columns.parent', [], 'en'),
            ],
            'is_active' => [
                'ar' => (string) __('excel.product_categories.columns.is_active', [], 'ar'),
                'en' => (string) __('excel.product_categories.columns.is_active', [], 'en'),
            ],
        ];
    }

    public function __construct(
        private readonly string $locale,
    ) {}

    public function title(): string
    {
        return mb_substr((string) __('excel.product_categories.sheet_title', [], $this->locale), 0, 31);
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
                $parentNames = ProductCategory::query()
                    ->whereNull('parent_id')
                    ->orderBy('name')
                    ->pluck('name')
                    ->values()
                    ->all();

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
                        'heading' => $headings['parent'][$this->locale] ?? $headings['parent']['en'],
                        'width' => 30,
                        'type' => 'dropdown',
                        'options' => $parentNames,
                        'default' => null,
                    ],
                    [
                        'heading' => $headings['is_active'][$this->locale] ?? $headings['is_active']['en'],
                        'width' => 16,
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
