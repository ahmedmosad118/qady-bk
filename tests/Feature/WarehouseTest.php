<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_seeder_creates_default_warehouse_and_is_idempotent(): void
    {
        $this->seed(WarehouseSeeder::class);

        $this->assertDatabaseHas('warehouses', [
            'code' => 'MAIN',
            'name' => 'المخزن الرئيسي',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->assertEquals(1, Warehouse::query()->where('is_default', true)->count());

        // Run seeder again to verify idempotency
        $this->seed(WarehouseSeeder::class);
        $this->assertEquals(1, Warehouse::query()->where('is_default', true)->count());
        $this->assertEquals(1, Warehouse::query()->count());
    }

    public function test_can_retrieve_default_warehouse(): void
    {
        $this->seed(WarehouseSeeder::class);

        $response = $this->getJson('/api/warehouses/default');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'MAIN')
            ->assertJsonPath('data.is_default', true);
    }

    public function test_can_update_warehouse_details(): void
    {
        $this->seed(WarehouseSeeder::class);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $response = $this->putJson("/api/warehouses/{$warehouse->id}", [
            'code' => 'CENTRAL',
            'name' => 'المستودع المركزي',
            'address' => 'شارع رمسيس، القاهرة',
            'phone' => '01012345678',
            'manager_name' => 'أحمد محمود',
            'description' => 'المستودع الرئيسي بعد التحديث',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'CENTRAL')
            ->assertJsonPath('data.name', 'المستودع المركزي')
            ->assertJsonPath('data.address', 'شارع رمسيس، القاهرة');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse->id,
            'code' => 'CENTRAL',
            'name' => 'المستودع المركزي',
        ]);
    }

    public function test_update_rejects_empty_address(): void
    {
        $this->seed(WarehouseSeeder::class);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $response = $this->putJson("/api/warehouses/{$warehouse->id}", [
            'code' => 'MAIN',
            'name' => 'المخزن الرئيسي',
            'address' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address']);
    }

    public function test_cannot_deactivate_the_default_warehouse(): void
    {
        $this->seed(WarehouseSeeder::class);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $response = $this->putJson("/api/warehouses/{$warehouse->id}", [
            'code' => 'MAIN',
            'name' => 'المخزن الرئيسي',
            'address' => 'العنوان الحالي',
            'is_active' => false,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_no_create_or_store_endpoint_exists_for_warehouses(): void
    {
        $response = $this->postJson('/api/warehouses', [
            'code' => 'NEW',
            'name' => 'مخزن جديد',
            'address' => 'عنوان',
        ]);

        // Must return 405 Method Not Allowed or 404 Not Found
        $this->assertTrue(in_array($response->status(), [404, 405], true));
    }

    public function test_no_delete_endpoint_exists_for_warehouses(): void
    {
        $this->seed(WarehouseSeeder::class);
        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();

        $response = $this->deleteJson("/api/warehouses/{$warehouse->id}");

        // Must return 405 Method Not Allowed or 404 Not Found
        $this->assertTrue(in_array($response->status(), [404, 405], true));
    }

    public function test_sidebar_navigation_does_not_contain_warehouses(): void
    {
        $navContent = file_get_contents(resource_path('js/config/navigation.js'));

        $this->assertStringNotContainsString("to: '/warehouses'", $navContent);
        $this->assertStringNotContainsString("path: 'warehouses'", $navContent);
    }
}
