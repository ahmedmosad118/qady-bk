<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Access;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\Access\PermissionMatrixService;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    public function __construct(
        protected PermissionMatrixService $matrixService,
    ) {}

    /**
     * Return all permissions grouped by category and module with localized labels.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewPermissions', Role::class);

        $matrix = $this->matrixService->getMatrix();

        return response()->json([
            'success' => true,
            'data' => $matrix['categories'],
            'meta' => [
                'total' => $matrix['total_permissions'],
            ],
        ]);
    }
}
