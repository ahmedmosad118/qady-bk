<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreChartOfAccountRequest;
use App\Http\Requests\Accounting\UpdateChartOfAccountRequest;
use App\Models\ChartOfAccount;
use App\Services\Accounting\ChartOfAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ChartOfAccountController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ChartOfAccountService $accountService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.ChartOfAccount::class, only: ['store']),
            new Middleware('can:update,account', only: ['update']),
        ];
    }

    /**
     * Display a paginated listing of single-level accounts with server-side filters and stats.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChartOfAccount::class);

        $filters = [
            'search' => $request->input('search'),
            'account_type' => $request->input('account_type'),
            'account_purpose' => $request->input('account_purpose'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->accountService->paginate($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'stats' => $this->accountService->stats(),
        ]);
    }

    /**
     * Store a new account in the Chart of Accounts.
     */
    public function store(StoreChartOfAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.account_created'),
            'data' => $account,
        ], 201);
    }

    /**
     * Display details for a specific account.
     */
    public function show(ChartOfAccount $account): JsonResponse
    {
        $this->authorize('view', $account);

        return response()->json([
            'success' => true,
            'data' => $this->accountService->getDetails($account),
        ]);
    }

    /**
     * Update an existing account.
     */
    public function update(UpdateChartOfAccountRequest $request, ChartOfAccount $account): JsonResponse
    {
        $updated = $this->accountService->update($account, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.account_updated'),
            'data' => $updated,
        ]);
    }

    /**
     * Delete an unused account.
     */
    public function destroy(ChartOfAccount $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $this->accountService->delete($account);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.account_deleted'),
        ]);
    }
}
