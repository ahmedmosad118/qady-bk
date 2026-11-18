<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\PrintBarcodeLabelsRequest;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use App\Repositories\ProductRepository;
use App\Services\Product\ProductBarcodeLabelService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected ProductService $productService,
        protected ProductBarcodeLabelService $barcodeLabelService,
    ) {}

    /**
     * Display a paginated listing of products.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'ids' => $request->filled('ids') ? explode(',', (string) $request->input('ids')) : null,
            'category_id' => $request->input('category_id'),
            'is_active' => $request->input('is_active'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->productRepository->paginate($filters, $perPage);

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
     * Look up an active product by its barcode for scanner operations.
     */
    public function lookupBarcode(string $barcode): JsonResponse
    {
        $product = Product::query()
            ->with('category:id,name,parent_id')
            ->where('barcode', trim($barcode))
            ->where('is_active', true)
            ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'لم يتم العثور على أي كتاب أو صنف بهذا الباركود.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product,
        ]);
    }

    /**
     * Prepare barcode labels, generating and saving missing barcodes.
     */
    public function barcodeLabels(PrintBarcodeLabelsRequest $request): JsonResponse
    {
        $labels = $this->barcodeLabelService->prepare($request->validated('items'));

        return response()->json([
            'success' => true,
            'data' => $labels,
            'meta' => [
                'generated' => count(array_filter($labels, fn (array $l) => $l['barcode_generated'])),
            ],
        ]);
    }

    /**
     * Display the specified product.
     */
    public function show(Product $product): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $product->load('category:id,name,parent_id'),
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('crud.saved_successfully'),
            'data' => $product,
        ], 201);
    }

    /**
     * Update the specified product.
     */
    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->productService->update($product, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('crud.updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Soft-delete one or more products.
     */
    public function destroy(string $ids): JsonResponse
    {
        $idArray = array_values(array_filter(array_map('intval', explode(',', $ids))));

        if ($idArray === []) {
            abort(404);
        }

        $deleted = $this->productService->delete($idArray);

        if ($deleted === 0) {
            abort(404);
        }

        return response()->json([
            'success' => true,
            'message' => __('crud.deleted_successfully'),
            'meta' => ['deleted' => $deleted],
        ]);
    }
}
