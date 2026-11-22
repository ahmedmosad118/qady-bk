<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Models\Warehouse;
use App\Services\Inventory\WarehouseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class WarehouseController extends Controller implements HasMiddleware
{
    public function __construct(
        protected WarehouseService $warehouseService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:update,warehouse', only: ['update']),
        ];
    }

    /**
     * Display a listing of active warehouses (for document select inputs).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);

        $result = $this->warehouseService->list(
            [
                'active_only' => $request->input('active_only', true),
                'search' => $request->input('search'),
            ],
            $request->filled('per_page') ? $request->integer('per_page', 10) : null
        );

        if ($result instanceof LengthAwarePaginator) {
            return $this->paginatedResponse($result);
        }

        return $this->successResponse($result);
    }

    /**
     * Get the default warehouse.
     */
    public function defaultWarehouse(): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);

        $warehouse = $this->warehouseService->findDefaultOrFirst();

        if (! $warehouse) {
            return $this->errorResponse((string) __('inventory.errors.warehouse_not_found'), 404);
        }

        return $this->successResponse($warehouse);
    }

    /**
     * Display the specified warehouse.
     */
    public function show(Warehouse $warehouse): JsonResponse
    {
        $this->authorize('view', $warehouse);

        return $this->successResponse($warehouse);
    }

    /**
     * Update the specified warehouse.
     */
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $updated = $this->warehouseService->update($warehouse, $request->validated());

        return $this->successResponse(
            $updated,
            (string) __('inventory.warehouse_updated_successfully')
        );
    }
}
