<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreRoleRequest;
use App\Http\Requests\Access\UpdateRoleRequest;
use App\Models\Role;
use App\Services\Access\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class RoleController extends Controller implements HasMiddleware
{
    public function __construct(
        protected RoleService $roleService,
    ) {}

    /**
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,'.Role::class, only: ['store']),
            new Middleware('can:update,role', only: ['update']),
        ];
    }

    /**
     * List roles (all for dropdowns when ?all=1, otherwise paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        if ($request->boolean('all')) {
            return $this->successResponse($this->roleService->listAll());
        }

        $filters = [
            'search' => $request->input('search'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $paginated = $this->roleService->paginate($filters, $request->integer('per_page', 15));

        return $this->paginatedResponse($paginated);
    }

    /**
     * Create a new custom role with assigned permissions.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated(), $request->user());

        return $this->successResponse($role, (string) __('roles.created'), 201);
    }

    /**
     * Show role details including its granted permissions.
     */
    public function show(Role $role): JsonResponse
    {
        $this->authorize('view', $role);

        return $this->successResponse($this->roleService->findWithDetails($role));
    }

    /**
     * Update an existing role and sync its permissions.
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roleService->update($role, $request->validated());

        return $this->successResponse($updated, (string) __('roles.updated'));
    }

    /**
     * Delete a custom role.
     */
    public function destroy(Role $role): JsonResponse
    {
        $this->authorize('delete', $role);

        $this->roleService->delete($role);

        return $this->successResponse(null, (string) __('roles.deleted'));
    }
}
