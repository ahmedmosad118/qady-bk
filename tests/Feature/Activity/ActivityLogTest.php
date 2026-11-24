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
use App\Services\Activity\ActivityService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('updated', $activity->event);
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
            ->pluck('event')
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
        $this->assertSame('created', $saleActivity->event);
        $this->assertArrayNotHasKey('client_uuid', $saleActivity->getNormalizedProperties()['attributes'] ?? []);
    }

    public function test_activity_service_filters_and_computes_analytics_across_months_and_modules(): void
    {
        App::setLocale('en');

        $alice = User::factory()->create(['name' => 'Alice Editor']);
        $bob = User::factory()->create(['name' => 'Bob Cashier']);

        Activity::query()->delete();

        $cat = ProductCategory::query()->create(['name' => 'History', 'is_active' => true]);
        $prod = Product::query()->create([
            'category_id' => $cat->id,
            'name' => 'Soft Deleted Book',
            'purchase_price' => '10.00',
            'selling_price' => '20.00',
            'is_active' => true,
            'show_in_shop' => true,
        ]);

        Activity::query()->delete();

        $a1 = Activity::query()->create([
            'log_name' => 'product',
            'description' => 'created',
            'event' => 'created',
            'subject_type' => Product::class,
            'subject_id' => $prod->id,
            'causer_type' => User::class,
            'causer_id' => $alice->id,
            'properties' => ['attributes' => ['name' => 'Soft Deleted Book']],
        ]);
        $a1->forceFill(['created_at' => now()->startOfMonth()->addDay()])->saveQuietly();

        $a2 = Activity::query()->create([
            'log_name' => 'product',
            'description' => 'updated',
            'event' => 'updated',
            'subject_type' => Product::class,
            'subject_id' => $prod->id,
            'causer_type' => User::class,
            'causer_id' => $alice->id,
            'properties' => [
                'old' => ['selling_price' => '20.00'],
                'attributes' => ['selling_price' => '25.00'],
            ],
        ]);
        $a2->forceFill(['created_at' => now()->startOfMonth()->addDays(2)])->saveQuietly();

        $prevMonthDate = now()->subMonthNoOverflow()->startOfMonth()->addDays(3);
        $a3 = Activity::query()->create([
            'log_name' => 'user',
            'description' => 'deleted',
            'event' => 'deleted',
            'subject_type' => User::class,
            'subject_id' => $bob->id,
            'causer_type' => User::class,
            'causer_id' => $bob->id,
            'properties' => ['old' => ['name' => 'Bob Cashier']],
        ]);
        $a3->forceFill(['created_at' => $prevMonthDate])->saveQuietly();

        $prod->delete(); // soft delete after removing extra log
        Activity::query()->where('id', '>', $a3->id)->delete();

        /** @var ActivityService $service */
        $service = app(ActivityService::class);

        $allResult = $service->index([], 15);
        $analytics = $allResult['analytics'];

        $this->assertSame(3, $analytics['total_activities']);
        $this->assertSame(1, $analytics['created_count']);
        $this->assertSame(1, $analytics['updated_count']);
        $this->assertSame(1, $analytics['deleted_count']);
        $this->assertSame(2, $analytics['this_month_count']);
        $this->assertSame(now()->format('Y-m'), $analytics['most_active_month']['key']);
        $this->assertCount(2, $analytics['monthly_breakdown']);
        $this->assertSame($alice->id, $analytics['top_user']['user_id']);
        $this->assertSame('product', $analytics['top_module']['key']);

        // Filter by module + event
        $filtered = $service->index(['module' => 'product', 'event' => 'updated'], 15);
        $this->assertSame(1, $filtered['paginator']->total());
        $this->assertSame('Soft Deleted Book', $filtered['items'][0]['subject_name']);
        $this->assertSame(2, $filtered['analytics']['filtered_stats']['total']);
        $this->assertSame(1, $filtered['analytics']['filtered_stats']['created']);
        $this->assertSame(1, $filtered['analytics']['filtered_stats']['updated']);

        // Filter by month (previous month)
        $prevMonthKey = $prevMonthDate->format('Y-m');
        $monthFiltered = $service->index(['month' => $prevMonthKey], 15);
        $this->assertSame(1, $monthFiltered['paginator']->total());
        $this->assertSame($bob->id, $monthFiltered['items'][0]['causer']['id']);

        // Search by causer name
        $searchResult = $service->index(['search' => 'Alice'], 15);
        $this->assertSame(2, $searchResult['paginator']->total());

        // Batch query count check (no N+1 per row)
        DB::enableQueryLog();
        $service->index([], 15);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThan(20, count($queries));
    }

    public function test_activity_endpoints_enforce_authentication_permissions_and_validation(): void
    {
        $this->getJson('/api/activities')->assertUnauthorized();
        $this->deleteJson('/api/activities/clear')->assertUnauthorized();

        $this->actingAsUserWithPermissions([]);
        $this->getJson('/api/activities')->assertForbidden();
        $this->deleteJson('/api/activities/clear')->assertForbidden();

        // Admin user with activities.view sees general activity log across all users, but cannot clear
        $adminViewer = $this->actingAsUserWithPermissions(['activities.view'], ['name' => 'Admin Viewer']);
        ProductCategory::query()->create(['name' => 'Science', 'is_active' => true]);

        $this->getJson('/api/activities')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'analytics' => [
                    'total_activities',
                    'created_count',
                    'updated_count',
                    'deleted_count',
                    'this_month_count',
                    'most_active_month',
                    'monthly_breakdown',
                    'top_user',
                    'top_users_list',
                    'top_module',
                    'filtered_stats',
                    'modules_list',
                    'users_list',
                ],
            ]);

        $this->getJson('/api/activities?month=invalid-month')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['month']);

        $this->getJson('/api/activities?date_from=2026-11-20&date_to=2026-11-10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);

        $this->deleteJson('/api/activities/clear')->assertForbidden();
        $this->assertGreaterThan(0, Activity::query()->count());

        // Super Admin can clear all activity logs
        $this->actingAsSuperAdmin();
        $this->deleteJson('/api/activities/clear')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, Activity::query()->count());
        $this->assertNotNull($adminViewer->id);
    }
}
