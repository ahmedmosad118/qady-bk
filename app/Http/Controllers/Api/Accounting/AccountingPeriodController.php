<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountingPeriodRequest;
use App\Http\Requests\Accounting\UpdateAccountingPeriodRequest;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingPeriodController extends Controller
{
    public function __construct(
        protected AccountingPeriodService $accountingPeriodService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'fiscal_year_id' => $request->input('fiscal_year_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->accountingPeriodService->paginate($filters, $request->integer('per_page', 50));

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

    public function store(StoreAccountingPeriodRequest $request): JsonResponse
    {
        $period = $this->accountingPeriodService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.accounting_period_created'),
            'data' => $period,
        ], 201);
    }

    public function show(AccountingPeriod $accountingPeriod): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $accountingPeriod->load(['fiscalYear:id,code,name,start_date,end_date,status', 'closedBy:id,name']),
        ]);
    }

    public function update(UpdateAccountingPeriodRequest $request, AccountingPeriod $accountingPeriod): JsonResponse
    {
        $updated = $this->accountingPeriodService->update($accountingPeriod, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.accounting_period_updated'),
            'data' => $updated,
        ]);
    }

    public function destroy(AccountingPeriod $accountingPeriod): JsonResponse
    {
        $this->accountingPeriodService->delete($accountingPeriod);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.accounting_period_deleted'),
        ]);
    }
}
