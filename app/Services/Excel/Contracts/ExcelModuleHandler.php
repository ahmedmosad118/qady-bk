<?php

declare(strict_types=1);

namespace App\Services\Excel\Contracts;

use App\Enums\ExcelModuleType;
use Maatwebsite\Excel\Concerns\Export;

interface ExcelModuleHandler
{
    public function type(): ExcelModuleType;

    public function exportAbility(): string;

    public function importAbility(): string;

    /**
     * @return class-string
     */
    public function modelClass(): string;

    public function makeTemplate(string $locale): Export;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function makeExporter(array $filters, string $locale): Export;

    /**
     * @return array{imported: int}
     */
    public function import(string $filePath, string $locale): array;
}
