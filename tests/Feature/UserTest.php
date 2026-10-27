<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_can_list_users_with_pagination_stats_and_schema(): void
    {
        $this->actingUser();
        User::factory()->count(5)->create(['status' => 'active']);
        User::factory()->count(2)->create(['status' => 'inactive']);

        $response = $this->getJson('/api/users?per_page=5');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'stats' => ['total', 'active', 'inactive'],
                'schema',
            ]);

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(8, $response->json('stats.total'));
    }

    public function test_can_filter_users_by_search_term(): void
    {
        $this->actingUser();
        User::factory()->create(['name' => 'خالد السعيد', 'email' => 'khaled@test.com']);
        User::factory()->create(['name' => 'عمر عبد الله', 'email' => 'omar@test.com']);

        $response = $this->getJson('/api/users?search=خالد');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('خالد السعيد', $response->json('data.0.name'));
    }

    public function test_can_create_user_with_hashed_password(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/users', [
            'name' => 'طارق كمال',
            'email' => 'tarek@example.com',
            'phone' => '01099887766',
            'status' => 'active',
            'password' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'طارق كمال');

        $user = User::where('email', 'tarek@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret123', $user->password));
    }

    public function test_can_update_user(): void
    {
        $this->actingUser();
        $user = User::factory()->create(['name' => 'قبل التعديل', 'status' => 'active']);

        $response = $this->putJson("/api/users/{$user->id}", [
            'name' => 'بعد التعديل',
            'email' => $user->email,
            'status' => 'inactive',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'بعد التعديل');

        $this->assertSame('inactive', $user->fresh()->status);
    }

    public function test_authenticated_user_cannot_delete_their_own_account(): void
    {
        $current = $this->actingUser();

        $response = $this->deleteJson("/api/users/{$current->id}");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);

        $this->assertDatabaseHas('users', ['id' => $current->id]);
    }

    public function test_can_delete_multiple_users(): void
    {
        $this->actingUser();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $response = $this->deleteJson("/api/users/{$user1->id},{$user2->id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('users', ['id' => $user1->id]);
        $this->assertDatabaseMissing('users', ['id' => $user2->id]);
    }
}
