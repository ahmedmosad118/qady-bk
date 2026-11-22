<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseStockController extends Controller
{
    public function __construct(
        protected StockMovementService $stockMovementService,
    ) {}

    /**
     * List current warehouse stock balances with filtering and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WarehouseStock::class);

        $filters = [
            'warehouse_id' => $request->input('warehouse_id'),
            'product_id' => $request->input('product_id'),
            'search' => $request->input('search'),
            'has_stock' => $request->input('has_stock'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->stockMovementService->paginateWarehouseStocks($filters, $perPage);

        // Stats summary across current filtered scope
        $statsQuery = WarehouseStock::query()
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where('warehouse_id', (int) $filters['warehouse_id']))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('product_id', (int) $filters['product_id']))
            ->when(! empty($filters['search']), function ($q) use ($filters): void {
                $q->whereHas('product', function ($sub) use ($filters): void {
                    $search = $filters['search'];
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%");
                });
            });

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
}
