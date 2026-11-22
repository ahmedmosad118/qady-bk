<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\PermissionRegistrar;

class UserService
{
    /**
     * Create a new user and optionally assign roles.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): User
    {
        $roles = ! empty($data['roles']) ? $this->resolveRoles($data['roles']) : new Collection;

        if ($actor !== null
            && ! $actor->isSuperAdmin()
            && $roles->contains(static fn (Role $role): bool => $role->isSuperAdmin())
        ) {
            throw new AuthorizationException((string) __('roles.only_super_admin_can_assign_super_admin'));
        }

        return DB::transaction(function () use ($data, $roles): User {
            $user = new User;
            $user->name = (string) $data['name'];
            $user->email = (string) $data['email'];
            $user->phone = $data['phone'] ?? null;
            $user->status = UserStatus::from((string) ($data['status'] ?? UserStatus::ACTIVE->value));
            $user->password = Hash::make((string) $data['password']);

            $user->save();

            if ($roles->isNotEmpty()) {
                $user->syncRoles($roles);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

            return $user->load('roles:id,name,is_super_admin,is_system');
        });
    }

    /**
     * Update an existing user, enforcing self-deactivation and last-Super-Admin protections.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data, ?User $actor = null): User
    {
        $newStatus = UserStatus::from((string) ($data['status'] ?? UserStatus::ACTIVE->value));

        if ($actor !== null && $actor->id === $user->id && $newStatus === UserStatus::INACTIVE) {
            throw ValidationException::withMessages([
                'status' => [__('auth.cannot_deactivate_self')],
            ]);
        }

        $syncRoles = array_key_exists('roles', $data);
        $newRoles = $syncRoles ? $this->resolveRoles($data['roles'] ?? []) : new Collection;

        if ($actor !== null
            && ! $actor->isSuperAdmin()
            && ($user->isSuperAdmin() || $newRoles->contains(static fn (Role $role): bool => $role->isSuperAdmin()))
        ) {
            throw new AuthorizationException((string) __('roles.only_super_admin_can_assign_super_admin'));
        }

        if ($syncRoles
            && $user->isSuperAdmin()
            && ! $newRoles->contains(static fn (Role $role): bool => $role->isSuperAdmin())
        ) {
            $otherSuperAdminExists = User::query()
                ->whereKeyNot($user->id)
                ->whereHas('roles', static fn ($q) => $q->where('is_super_admin', true))
                ->exists();

            if (! $otherSuperAdminExists) {
                throw ValidationException::withMessages([
                    'roles' => [__('roles.cannot_remove_last_super_admin')],
                ]);
            }
        }

        return DB::transaction(function () use ($user, $data, $actor, $newStatus, $syncRoles, $newRoles): User {
            $user->name = (string) $data['name'];
            $user->email = (string) $data['email'];
            $user->phone = $data['phone'] ?? null;
            $user->status = $newStatus;

            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = Hash::make((string) $data['password']);
            }

            $user->save();

            if ($syncRoles) {
                $user->syncRoles($newRoles);
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }

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

            return $user->load('roles:id,name,is_super_admin,is_system');
        });
    }

    /**
     * Delete users by IDs, ensuring self-deletion and last-Super-Admin protections.
     *
     * @param  array<int|string>  $ids
     */
    public function delete(array $ids, int $currentAuthId): int
    {
        $idArray = array_values(array_filter(array_map('intval', $ids)));

        if (in_array($currentAuthId, $idArray, true)) {
            throw ValidationException::withMessages([
                'ids' => [__('auth.cannot_delete_self')],
            ]);
        }

        $deletingSuperAdmin = User::query()
            ->whereIn('id', $idArray)
            ->whereHas('roles', static fn ($q) => $q->where('is_super_admin', true))
            ->exists();

        if ($deletingSuperAdmin) {
            $remainingSuperAdmin = User::query()
                ->whereNotIn('id', $idArray)
                ->whereHas('roles', static fn ($q) => $q->where('is_super_admin', true))
                ->exists();

            if (! $remainingSuperAdmin) {
                throw ValidationException::withMessages([
                    'ids' => [__('roles.cannot_remove_last_super_admin')],
                ]);
            }
        }

        return DB::transaction(function () use ($idArray): int {
            PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $idArray)
                ->delete();

            return User::whereIn('id', $idArray)->delete();
        });
    }

    /**
     * Resolve web-guard roles by IDs.
     *
     * @param  array<int|string>  $roleIds
     * @return Collection<int, Role>
     */
    private function resolveRoles(array $roleIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $roleIds)));

        if ($ids === []) {
            return new Collection;
        }

        return Role::query()
            ->where('guard_name', 'web')
            ->whereIn('id', $ids)
            ->get();
    }
}
