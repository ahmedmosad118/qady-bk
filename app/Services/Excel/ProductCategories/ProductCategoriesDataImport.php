<?php

declare(strict_types=1);

namespace App\Services\Excel\ProductCategories;

use App\Models\ProductCategory;
use App\Services\Excel\Support\ExcelTabularHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductCategoriesDataImport
{
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

        $headingsSpec = ProductCategoriesTemplateExport::columnHeadings();
        $resolved = ExcelTabularHelper::resolveColumnMap($rows[0], $headingsSpec);

        if ($resolved === null) {
            throw ValidationException::withMessages([
                'excel_file' => [(string) __('excel.messages.pattern_mismatch', [], $locale)],
            ]);
        }

        $map = $resolved['map'];
        $columnCount = count($headingsSpec);
        $dataRows = array_slice($rows, 1);

        $parsedRows = $this->parseRows($dataRows, $map, $columnCount, $locale);

        if ($parsedRows === [] && $this->errors === []) {
            throw ValidationException::withMessages([
                'excel_file' => [(string) __('excel.messages.empty_file', [], $locale)],
            ]);
        }

        $validated = $this->validateRows($parsedRows, $locale);

        if ($this->errors !== []) {
            throw ValidationException::withMessages([
                'excel_file' => $this->errors,
            ]);
        }

        $this->persistRows($validated);

        return ['imported' => count($validated)];
    }

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param  list<array<int, mixed>>  $dataRows
     * @param  array<string, int>  $map
     * @return list<array{rowNumber: int, name: ?string, parentName: ?string, isActive: ?bool}>
     */
    private function parseRows(array $dataRows, array $map, int $columnCount, string $locale): array
    {
        $this->errors = [];
        $parsed = [];

        $nameFieldLabel = (string) __('excel.product_categories.columns.name', [], $locale);
        $statusFieldLabel = (string) __('excel.product_categories.columns.is_active', [], $locale);

        foreach ($dataRows as $offset => $row) {
            $rowNumber = $offset + 2;

            if (ExcelTabularHelper::isRowEmpty($row, $columnCount)) {
                continue;
            }

            $name = ExcelTabularHelper::normalizeCell($row[$map['name']] ?? null);
            $parentName = ExcelTabularHelper::normalizeCell($row[$map['parent']] ?? null);
            $statusRaw = ExcelTabularHelper::normalizeCell($row[$map['is_active']] ?? null);

            if ($parentName !== null && in_array($parentName, [
                (string) __('excel.options.main_category', [], 'ar'),
                (string) __('excel.options.main_category', [], 'en'),
            ], true)) {
                $parentName = null;
            }

            if ($name === null) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.required', ['field' => $nameFieldLabel], $locale),
                    $locale
                );
            } elseif (mb_strlen($name) > 150) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.max_length', ['field' => $nameFieldLabel, 'max' => 150], $locale),
                    $locale
                );
            }

            $isActive = ExcelTabularHelper::parseBoolean(
                $statusRaw,
                'excel.options.active',
                'excel.options.inactive'
            );

            if ($statusRaw === null) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.required', ['field' => $statusFieldLabel], $locale),
                    $locale
                );
            } elseif ($isActive === null) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.invalid_boolean', ['field' => $statusFieldLabel], $locale),
                    $locale
                );
            }

            if ($name !== null && $parentName !== null && mb_strtolower($name) === mb_strtolower($parentName)) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.parent_invalid', [], $locale),
                    $locale
                );
            }

            $parsed[] = [
                'rowNumber' => $rowNumber,
                'name' => $name,
                'parentName' => $parentName,
                'isActive' => $isActive ?? true,
            ];
        }

        return $parsed;
    }

    /**
     * @param  list<array{rowNumber: int, name: ?string, parentName: ?string, isActive: bool}>  $parsedRows
     * @return list<array{name: string, parent_id: ?int, in_file_parent: ?string, is_active: bool}>
     */
    private function validateRows(array $parsedRows, string $locale): array
    {
        $existingCategories = ProductCategory::query()
            ->select('id', 'name', 'parent_id')
            ->get();

        $dbTopLevelByKey = [];
        $dbSubcategoryKeys = [];
        $dbPairKeys = [];

        foreach ($existingCategories as $category) {
            $nameKey = mb_strtolower(trim($category->name));
            $parentId = $category->parent_id;
            $dbPairKeys[$nameKey.'|'.($parentId ?? 0)] = true;

            if ($parentId === null) {
                $dbTopLevelByKey[$nameKey] = $category->id;
            } else {
                $dbSubcategoryKeys[$nameKey] = true;
            }
        }

        $fileTopLevelNames = [];
        $fileSubcategoryNames = [];

        foreach ($parsedRows as $row) {
            if ($row['name'] === null) {
                continue;
            }

            $key = mb_strtolower($row['name']);
            if ($row['parentName'] === null) {
                $fileTopLevelNames[$key] = $row['name'];
            } else {
                $fileSubcategoryNames[$key] = true;
            }
        }

        $seenFilePairs = [];
        $validated = [];

        foreach ($parsedRows as $row) {
            if ($row['name'] === null) {
                continue;
            }

            $rowNumber = $row['rowNumber'];
            $name = $row['name'];
            $nameKey = mb_strtolower($name);
            $parentName = $row['parentName'];

            $dbParentId = null;
            $inFileParent = null;

            if ($parentName !== null) {
                $parentKey = mb_strtolower($parentName);

                if (isset($dbTopLevelByKey[$parentKey])) {
                    $dbParentId = $dbTopLevelByKey[$parentKey];
                } elseif (isset($fileTopLevelNames[$parentKey])) {
                    $inFileParent = $parentKey;
                } elseif (isset($dbSubcategoryKeys[$parentKey]) || isset($fileSubcategoryNames[$parentKey])) {
                    $this->errors[] = ExcelTabularHelper::formatRowError(
                        $rowNumber,
                        (string) __('excel.messages.parent_must_be_top_level', ['name' => $parentName], $locale),
                        $locale
                    );

                    continue;
                } else {
                    $this->errors[] = ExcelTabularHelper::formatRowError(
                        $rowNumber,
                        (string) __('excel.messages.parent_not_found', ['name' => $parentName], $locale),
                        $locale
                    );

                    continue;
                }
            }

            $fileScope = $dbParentId !== null
                ? 'db:'.$dbParentId
                : ($inFileParent !== null ? 'file:'.$inFileParent : 'root');
            $filePairKey = $nameKey.'|'.$fileScope;

            if (isset($seenFilePairs[$filePairKey])) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.duplicate_category_file', ['name' => $name], $locale),
                    $locale
                );

                continue;
            }

            $seenFilePairs[$filePairKey] = true;

            if ($inFileParent === null && isset($dbPairKeys[$nameKey.'|'.($dbParentId ?? 0)])) {
                $this->errors[] = ExcelTabularHelper::formatRowError(
                    $rowNumber,
                    (string) __('excel.messages.duplicate_category_db', ['name' => $name], $locale),
                    $locale
                );

                continue;
            }

            $validated[] = [
                'name' => $name,
                'parent_id' => $dbParentId,
                'in_file_parent' => $inFileParent,
                'is_active' => $row['isActive'],
            ];
        }

        return $validated;
    }

    /**
     * @param  list<array{name: string, parent_id: ?int, in_file_parent: ?string, is_active: bool}>  $rows
     */
    private function persistRows(array $rows): void
    {
        DB::transaction(function () use ($rows): void {
            $now = now();
            $topLevelInserts = [];
            $childRows = [];

            foreach ($rows as $row) {
                if ($row['parent_id'] === null && $row['in_file_parent'] === null) {
                    $topLevelInserts[] = [
                        'name' => $row['name'],
                        'parent_id' => null,
                        'is_active' => $row['is_active'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                } else {
                    $childRows[] = $row;
                }
            }

            if ($topLevelInserts !== []) {
                foreach (array_chunk($topLevelInserts, 500) as $chunk) {
                    ProductCategory::query()->insert($chunk);
                }
            }

            if ($childRows === []) {
                return;
            }

            $inFileParentsMap = [];
            $neededParentKeys = array_values(array_unique(array_filter(array_column($childRows, 'in_file_parent'))));

            if ($neededParentKeys !== []) {
                $newTopLevels = ProductCategory::query()
                    ->whereNull('parent_id')
                    ->select('id', 'name')
                    ->get();

                foreach ($newTopLevels as $cat) {
                    $inFileParentsMap[mb_strtolower(trim($cat->name))] = $cat->id;
                }
            }

            $childInserts = [];
            foreach ($childRows as $row) {
                $parentId = $row['parent_id'] ?? $inFileParentsMap[$row['in_file_parent']] ?? null;
                $childInserts[] = [
                    'name' => $row['name'],
                    'parent_id' => $parentId,
                    'is_active' => $row['is_active'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($childInserts, 500) as $chunk) {
                ProductCategory::query()->insert($chunk);
            }
        });
    }
}
