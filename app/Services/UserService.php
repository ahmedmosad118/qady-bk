<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class UserService
{
    /**
     * Create a new user.
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = new User();
            $user->name = (string) $data['name'];
            $user->email = (string) $data['email'];
            $user->phone = $data['phone'] ?? null;
            $user->status = UserStatus::from((string) ($data['status'] ?? UserStatus::ACTIVE->value));
            $user->password = Hash::make((string) $data['password']);

            $user->save();

            return $user;
        });
    }

    /**
     * Update an existing user, enforcing self-deactivation protection and revoking tokens as needed.
     *
     * @param array<string, mixed> $data
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        $newStatus = UserStatus::from((string) ($data['status'] ?? UserStatus::ACTIVE->value));

        if ($actor !== null && $actor->id === $user->id && $newStatus === UserStatus::INACTIVE) {
            throw ValidationException::withMessages([
                'status' => [__('auth.cannot_deactivate_self')],
            ]);
        }

        return DB::transaction(function () use ($user, $data, $actor, $newStatus) {
            $user->name = (string) $data['name'];
            $user->email = (string) $data['email'];
            $user->phone = $data['phone'] ?? null;
            $user->status = $newStatus;

            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = Hash::make((string) $data['password']);
            }

            $user->save();

            if ($newStatus === UserStatus::INACTIVE) {
                $user->tokens()->delete();
            } elseif ($passwordChanged) {
                $currentToken = $actor !== null && $actor->id === $user->id
                    ? $actor->currentAccessToken()
                    : null;

                if ($currentToken instanceof PersonalAccessToken) {
                    $user->tokens()->whereKeyNot($currentToken->getKey())->delete();
                } else {
                    $user->tokens()->delete();
                }
            }

            return $user;
        });
    }

    /**
     * Delete users by IDs, ensuring the authenticated user cannot delete their own account.
     *
     * @param array<int|string> $ids
     */
    public function delete(array $ids, int $currentAuthId): int
    {
        $idArray = array_values(array_filter(array_map('intval', $ids)));

        if (in_array($currentAuthId, $idArray, true)) {
            throw ValidationException::withMessages([
                'ids' => [__('auth.cannot_delete_self')],
            ]);
        }

        return DB::transaction(function () use ($idArray) {
            PersonalAccessToken::query()
                ->where('tokenable_type', (new User())->getMorphClass())
                ->whereIn('tokenable_id', $idArray)
                ->delete();

            return User::whereIn('id', $idArray)->delete();
        });
    }
}
