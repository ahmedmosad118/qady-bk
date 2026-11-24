<?php

declare(strict_types=1);

namespace App\Services\Excel;

use App\Enums\ExcelModuleType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelService
{
    public function __construct(
        private readonly ExcelModuleRegistry $registry,
    ) {}

    public function downloadTemplate(ExcelModuleType $type, ?string $requestedLocale = null): BinaryFileResponse
    {
        $handler = $this->registry->resolve($type);
        Gate::authorize($handler->importAbility(), $handler->modelClass());

        $locale = $this->resolveLocale($requestedLocale);

        return Excel::download(
            $handler->makeTemplate($locale),
            $type->templateFilename()
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function export(ExcelModuleType $type, array $filters = [], ?string $requestedLocale = null): BinaryFileResponse
    {
        $handler = $this->registry->resolve($type);
        Gate::authorize($handler->exportAbility(), $handler->modelClass());

        $locale = $this->resolveLocale($requestedLocale);

        return Excel::download(
            $handler->makeExporter($filters, $locale),
            $type->exportFilename()
        );
    }

    /**
     * @return array{imported: int, message: string}
     */
    public function import(ExcelModuleType $type, UploadedFile $file, ?string $requestedLocale = null): array
    {
        $handler = $this->registry->resolve($type);
        Gate::authorize($handler->importAbility(), $handler->modelClass());

        $locale = $this->resolveLocale($requestedLocale);
        $extension = $file->getClientOriginalExtension() ?: 'xlsx';
        $relativePath = 'temp/excel/'.Str::uuid().'.'.$extension;

        Storage::disk('local')->putFileAs(
            dirname($relativePath),
            $file,
            basename($relativePath)
        );

        $fullPath = Storage::disk('local')->path($relativePath);

        try {
            $result = $handler->import($fullPath, $locale);
        } finally {
            Storage::disk('local')->delete($relativePath);
        }

        return [
            'imported' => $result['imported'],
            'message' => (string) __('excel.messages.import_success', [
                'count' => $result['imported'],
            ], $locale),
        ];
    }

    private function resolveLocale(?string $requestedLocale): string
    {
        if (is_string($requestedLocale) && in_array($requestedLocale, ['ar', 'en'], true)) {
            return $requestedLocale;
        }

        $current = app()->getLocale();

        return in_array($current, ['ar', 'en'], true) ? $current : 'ar';
    }
}
