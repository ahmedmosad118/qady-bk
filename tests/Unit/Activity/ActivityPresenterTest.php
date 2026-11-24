<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Models\Activity;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\User;
use App\Services\Activity\ActivityModuleRegistry;
use App\Services\Activity\ActivityPresenter;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ActivityPresenterTest extends TestCase
{
    public function test_presenter_formats_row_changes_and_redacts_sensitive_fields(): void
    {
        App::setLocale('en');

        $presenter = new ActivityPresenter(new ActivityModuleRegistry);

        $activity = new Activity([
            'log_name' => 'product',
            'event' => 'updated',
            'description' => 'updated',
            'subject_type' => Product::class,
            'subject_id' => 42,
            'properties' => [
                'old' => [
                    'name' => 'Old Book',
                    'selling_price' => '25.00',
                    'is_active' => true,
                    'category_id' => 1,
                    'password' => 'secret1',
                    'updated_at' => '2026-11-24 10:00:00',
                ],
                'attributes' => [
                    'name' => 'New Book',
                    'selling_price' => '35.00',
                    'is_active' => false,
                    'category_id' => 2,
                    'password' => 'secret2',
                    'updated_at' => '2026-11-24 12:00:00',
                ],
            ],
        ]);
        $activity->id = 99;
        $activity->created_at = now();

        $presented = $presenter->present($activity);

        $this->assertSame('product', $presented['log_name']);
        $this->assertSame('updated', $presented['event_key']);
        $this->assertSame('New Book', $presented['subject_name']);
        $this->assertSame('/products?showId=42', $presented['link']);

        $changeKeys = array_column($presented['row_changes'], 'key');
        $this->assertSame(['name', 'selling_price', 'is_active'], $changeKeys);
        $this->assertNotContains('password', $changeKeys);
        $this->assertNotContains('category_id', $changeKeys);
        $this->assertNotContains('updated_at', $changeKeys);

        $activeDiff = collect($presented['row_changes'])->firstWhere('key', 'is_active');
        $this->assertSame('Yes', $activeDiff['old']);
        $this->assertSame('No', $activeDiff['new']);
    }

    public function test_presenter_resolves_morphed_subject_name_and_hides_link_when_deleted(): void
    {
        App::setLocale('en');

        $registry = new ActivityModuleRegistry;
        $presenter = new ActivityPresenter($registry);

        $this->assertSame(PosSale::class, $registry->resolveSubjectClass('pos_sale'));

        $sale = new PosSale(['invoice_number' => 'INV-2026-009']);
        $sale->id = 77;

        $activity = new Activity([
            'log_name' => 'pos_sale',
            'event' => 'updated',
            'description' => 'updated',
            'subject_type' => 'pos_sale',
            'subject_id' => 77,
            'properties' => [
                'old' => ['notes' => 'a'],
                'attributes' => ['notes' => 'b'],
            ],
        ]);

        $presented = $presenter->present($activity, $sale);
        $this->assertSame('INV-2026-009', $presented['subject_name']);
        $this->assertSame('/invoices?showId=77', $presented['link']);

        $deletedActivity = new Activity([
            'log_name' => 'user',
            'event' => 'deleted',
            'description' => 'deleted',
            'subject_type' => User::class,
            'subject_id' => 5,
            'properties' => [
                'old' => ['name' => 'Deleted Admin'],
            ],
        ]);

        $presentedDeleted = $presenter->present($deletedActivity);
        $this->assertSame('Deleted Admin', $presentedDeleted['subject_name']);
        $this->assertNull($presentedDeleted['link']);
    }
}
