<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductCategoryRequest;
use App\Http\Requests\Product\UpdateProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\ProductCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class ProductCategoryController extends Controller implements HasMiddleware
{
    public function __construct(
        protected ProductCategoryService $categoryService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.ProductCategory::class, only: ['store']),
            new Middleware('can:update,category', only: ['update']),
        ];
    }

    /**
     * Display the category tree.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProductCategory::class);

        if ($request->filled('per_page')) {
            $paginated = ProductCategory::query()
                ->when($request->filled('search'), function ($q) use ($request): void {
                    $search = (string) $request->input('search');
                    $q->where('name', 'like', "%{$search}%");
                })
                ->orderBy('name', 'asc')
                ->paginate($request->integer('per_page', 10), ['id', 'name', 'parent_id']);

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

        return response()->json([
            'success' => true,
            'data' => $this->categoryService->getTree(),
        ]);
    }

    /**
     * Store a newly created category.
     */
    public function store(StoreProductCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('crud.saved_successfully'),
            'data' => $category,
        ], 201);
    }

    /**
     * Update the specified category.
     */
    public function update(UpdateProductCategoryRequest $request, ProductCategory $category): JsonResponse
    {
        $updated = $this->categoryService->update($category, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('crud.updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Delete one or more categories.
     */
    public function destroy(string $ids): JsonResponse
    {
        $this->authorize('delete', ProductCategory::class);

        $idArray = array_values(array_filter(array_map('intval', explode(',', $ids))));

        if ($idArray === []) {
            abort(404);
        }

        if (ProductCategory::whereIn('id', $idArray)->count() === 0) {
            abort(404);
        }

        try {
            $deleted = $this->categoryService->delete($idArray);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => __('products.delete_has_children'),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('crud.deleted_successfully'),
            'meta' => ['deleted' => $deleted],
        ]);
    }
}
