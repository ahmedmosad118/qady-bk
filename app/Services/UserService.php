<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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
            $user->status = (string) ($data['status'] ?? 'active');

            $password = !empty($data['password']) ? (string) $data['password'] : '12345678';
            $user->password = Hash::make($password);

            $user->save();

            return $user;
        });
    }

    /**
     * Update an existing user.
     *
     * @param array<string, mixed> $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user->name = (string) $data['name'];
            $user->email = (string) $data['email'];
            $user->phone = $data['phone'] ?? null;
            $user->status = (string) ($data['status'] ?? 'active');

            if (!empty($data['password'])) {
                $user->password = Hash::make((string) $data['password']);
            }

            $user->save();

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
                'ids' => ['لا يمكنك حذف حسابك الحالي.'],
            ]);
        }

        return DB::transaction(function () use ($idArray) {
            return User::whereIn('id', $idArray)->delete();
        });
    }
}
