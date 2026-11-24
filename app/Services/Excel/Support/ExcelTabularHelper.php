<?php

declare(strict_types=1);

namespace App\Services\Excel\Support;

use Maatwebsite\Excel\Facades\Excel;

final class ExcelTabularHelper
{
    /**
     * Read all rows of the first worksheet as raw indexed arrays.
     *
     * @return list<array<int, mixed>>
     */
    public static function readSheetRows(string $filePath): array
    {
        $sheets = Excel::toArray(new RawArrayImport, $filePath);

        return array_values($sheets[0] ?? []);
    }

    /**
     * Match the uploaded header row against expected headings in either 'ar' or 'en'.
     *
     * @param  array<int, mixed>  $headerRow
     * @param  array<string, array{ar: string, en: string}>  $columnHeadings
     * @return array{locale: string, map: array<string, int>}|null
     */
    public static function resolveColumnMap(array $headerRow, array $columnHeadings): ?array
    {
        $normalizedHeader = array_map(
            static fn (mixed $cell): string => trim((string) $cell),
            array_values($headerRow)
        );

        foreach (['ar', 'en'] as $candidateLocale) {
            $expected = [];
            $fieldKeys = [];

            foreach ($columnHeadings as $field => $translations) {
                $expected[] = trim($translations[$candidateLocale]);
                $fieldKeys[] = $field;
            }

            $slice = array_slice($normalizedHeader, 0, count($expected));
            if ($slice === $expected) {
                $map = [];
                foreach ($fieldKeys as $index => $field) {
                    $map[$field] = $index;
                }

                return [
                    'locale' => $candidateLocale,
                    'map' => $map,
                ];
            }
        }

        return null;
    }

    /**
     * Check whether an entire spreadsheet row is empty after trimming.
     *
     * @param  array<int, mixed>  $row
     */
    public static function isRowEmpty(array $row, int $columnCount): bool
    {
        for ($i = 0; $i < $columnCount; $i++) {
            $value = $row[$i] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    public static function normalizeCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        $placeholders = [
            (string) __('excel.options.select_from_list', [], 'ar'),
            (string) __('excel.options.select_from_list', [], 'en'),
        ];

        if (in_array($trimmed, $placeholders, true)) {
            return null;
        }

        return $trimmed;
    }

    /**
     * Parse a boolean option accepting Arabic, English, or numeric representations.
     */
    public static function parseBoolean(?string $value, string $trueKey, string $falseKey): ?bool
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));

        $trueValues = array_map(
            static fn (string $token): string => mb_strtolower($token),
            [
                (string) __($trueKey, [], 'ar'),
                (string) __($trueKey, [], 'en'),
                '1',
                'true',
                'yes',
                'active',
                'نعم',
                'نشط',
            ]
        );

        if (in_array($normalized, $trueValues, true)) {
            return true;
        }

        $falseValues = array_map(
            static fn (string $token): string => mb_strtolower($token),
            [
                (string) __($falseKey, [], 'ar'),
                (string) __($falseKey, [], 'en'),
                '0',
                'false',
                'no',
                'inactive',
                'لا',
                'معطل',
            ]
        );

        if (in_array($normalized, $falseValues, true)) {
            return false;
        }

        return null;
    }

    public static function formatRowError(int $rowNumber, string $message, string $locale): string
    {
        return (string) __('excel.messages.row_error', [
            'row' => $rowNumber,
            'message' => $message,
        ], $locale);
    }
}
