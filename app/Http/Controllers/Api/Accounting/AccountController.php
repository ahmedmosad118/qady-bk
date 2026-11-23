<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use App\Models\Account;
use App\Services\Accounting\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AccountController extends Controller implements HasMiddleware
{
    public function __construct(
        protected AccountService $accountService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.Account::class, only: ['store']),
            new Middleware('can:update,account', only: ['update']),
        ];
    }

    /**
     * Return virtual tree structure (AccountClasses -> AccountTypes with account counts).
     */
    public function tree(): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        return response()->json([
            'success' => true,
            'data' => $this->accountService->getTree(),
        ]);
    }

    /**
     * Display a paginated listing of accounts with server-side filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $filters = [
            'account_class_id' => $request->input('account_class_id'),
            'account_type_id' => $request->input('account_type_id'),
            'is_active' => $request->input('is_active'),
            'search' => $request->input('search'),
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
        ]);
    }

    /**
     * Store a new account.
     */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.account_created'),
            'data' => $this->accountService->getDetails($account),
        ], 201);
    }

    /**
     * Display details for a specific account.
     */
    public function show(Account $account): JsonResponse
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
    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $updated = $this->accountService->update($account, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.account_updated'),
            'data' => $this->accountService->getDetails($updated),
        ]);
    }

    /**
     * Delete an unused non-system account.
     */
    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $this->accountService->delete($account);

        return response()->json([
            'success' => true,
            'message' => __('accounting.messages.account_deleted'),
        ]);
    }
}
