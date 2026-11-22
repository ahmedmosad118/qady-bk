<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate a user and issue a Sanctum personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        if (! $user->isActive()) {
            return response()->json([
                'success' => false,
                'message' => __('auth.inactive'),
            ], 403);
        }

        // Prune excessively old tokens if count exceeds limit to avoid table bloat without terminating active concurrent tabs.
        if ($user->tokens()->where('name', 'spa')->count() > 15) {
            $user->tokens()->where('name', 'spa')->oldest()->limit(5)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => __('auth.login_success'),
            'data' => [
                'token' => $user->createToken('spa')->plainTextToken,
                'user' => $this->payload($user),
            ],
        ]);
    }

    /**
     * Return the currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->payload($request->user()),
            ],
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'success' => true,
            'message' => __('auth.logged_out'),
        ]);
    }

    /**
     * Serialize the authenticated user for the SPA auth store.
     */
    protected function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status instanceof UserStatus ? $user->status->value : $user->status,
            'avatar_url' => $user->avatar_url,
            'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
            'permissions' => method_exists($user, 'getAllPermissions')
                ? $user->getAllPermissions()->pluck('name')
                : [],
        ];
    }
}
