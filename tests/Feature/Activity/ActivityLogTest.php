<?php

declare(strict_types=1);

namespace Tests\Feature\Activity;

use App\Enums\PaymentMethodType;
use App\Models\Activity;
use App\Models\PaymentMethod;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_activitylog_config_uses_custom_activity_model_and_user_exposes_activities_relation(): void
    {
        $this->assertSame(Activity::class, config('activitylog.activity_model'));
        $this->assertTrue((bool) config('activitylog.subject_returns_soft_deleted_models'));

        $user = User::factory()->create();

        $this->assertInstanceOf(MorphMany::class, $user->activities());
    }

    public function test_user_activity_logging_never_logs_password_or_remember_token(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $user = User::factory()->create([
            'name' => 'Initial Name',
            'password' => Hash::make('secret-123'),
        ]);

        Activity::query()->delete();

        $user->update([
            'password' => Hash::make('new-secret-456'),
            'remember_token' => 'token-xyz',
        ]);

        $this->assertSame(0, Activity::query()->where('subject_id', $user->id)->count());

        $user->update(['name' => 'Updated Name']);

        $activity = Activity::query()
            ->where('log_name', 'user')
            ->where('subject_id', $user->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame('updated', $activity->event_key);
        $this->assertSame($admin->id, $activity->causer_id);

        $props = $activity->getNormalizedProperties();
        $this->assertArrayNotHasKey('password', $props['attributes'] ?? []);
        $this->assertArrayNotHasKey('remember_token', $props['attributes'] ?? []);
        $this->assertSame('Updated Name', $props['attributes']['name'] ?? null);
        $this->assertSame('Initial Name', $props['old']['name'] ?? null);
    }

    public function test_product_and_morphed_pos_sale_record_expected_log_names_and_events(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $category = ProductCategory::query()->create(['name' => 'Fiction', 'is_active' => true]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Book One',
            'purchase_price' => '10.00',
            'selling_price' => '25.00',
            'is_active' => true,
            'show_in_shop' => true,
        ]);

        $product->update(['selling_price' => '30.00']);
        $product->delete();

        $events = Activity::query()
            ->where('log_name', 'product')
            ->where('subject_id', $product->id)
            ->orderBy('id')
            ->get()
            ->pluck('event_key')
            ->all();

        $this->assertSame(['created', 'updated', 'deleted'], $events);

        $paymentMethod = PaymentMethod::query()->create([
            'name' => 'Cash',
            'type' => PaymentMethodType::CASH,
            'is_active' => true,
        ]);

        $shift = PosShift::query()->create([
            'shift_number' => 'SH-0001',
            'opening_balance' => '100.00',
            'opened_by' => $admin->id,
            'opened_at' => now(),
        ]);

        $sale = PosSale::query()->create([
            'invoice_number' => 'INV-0001',
            'shift_id' => $shift->id,
            'payment_method_id' => $paymentMethod->id,
            'subtotal' => '30.00',
            'items_discount_total' => '0.00',
            'invoice_discount_type' => null,
            'invoice_discount_value' => '0.00',
            'invoice_discount_amount' => '0.00',
            'grand_total' => '30.00',
            'created_by' => $admin->id,
            'sold_at' => now(),
            'client_uuid' => 'uuid-test-123',
        ]);

        $saleActivity = Activity::query()
            ->where('log_name', 'pos_sale')
            ->where('subject_id', $sale->id)
            ->first();

        $this->assertNotNull($saleActivity);
        $this->assertSame('pos_sale', $saleActivity->subject_type);
        $this->assertSame('created', $saleActivity->event_key);
        $this->assertSame('INV-0001', $saleActivity->subject_name);
        $this->assertSame("/invoices?showId={$sale->id}", $saleActivity->link);
        $this->assertArrayNotHasKey('client_uuid', $saleActivity->getNormalizedProperties()['attributes'] ?? []);
    }

    public function test_activity_model_formats_row_changes_and_resolves_soft_deleted_subjects(): void
    {
        App::setLocale('en');

        $cat = ProductCategory::query()->create(['name' => 'History', 'is_active' => true]);
        $prod = Product::query()->create([
            'category_id' => $cat->id,
            'name' => 'Soft Deleted Book',
            'purchase_price' => '10.00',
            'selling_price' => '20.00',
            'is_active' => true,
            'show_in_shop' => true,
        ]);

        $prod->update(['selling_price' => '25.00', 'is_active' => false]);
        $prod->delete();

        $updateActivity = Activity::query()
            ->where('log_name', 'product')
            ->where('subject_id', $prod->id)
            ->where('event', 'updated')
            ->firstOrFail();

        $this->assertSame('Soft Deleted Book', $updateActivity->subject_name);
        $this->assertSame("/products?showId={$prod->id}", $updateActivity->link);

        $changeKeys = array_column($updateActivity->row_changes, 'key');
        $this->assertSame(['selling_price', 'is_active'], $changeKeys);

        $activeDiff = collect($updateActivity->row_changes)->firstWhere('key', 'is_active');
        $this->assertSame('Yes', $activeDiff['old']);
        $this->assertSame('No', $activeDiff['new']);

        $deleteActivity = Activity::query()
            ->where('log_name', 'product')
            ->where('subject_id', $prod->id)
            ->where('event', 'deleted')
            ->firstOrFail();

        $this->assertNull($deleteActivity->link);
    }

    public function test_activity_endpoints_support_filters_index_delete_one_and_clear_all(): void
    {
        App::setLocale('en');

        $this->getJson('/api/activities')->assertUnauthorized();
        $this->deleteJson('/api/activities/clear')->assertUnauthorized();

        $this->actingAsUserWithPermissions([]);
        $this->getJson('/api/activities')->assertForbidden();
        $this->deleteJson('/api/activities/clear')->assertForbidden();

        // Admin user with activities.view sees activity log & can filter, but cannot delete one or clear all
        $alice = User::factory()->create(['name' => 'Alice Editor']);
        $this->actingAsUserWithPermissions(['activities.view'], ['name' => 'Admin Viewer']);

        Activity::query()->delete();

        $cat = ProductCategory::query()->create(['name' => 'Science', 'is_active' => true]);
        $cat->update(['name' => 'Science Updated']);

        Activity::query()->create([
            'log_name' => 'user',
            'description' => 'created',
            'event' => 'created',
            'subject_type' => User::class,
            'subject_id' => $alice->id,
            'causer_type' => User::class,
            'causer_id' => $alice->id,
            'properties' => ['attributes' => ['name' => 'Alice Editor']],
        ]);

        $firstId = (int) Activity::query()->orderBy('id')->value('id');

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'filters' => ['modules', 'users'],
            ]);

        // Filter by module + event
        $this->getJson('/api/activities?module=product_category&event=updated')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.subject_name', 'Science Updated');

        // Filter by causer_id & search
        $this->getJson("/api/activities?causer_id={$alice->id}&search=Alice")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.causer.name', 'Alice Editor');

        // Validation error on bad date range
        $this->getJson('/api/activities?date_from=2026-11-20&date_to=2026-11-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);

        $this->deleteJson("/api/activities/{$firstId}")->assertForbidden();
        $this->deleteJson('/api/activities/clear')->assertForbidden();

        // User with activities.delete can delete a single activity record
        $this->actingAsUserWithPermissions(['activities.view', 'activities.delete']);
        $countBeforeDelete = Activity::query()->count();

        $this->deleteJson("/api/activities/{$firstId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame($countBeforeDelete - 1, Activity::query()->count());
        $this->assertDatabaseMissing('activity_log', ['id' => $firstId]);

        // Super Admin can clear all activity logs
        $this->actingAsSuperAdmin();
        $this->deleteJson('/api/activities/clear')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, Activity::query()->count());
    }
}
