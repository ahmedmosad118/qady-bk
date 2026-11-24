<?php

declare(strict_types=1);

namespace App\Services\Excel\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class ExcelSheetStyler
{
    public const int TEMPLATE_VALIDATION_ROWS = 200;

    /**
     * Neutralize Excel formula injection on untrusted text cells.
     */
    public static function sanitizeText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        $firstChar = substr($text, 0, 1);
        if (in_array($firstChar, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * Style a template worksheet with RTL/LTR direction, header colors, formats, and dropdowns.
     *
     * @param  array<int, array{
     *     heading: string,
     *     width: int,
     *     type: string,
     *     options?: list<string>,
     *     default?: string|null
     * }>  $columns
     */
    public static function styleTemplateSheet(
        Worksheet $sheet,
        array $columns,
        string $locale,
        int $validationRows = self::TEMPLATE_VALIDATION_ROWS,
    ): void {
        $sheet->setRightToLeft($locale === 'ar');

        $hiddenOffset = 0;

        foreach (array_values($columns) as $index => $columnSpec) {
            $colLetter = Coordinate::stringFromColumnIndex($index + 1);
            $width = $columnSpec['width'];
            $type = $columnSpec['type'];

            $sheet->getCell("{$colLetter}1")->setValue($columnSpec['heading']);
            $sheet->getColumnDimension($colLetter)->setWidth($width);
            $sheet->getRowDimension(1)->setRowHeight(24);

            $sheet->getStyle("{$colLetter}1")->applyFromArray([
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
            ]);

            if ($type === 'text') {
                $sheet->getStyle("{$colLetter}2:{$colLetter}{$validationRows}")
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
            } elseif ($type === 'integer') {
                $sheet->getStyle("{$colLetter}2:{$colLetter}{$validationRows}")
                    ->getNumberFormat()
                    ->setFormatCode('0');
            } elseif ($type === 'decimal') {
                $sheet->getStyle("{$colLetter}2:{$colLetter}{$validationRows}")
                    ->getNumberFormat()
                    ->setFormatCode('0.00');
            } elseif ($type === 'dropdown') {
                $options = $columnSpec['options'] ?? [];
                $default = $columnSpec['default'] ?? null;

                self::applyDropdown(
                    sheet: $sheet,
                    colLetter: $colLetter,
                    options: $options,
                    defaultValue: $default,
                    locale: $locale,
                    validationRows: $validationRows,
                    hiddenOffset: $hiddenOffset,
                );

                if (self::needsRangeSheet($options)) {
                    $hiddenOffset++;
                }
            }
        }

        $sheet->freezePane('A2');
    }

    /**
     * @param  list<string>  $options
     */
    private static function applyDropdown(
        Worksheet $sheet,
        string $colLetter,
        array $options,
        ?string $defaultValue,
        string $locale,
        int $validationRows,
        int $hiddenOffset,
    ): void {
        if ($options === []) {
            return;
        }

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setPromptTitle((string) __('excel.validation_prompt.list_title', [], $locale));
        $validation->setPrompt((string) __('excel.validation_prompt.list_message', [], $locale));
        $validation->setErrorTitle((string) __('excel.validation_prompt.list_error_title', [], $locale));
        $validation->setError((string) __('excel.validation_prompt.list_error_message', [], $locale));

        if (self::needsRangeSheet($options)) {
            $spreadsheet = $sheet->getParent();
            $listsSheetTitle = 'Lists';
            $listsSheet = $spreadsheet->getSheetByName($listsSheetTitle);

            if ($listsSheet === null) {
                $listsSheet = $spreadsheet->createSheet();
                $listsSheet->setTitle($listsSheetTitle);
            }

            $listColumn = Coordinate::stringFromColumnIndex(1 + $hiddenOffset);

            foreach ($options as $optionIndex => $optionValue) {
                $rowNumber = $optionIndex + 1;
                $listsSheet->getCell("{$listColumn}{$rowNumber}")->setValue($optionValue);
            }

            $endRow = count($options);
            $validation->setFormula1("{$listsSheetTitle}!\${$listColumn}\$1:\${$listColumn}\${$endRow}");
        } else {
            $escaped = array_map(
                static fn (string $option): string => str_replace('"', '""', $option),
                $options
            );
            $validation->setFormula1('"'.implode(',', $escaped).'"');
        }

        if ($defaultValue !== null && $defaultValue !== '') {
            $sheet->getCell("{$colLetter}2")->setValue($defaultValue);
        }

        $sheet->setDataValidation("{$colLetter}2:{$colLetter}{$validationRows}", $validation);
    }

    /**
     * Excel inline list validation supports up to 255 characters and forbids commas inside items.
     *
     * @param  list<string>  $options
     */
    private static function needsRangeSheet(array $options): bool
    {
        if (mb_strlen(implode(',', $options)) > 240) {
            return true;
        }

        foreach ($options as $option) {
            if (str_contains($option, ',') || str_contains($option, '"')) {
                return true;
            }
        }

        return false;
    }
}
