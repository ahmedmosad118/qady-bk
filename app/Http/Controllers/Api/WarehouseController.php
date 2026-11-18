<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Warehouse\UpdateWarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    /**
     * Display a listing of active warehouses (for document select inputs).
     */
    public function index(Request $request): JsonResponse
    {
        $warehouses = Warehouse::query()
            ->when($request->boolean('active_only', true), function ($q): void {
                $q->active();
            })
            ->orderBy('is_default', 'desc')
            ->orderBy('name', 'asc')
            ->get(['id', 'code', 'name', 'address', 'is_default', 'is_active']);

        return response()->json([
            'success' => true,
            'data' => $warehouses,
        ]);
    }

    /**
     * Get the default warehouse.
     */
    public function defaultWarehouse(): JsonResponse
    {
        $warehouse = Warehouse::query()->where('is_default', true)->first();

        if (! $warehouse) {
            $warehouse = Warehouse::query()->first();
        }

        if (! $warehouse) {
            return response()->json([
                'success' => false,
                'message' => __('inventory.errors.warehouse_not_found'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $warehouse,
        ]);
    }

    /**
     * Display the specified warehouse.
     */
    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $warehouse,
        ]);
    }

    /**
     * Update the specified warehouse.
     */
    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validated();

        $warehouse->update([
            'code' => $validated['code'],
            'name' => $validated['name'],
            'address' => $validated['address'],
            'phone' => $validated['phone'] ?? null,
            'manager_name' => $validated['manager_name'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : $warehouse->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('inventory.warehouse_updated_successfully'),
            'data' => $warehouse->fresh(),
        ]);
    }
}
