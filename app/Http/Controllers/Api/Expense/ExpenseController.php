<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\StoreExpenseRequest;
use App\Http\Requests\Expense\UpdateExpenseRequest;
use App\Models\Expense;
use App\Repositories\ExpenseRepository;
use App\Services\Expense\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ExpenseController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ExpenseRepository $repository,
        protected ExpenseService $expenseService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.Expense::class, only: ['store']),
            new Middleware('can:update,'.Expense::class, only: ['update']),
        ];
    }

    /**
     * Display a paginated listing of expenses with filters and sorting.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $filters = [
            'search' => $request->input('search'),
            'expense_category_id' => $request->input('expense_category_id'),
            'contact_id' => $request->input('contact_id'),
            'status' => $request->input('status'),
            'expense_date_from' => $request->input('expense_date_from'),
            'expense_date_to' => $request->input('expense_date_to'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->repository->paginate($filters, $perPage);

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
     * Store a newly created Draft expense with items and optional attachments.
     */
    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenseService->createExpense(
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('expenses.created_successfully'),
            'data' => $expense,
        ], 201);
    }

    /**
     * Display a single expense with category, contact, items, and attachments.
     */
    public function show(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return response()->json([
            'success' => true,
            'data' => $this->repository->findWithRelations($expense->id),
        ]);
    }

    /**
     * Update an existing Draft expense, its items, and its attachments.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): JsonResponse
    {
        $updated = $this->expenseService->updateExpense(
            $expense,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => __('expenses.updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Delete a Draft expense along with its items and attachment files.
     */
    public function destroy(Expense $expense): JsonResponse
    {
        $this->authorize('delete', Expense::class);

        $this->expenseService->deleteExpense($expense);

        return response()->json([
            'success' => true,
            'message' => __('expenses.deleted_successfully'),
        ]);
    }
}
