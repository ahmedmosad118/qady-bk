<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expense;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expense\StoreExpenseCategoryRequest;
use App\Http\Requests\Expense\UpdateExpenseCategoryRequest;
use App\Models\ExpenseCategory;
use App\Repositories\ExpenseCategoryRepository;
use App\Services\Expense\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ExpenseCategoryController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ExpenseCategoryRepository $repository,
        protected ExpenseCategoryService $categoryService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.ExpenseCategory::class, only: ['store']),
            new Middleware('can:update,expenseCategory', only: ['update']),
        ];
    }

    /**
     * List expense categories (paginated or full dropdown list when all=1).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExpenseCategory::class);

        if ($request->boolean('all')) {
            return response()->json([
                'success' => true,
                'data' => $this->repository->listAll(),
            ]);
        }

        $filters = [
            'search' => $request->input('search'),
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
     * Store a newly created expense category.
     */
    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('expenses.category_created_successfully'),
            'data' => $category,
        ], 201);
    }

    /**
     * Display a single expense category with expense count.
     */
    public function show(ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->authorize('view', $expenseCategory);

        return response()->json([
            'success' => true,
            'data' => $this->repository->findWithCounts($expenseCategory->id),
        ]);
    }

    /**
     * Update an existing expense category.
     */
    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $updated = $this->categoryService->update($expenseCategory, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('expenses.category_updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Remove an unused expense category.
     */
    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->authorize('delete', $expenseCategory);

        $this->categoryService->delete($expenseCategory);

        return response()->json([
            'success' => true,
            'message' => __('expenses.category_deleted_successfully'),
        ]);
    }
}
