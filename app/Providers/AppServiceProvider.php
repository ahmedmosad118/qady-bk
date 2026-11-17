<?php

namespace App\Providers;

use App\Models\InventoryDocument;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PurchaseInvoice;
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
            'purchase_invoice' => PurchaseInvoice::class,
        ]);

        InventoryDocument::observe(InventoryDocumentObserver::class);

        // POS Authorization Gates bound to real Spatie roles & permissions
        $hasPosPermission = static function (User $user, array $permissions): bool {
            if ($user->hasAnyRole(['admin', 'Owner', 'Super Admin'])) {
                return true;
            }

            foreach ($permissions as $permission) {
                if ($user->checkPermissionTo($permission)) {
                    return true;
                }
            }

            return false;
        };

        $posGates = [
            'pos.sell' => ['pos.sell'],
            'pos.discount' => ['pos.discount', 'pos.apply_item_discount', 'pos.apply_invoice_discount'],
            'pos.apply_item_discount' => ['pos.discount', 'pos.apply_item_discount'],
            'pos.apply_invoice_discount' => ['pos.discount', 'pos.apply_invoice_discount'],
            'pos.price_override' => ['pos.price_override', 'pos.override_price'],
            'pos.override_price' => ['pos.price_override', 'pos.override_price'],
            'pos.shift' => ['pos.shift', 'pos.manage_shifts'],
            'pos.manage_shifts' => ['pos.shift', 'pos.manage_shifts'],
            'pos.return' => ['pos.return', 'pos.process_returns'],
            'pos.process_returns' => ['pos.return', 'pos.process_returns'],
            'pos.expenses' => ['pos.expenses', 'pos.manage_shift_cash'],
            'pos.manage_shift_cash' => ['pos.expenses', 'pos.manage_shift_cash'],
        ];

        foreach ($posGates as $gate => $permissions) {
            Gate::define($gate, static fn (User $user): bool => $hasPosPermission($user, $permissions));
        }

        // Expenses Authorization Gates (Integration points with current/future permission systems)
        Gate::define('expenses.view', fn (User $user) => true);
        Gate::define('expenses.create', fn (User $user) => true);
        Gate::define('expenses.update', fn (User $user) => true);
        Gate::define('expenses.delete', fn (User $user) => true);
        Gate::define('expense_categories.view', fn (User $user) => true);
        Gate::define('expense_categories.create', fn (User $user) => true);
        Gate::define('expense_categories.update', fn (User $user) => true);
        Gate::define('expense_categories.delete', fn (User $user) => true);
    }
}
