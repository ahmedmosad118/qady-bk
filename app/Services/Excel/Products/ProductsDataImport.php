<?php

declare(strict_types=1);

namespace App\Services\Excel\Products;

use App\Models\Product;
use App\Services\Excel\Support\ExcelTabularHelper;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductsDataImport
{
    private const string MAX_PRICE = '9999999999';

    /** @var list<string> */
    private array $errors = [];

    /**
     * @return array{imported: int}
     */
    public function import(string $filePath, string $locale): array
    {
        $rows = ExcelTabularHelper::readSheetRows($filePath);

        if ($rows === []) {
            throw ValidationException::withMessages([
                'excel_file' => [(string) __('excel.messages.empty_file', [], $locale)],
            ]);
        }

        $headingsSpec = ProductsTemplateExport::columnHeadings();
        $resolved = ExcelTabularHelper::resolveColumnMap($rows[0], $headingsSpec);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'excel_file' => [(string) __('excel.messages.pattern_mismatch', [], $locale)],
            ]);
        }

        $map = $resolved['map'];
        $columnCount = count($headingsSpec);
        $dataRows = array_slice($rows, 1);

        $validated = $this->validateAndPrepareRows($dataRows, $map, $columnCount, $locale);

        if ($this->errors !== []) {
            throw ValidationException::withMessages([
                'excel_file' => $this->errors,
            ]);
        }

        if ($validated === []) {
            throw ValidationException::withMessages([
                'excel_file' => [(string) __('excel.messages.empty_file', [], $locale)],
            ]);
        }

        $this->persistRows($validated);

        return ['imported' => count($validated)];
    }

    /**
     * @param  list<array<int, mixed>>  $dataRows
     * @param  array<string, int>  $map
     * @return list<array<string, mixed>>
     */
    private function validateAndPrepareRows(array $dataRows, array $map, int $columnCount, string $locale): array
    {
        $this->errors = [];

        $nonEmptyRows = [];
        $candidateBarcodes = [];

        foreach ($dataRows as $offset => $row) {
            $rowNumber = $offset + 2;
            if (ExcelTabularHelper::isRowEmpty($row, $columnCount)) {
                continue;
            }

            $barcode = $this->normalizeBarcode($row[$map['barcode']] ?? null);
            if ($barcode !== null) {
                $candidateBarcodes[] = $barcode;
            }

            $nonEmptyRows[] = [
                'rowNumber' => $rowNumber,
                'row' => $row,
                'barcode' => $barcode,
            ];
        }

        if ($nonEmptyRows === []) {
            return [];
        }

        $existingBarcodes = [];
        if ($candidateBarcodes !== []) {
            $dbBarcodes = Product::withTrashed()
                ->whereIn('barcode', array_values(array_unique($candidateBarcodes)))
                ->pluck('barcode')
                ->all();

            foreach ($dbBarcodes as $dbBarcode) {
                $existingBarcodes[(string) $dbBarcode] = true;
            }
        }

        $categoryLookup = ProductsTemplateExport::activeCategoryLookup()['lookup'];
        $seenBarcodes = [];
        $prepared = [];

        $labels = [
            'name' => (string) __('excel.products.columns.name', [], $locale),
            'barcode' => (string) __('excel.products.columns.barcode', [], $locale),
            'author' => (string) __('excel.products.columns.author', [], $locale),
            'publisher' => (string) __('excel.products.columns.publisher', [], $locale),
            'edition' => (string) __('excel.products.columns.edition', [], $locale),
            'publication_year' => (string) __('excel.products.columns.publication_year', [], $locale),
            'purchase_price' => (string) __('excel.products.columns.purchase_price', [], $locale),
            'selling_price' => (string) __('excel.products.columns.selling_price', [], $locale),
            'show_in_shop' => (string) __('excel.products.columns.show_in_shop', [], $locale),
            'is_active' => (string) __('excel.products.columns.is_active', [], $locale),
        ];

        $uncategorizedTokens = [
            mb_strtolower((string) __('excel.options.uncategorized', [], 'ar')),
            mb_strtolower((string) __('excel.options.uncategorized', [], 'en')),
        ];

        foreach ($nonEmptyRows as $entry) {
            $rowNumber = $entry['rowNumber'];
            $row = $entry['row'];
            $barcode = $entry['barcode'];
            $rowHasError = false;

            $name = ExcelTabularHelper::normalizeCell($row[$map['name']] ?? null);
            $categoryRaw = ExcelTabularHelper::normalizeCell($row[$map['category']] ?? null);
            $author = ExcelTabularHelper::normalizeCell($row[$map['author']] ?? null);
            $publisher = ExcelTabularHelper::normalizeCell($row[$map['publisher']] ?? null);
            $edition = ExcelTabularHelper::normalizeCell($row[$map['edition']] ?? null);
            $yearRaw = ExcelTabularHelper::normalizeCell($row[$map['publication_year']] ?? null);
            $purchaseRaw = ExcelTabularHelper::normalizeCell($row[$map['purchase_price']] ?? null);
            $sellingRaw = ExcelTabularHelper::normalizeCell($row[$map['selling_price']] ?? null);
            $shopRaw = ExcelTabularHelper::normalizeCell($row[$map['show_in_shop']] ?? null);
            $statusRaw = ExcelTabularHelper::normalizeCell($row[$map['is_active']] ?? null);

            if ($name === null) {
                $this->addError($rowNumber, (string) __('excel.messages.required', ['field' => $labels['name']], $locale), $locale);
                $rowHasError = true;
            } elseif (mb_strlen($name) > 255) {
                $this->addError($rowNumber, (string) __('excel.messages.max_length', ['field' => $labels['name'], 'max' => 255], $locale), $locale);
                $rowHasError = true;
            }

            if ($barcode !== null) {
                if (mb_strlen($barcode) > 100) {
                    $this->addError($rowNumber, (string) __('excel.messages.max_length', ['field' => $labels['barcode'], 'max' => 100], $locale), $locale);
                    $rowHasError = true;
                } elseif (isset($existingBarcodes[$barcode])) {
                    $this->addError($rowNumber, (string) __('excel.messages.duplicate_barcode_db', ['barcode' => $barcode], $locale), $locale);
                    $rowHasError = true;
                } elseif (isset($seenBarcodes[$barcode])) {
                    $this->addError($rowNumber, (string) __('excel.messages.duplicate_barcode_file', ['barcode' => $barcode], $locale), $locale);
                    $rowHasError = true;
                } else {
                    $seenBarcodes[$barcode] = true;
                }
            }

            $categoryId = null;
            if ($categoryRaw !== null && ! in_array(mb_strtolower($categoryRaw), $uncategorizedTokens, true)) {
                $categoryKey = mb_strtolower($categoryRaw);
                if (! isset($categoryLookup[$categoryKey])) {
                    $this->addError($rowNumber, (string) __('excel.messages.category_not_found', ['name' => $categoryRaw], $locale), $locale);
                    $rowHasError = true;
                } else {
                    $categoryId = $categoryLookup[$categoryKey];
                }
            }

            foreach (['author' => $author, 'publisher' => $publisher] as $key => $val) {
                if ($val !== null && mb_strlen($val) > 255) {
                    $this->addError($rowNumber, (string) __('excel.messages.max_length', ['field' => $labels[$key], 'max' => 255], $locale), $locale);
                    $rowHasError = true;
                }
            }

            if ($edition !== null && mb_strlen($edition) > 100) {
                $this->addError($rowNumber, (string) __('excel.messages.max_length', ['field' => $labels['edition'], 'max' => 100], $locale), $locale);
                $rowHasError = true;
            }

            $publicationYear = null;
            if ($yearRaw !== null) {
                if (! preg_match('/^\d+$/', $yearRaw)) {
                    $this->addError($rowNumber, (string) __('excel.messages.invalid_integer', ['field' => $labels['publication_year']], $locale), $locale);
                    $rowHasError = true;
                } else {
                    $yearInt = (int) $yearRaw;
                    if ($yearInt < 1000 || $yearInt > 2100) {
                        $this->addError($rowNumber, (string) __('excel.messages.range_between', ['field' => $labels['publication_year'], 'min' => 1000, 'max' => 2100], $locale), $locale);
                        $rowHasError = true;
                    } else {
                        $publicationYear = $yearInt;
                    }
                }
            }

            $purchasePrice = $this->validatePrice($purchaseRaw, $labels['purchase_price'], $rowNumber, $locale);
            if ($purchasePrice === null) {
                $rowHasError = true;
            }

            $sellingPrice = $this->validatePrice($sellingRaw, $labels['selling_price'], $rowNumber, $locale);
            if ($sellingPrice === null) {
                $rowHasError = true;
            }

            if ($purchasePrice !== null && $sellingPrice !== null) {
                if (Decimal::comp($sellingPrice, $purchasePrice, Decimal::MONEY_SCALE) <= 0) {
                    $this->addError($rowNumber, (string) __('excel.messages.selling_price_gt_purchase', [], $locale), $locale);
                    $rowHasError = true;
                }
            }

            $showInShop = false;
            if ($shopRaw !== null) {
                $parsedShop = ExcelTabularHelper::parseBoolean($shopRaw, 'excel.options.yes', 'excel.options.no');
                if ($parsedShop === null) {
                    $this->addError($rowNumber, (string) __('excel.messages.invalid_boolean', ['field' => $labels['show_in_shop']], $locale), $locale);
                    $rowHasError = true;
                } else {
                    $showInShop = $parsedShop;
                }
            }

            $isActive = ExcelTabularHelper::parseBoolean($statusRaw, 'excel.options.active', 'excel.options.inactive');
            if ($statusRaw === null) {
                $this->addError($rowNumber, (string) __('excel.messages.required', ['field' => $labels['is_active']], $locale), $locale);
                $rowHasError = true;
            } elseif ($isActive === null) {
                $this->addError($rowNumber, (string) __('excel.messages.invalid_boolean', ['field' => $labels['is_active']], $locale), $locale);
                $rowHasError = true;
            }

            if (! $rowHasError) {
                $prepared[] = [
                    'category_id' => $categoryId,
                    'name' => $name,
                    'barcode' => $barcode,
                    'author' => $author,
                    'publisher' => $publisher,
                    'edition' => $edition,
                    'publication_year' => $publicationYear,
                    'cover_image' => null,
                    'purchase_price' => $purchasePrice,
                    'selling_price' => $sellingPrice,
                    'is_active' => $isActive,
                    'show_in_shop' => $showInShop,
                ];
            }
        }

        return $prepared;
    }

    private function validatePrice(?string $raw, string $fieldLabel, int $rowNumber, string $locale): ?string
    {
        if ($raw === null) {
            $this->addError($rowNumber, (string) __('excel.messages.required', ['field' => $fieldLabel], $locale), $locale);

            return null;
        }

        $parsed = Decimal::parse($raw);
        if ($parsed === null) {
            $this->addError($rowNumber, (string) __('excel.messages.invalid_number', ['field' => $fieldLabel], $locale), $locale);

            return null;
        }

        $normalized = Decimal::round($parsed, Decimal::MONEY_SCALE);

        if (Decimal::comp($normalized, '0', Decimal::MONEY_SCALE) < 0) {
            $this->addError($rowNumber, (string) __('excel.messages.min_value', ['field' => $fieldLabel, 'min' => 0], $locale), $locale);

            return null;
        }

        if (Decimal::comp($normalized, self::MAX_PRICE, Decimal::MONEY_SCALE) > 0) {
            $this->addError($rowNumber, (string) __('excel.messages.max_value', ['field' => $fieldLabel, 'max' => self::MAX_PRICE], $locale), $locale);

            return null;
        }

        return $normalized;
    }

    private function normalizeBarcode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            return fmod($value, 1.0) === 0.0
                ? number_format($value, 0, '', '')
                : (string) $value;
        }

        return ExcelTabularHelper::normalizeCell($value);
    }

    private function addError(int $rowNumber, string $message, string $locale): void
    {
        $this->errors[] = ExcelTabularHelper::formatRowError($rowNumber, $message, $locale);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persistRows(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            $now = now();

            $payload = array_map(
                static fn (array $row): array => [
                    ...$row,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $rows
            );

            foreach (array_chunk($payload, 500) as $chunk) {
                Product::query()->insert($chunk);
            }
        });
    }
}
