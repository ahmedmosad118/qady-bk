<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExcelModuleType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Excel\ImportExcelRequest;
use App\Services\Excel\ExcelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelController extends Controller
{
    public function __construct(
        private readonly ExcelService $excelService,
    ) {}

    public function downloadTemplate(Request $request, ExcelModuleType $type): BinaryFileResponse
    {
        return $this->excelService->downloadTemplate(
            type: $type,
            requestedLocale: $request->header('X-Locale'),
        );
    }

    public function export(Request $request, ExcelModuleType $type): BinaryFileResponse
    {
        $filters = $request->only([
            'search',
            'category_id',
            'is_active',
            'show_in_shop',
            'scope',
            'sort_by',
            'sort_order',
        ]);

        return $this->excelService->export(
            type: $type,
            filters: $filters,
            requestedLocale: $request->header('X-Locale'),
        );
    }

    public function import(ImportExcelRequest $request, ExcelModuleType $type): JsonResponse
    {
        $result = $this->excelService->import(
            type: $type,
            file: $request->file('excel_file'),
            requestedLocale: $request->header('X-Locale'),
        );

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data' => [
                'imported' => $result['imported'],
            ],
        ]);
    }
}
