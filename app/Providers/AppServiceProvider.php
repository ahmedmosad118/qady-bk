<?php

namespace App\Providers;

use App\Models\InventoryDocument;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\Stocktake;
use App\Models\User;
use App\Observers\InventoryDocumentObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'stocktake' => Stocktake::class,
            'pos_sale' => PosSale::class,
            'pos_return' => PosReturn::class,
        ]);

        InventoryDocument::observe(InventoryDocumentObserver::class);

        // POS Authorization Gates (Integration points with current/future permission systems)
        Gate::define('pos.override_price', fn (User $user) => true);
        Gate::define('pos.apply_item_discount', fn (User $user) => true);
        Gate::define('pos.apply_invoice_discount', fn (User $user) => true);
        Gate::define('pos.manage_shifts', fn (User $user) => true);
        Gate::define('pos.manage_shift_cash', fn (User $user) => true);
        Gate::define('pos.process_returns', fn (User $user) => true);
    }
}
