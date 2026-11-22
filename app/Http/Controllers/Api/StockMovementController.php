<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockCardRequest;
use App\Models\WarehouseStock;
use App\Services\Inventory\StockMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class StockMovementController extends Controller implements HasMiddleware
{
    public function __construct(
        protected StockMovementService $stockMovementService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewMovements,'.WarehouseStock::class, only: ['stockCard']),
        ];
    }

    /**
     * Read-only historical ledger of stock movements.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewMovements', WarehouseStock::class);

        $filters = [
            'warehouse_id' => $request->input('warehouse_id'),
            'product_id' => $request->input('product_id'),
            'direction' => $request->input('direction'),
            'document_id' => $request->input('document_id'),
            'search' => $request->input('search'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->stockMovementService->paginateMovements($filters, $perPage);

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
     * Product Stock Card / running movement ledger in a specific warehouse.
     */
    public function stockCard(StockCardRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $card = $this->stockMovementService->getStockCard(
            (int) $validated['product_id'],
            (int) $validated['warehouse_id'],
            $validated
        );

        return response()->json([
            'success' => true,
            'data' => $card,
        ]);
    }
}
