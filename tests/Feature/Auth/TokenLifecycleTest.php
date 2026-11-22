<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_token_returns_401(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $token = $user->createToken('spa')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('data.tenant');

        $expirationMinutes = (int) config('sanctum.expiration');
        $this->assertSame(720, $expirationMinutes);

        Auth::forgetGuards();
        $this->travel($expirationMinutes + 1)->minutes();

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_inactive_user_existing_token_returns_401_and_deletes_token_row(): void
    {
        $user = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $newToken = $user->createToken('spa');
        $tokenId = $newToken->accessToken->id;

        DB::table('users')
            ->where('id', $user->id)
            ->update(['status' => UserStatus::INACTIVE->value]);

        $response = $this->withToken($newToken->plainTextToken)->getJson('/api/auth/me');

        $response->assertUnauthorized()
            ->assertJson([
                'success' => false,
                'code' => 'account_inactive',
            ]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_deactivating_a_user_revokes_all_their_tokens(): void
    {
        $admin = $this->createSuperAdmin(['status' => UserStatus::ACTIVE]);
        $target = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $token1 = $target->createToken('spa')->plainTextToken;
        $target->createToken('mobile');

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'status' => UserStatus::INACTIVE->value,
            ])
            ->assertOk();

        $this->assertSame(0, $target->tokens()->count());

        Auth::forgetGuards();
        $this->withToken($token1)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_password_change_revokes_other_tokens_but_keeps_current_token(): void
    {
        $user = $this->createSuperAdmin(['status' => UserStatus::ACTIVE]);
        $currentToken = $user->createToken('spa-current');
        $otherToken = $user->createToken('spa-other');

        $this->withToken($currentToken->plainTextToken)
            ->putJson("/api/users/{$user->id}", [
                'name' => $user->name,
                'email' => $user->email,
                'status' => UserStatus::ACTIVE->value,
                'password' => 'NewPass123',
            ])
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        Auth::forgetGuards();
        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($otherToken->plainTextToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_admin_changing_another_users_password_revokes_all_their_tokens(): void
    {
        $admin = $this->createSuperAdmin(['status' => UserStatus::ACTIVE]);
        $target = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $token = $target->createToken('spa');

        $this->actingAs($admin)
            ->putJson("/api/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'status' => UserStatus::ACTIVE->value,
                'password' => 'AdminSet123',
            ])
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_deleting_a_user_removes_their_tokens(): void
    {
        $admin = $this->createSuperAdmin(['status' => UserStatus::ACTIVE]);
        $target = User::factory()->create(['status' => UserStatus::ACTIVE]);
        $token = $target->createToken('spa');

        $this->actingAs($admin)
            ->deleteJson("/api/users/{$target->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_creating_a_user_without_password_or_with_weak_password_returns_422(): void
    {
        $admin = $this->createSuperAdmin(['status' => UserStatus::ACTIVE]);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name' => 'No Pass User',
                'email' => 'nopass@example.com',
                'status' => UserStatus::ACTIVE->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->actingAs($admin)
            ->postJson('/api/users', [
                'name' => 'Weak Pass User',
                'email' => 'weak@example.com',
                'status' => UserStatus::ACTIVE->value,
                'password' => '12345678',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_user_cannot_deactivate_themselves(): void
    {
        $user = $this->createSuperAdmin(['status' => UserStatus::ACTIVE]);

        $this->actingAs($user)
            ->putJson("/api/users/{$user->id}", [
                'name' => $user->name,
                'email' => $user->email,
                'status' => UserStatus::INACTIVE->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(UserStatus::ACTIVE, $user->fresh()->status);
    }

    public function test_admin_seeder_never_uses_old_default_password(): void
    {
        putenv('ADMIN_INITIAL_PASSWORD=');
        unset($_ENV['ADMIN_INITIAL_PASSWORD'], $_SERVER['ADMIN_INITIAL_PASSWORD']);

        $this->artisan('db:seed', ['--class' => AdminUserSeeder::class])
            ->assertSuccessful();

        $admin = User::where('email', 'admin@kady.sys')->first();
        $this->assertNotNull($admin);
        $this->assertSame(UserStatus::ACTIVE, $admin->status);
        $this->assertFalse(Hash::check('Admin@12345', $admin->password));
    }

    public function test_login_still_returns_403_for_inactive_user(): void
    {
        $user = User::factory()->create([
            'status' => UserStatus::INACTIVE,
            'password' => Hash::make('StrongPass1'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'StrongPass1',
        ])
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => __('auth.inactive'),
            ]);
    }

    public function test_set_password_command_updates_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@kady.sys',
            'status' => UserStatus::ACTIVE,
        ]);
        $token = $user->createToken('spa');

        $this->artisan('users:set-password', ['email' => 'admin@kady.sys'])
            ->expectsQuestion('Enter new password', 'NewSecure99')
            ->expectsQuestion('Confirm new password', 'NewSecure99')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('NewSecure99', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
