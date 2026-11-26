<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Expense;
use App\Models\InventoryDocument;
use App\Models\Payment;
use App\Models\PosReturn;
use App\Models\PosSale;
use App\Models\PurchaseInvoice;
use App\Models\Stocktake;
use App\Observers\InventoryDocumentObserver;
use Illuminate\Database\Eloquent\Relations\Relation;
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
            'payment' => Payment::class,
            'expense' => Expense::class,
        ]);

        InventoryDocument::observe(InventoryDocumentObserver::class);
    }
}
