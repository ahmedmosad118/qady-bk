<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\UserSchemaProvider;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected UserService $userService,
        protected UserSchemaProvider $schemaProvider,
    ) {}

    /**
     * Display a listing of users with server-side pagination, search, and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'search' => $request->input('search'),
            'status' => $request->input('status'),
            'sort_by' => $request->input('sort_by'),
            'sort_order' => $request->input('sort_order'),
        ];

        $perPage = $request->integer('per_page', 15);
        $paginated = $this->userRepository->paginate($filters, $perPage);

        $items = collect($paginated->items())->map(function (User $user) {
            $data = $user->toArray();
            $data['avatar_url'] = 'https://ui-avatars.com/api/?background=00C896&color=fff&name='.urlencode($user->name);

            return $data;
        })->all();

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
            'stats' => $this->userRepository->getStats(),
            'schema' => $this->schemaProvider->getSchema(),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => __('crud.saved_successfully'),
            'data' => $user,
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
            'schema' => $this->schemaProvider->getSchema(),
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $updated = $this->userService->update($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => __('crud.updated_successfully'),
            'data' => $updated,
        ]);
    }

    /**
     * Delete one or more users.
     */
    public function destroy(string $ids): JsonResponse
    {
        $idArray = explode(',', $ids);
        $this->userService->delete($idArray, (int) auth()->id());

        return response()->json([
            'success' => true,
            'message' => __('crud.deleted_successfully'),
        ]);
    }
}
