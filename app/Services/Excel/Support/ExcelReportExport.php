<?php

declare(strict_types=1);

namespace App\Services\Excel\Support;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelReportExport implements FromCollection, WithColumnFormatting, WithEvents, WithHeadings, WithStrictNullComparison, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $headings
     * @param  Collection<int, mixed>  $rows
     * @param  array<string, string>  $formats
     */
    public function __construct(
        private readonly string $sheetTitle,
        private readonly array $headings,
        private readonly Collection $rows,
        private readonly string $locale,
        private readonly array $formats = [],
    ) {}

    public function title(): string
    {
        return mb_substr($this->sheetTitle, 0, 31);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->headings;
    }

    /**
     * @return array<string, string>
     */
    public function columnFormats(): array
    {
        return $this->formats;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        $styles = [];
        $count = count($this->headings);

        for ($i = 1; $i <= $count; $i++) {
            $column = Coordinate::stringFromColumnIndex($i);
            $styles["{$column}1"] = [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                    'size' => 11,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '8B1E2B'],
                ],
            ];
        }

        return $styles;
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft($this->locale === 'ar');
                $sheet->getRowDimension(1)->setRowHeight(24);
                $sheet->freezePane('A2');

                $count = count($this->headings);
                for ($i = 1; $i <= $count; $i++) {
                    $column = Coordinate::stringFromColumnIndex($i);
                    $sheet->getColumnDimension($column)->setAutoSize(true);
                }
            },
        ];
    }
}
