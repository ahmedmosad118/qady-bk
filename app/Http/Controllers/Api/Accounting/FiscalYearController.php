<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreFiscalYearRequest;
use App\Http\Requests\Accounting\UpdateFiscalYearRequest;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalYearController extends Controller
{
    public function __construct(
        protected FiscalYearService $fiscalYearService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->fiscalYearService->paginate($filters, $request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function store(StoreFiscalYearRequest $request): JsonResponse
    {
        $fiscalYear = $this->fiscalYearService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.fiscal_year_created'),
            'data' => $fiscalYear,
        ], 201);
    }

    public function show(FiscalYear $fiscalYear): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $fiscalYear
                ->loadCount('accountingPeriods')
                ->load(['accountingPeriods' => static fn ($q) => $q->orderBy('period_number')]),
        ]);
    }

    public function update(UpdateFiscalYearRequest $request, FiscalYear $fiscalYear): JsonResponse
    {
        $updated = $this->fiscalYearService->update($fiscalYear, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.fiscal_year_updated'),
            'data' => $updated,
        ]);
    }

    public function destroy(FiscalYear $fiscalYear): JsonResponse
    {
        $this->fiscalYearService->delete($fiscalYear);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.fiscal_year_deleted'),
        ]);
    }
}
