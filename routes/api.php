<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactAddressController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\InventoryDocumentController;
use App\Http\Controllers\Api\Pos\PosDashboardController;
use App\Http\Controllers\Api\Pos\PosPaymentMethodController;
use App\Http\Controllers\Api\Pos\PosReturnController;
use App\Http\Controllers\Api\Pos\PosSaleController;
use App\Http\Controllers\Api\Pos\PosShiftController;
use App\Http\Controllers\Api\Pos\PosSyncController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\Purchase\PurchaseOrderController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\StocktakeController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\WarehouseStockController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| The Vue SPA authenticates with Sanctum personal access tokens, so every
| protected route is stateless and expects a Bearer token.
|
*/

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Config-driven CRUD endpoint (App\Core\CRUD\CRUDController).
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{ids}', [UserController::class, 'destroy']);

    // Books (products) and their categories.
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/lookup-barcode/{barcode}', [ProductController::class, 'lookupBarcode']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::put('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{category}', [ProductCategoryController::class, 'destroy']);

    // Contacts (Customers & Suppliers) and Contact Addresses.
    Route::get('/contacts', [ContactController::class, 'index']);
    Route::post('/contacts', [ContactController::class, 'store']);
    Route::get('/contacts/{contact}', [ContactController::class, 'show']);
    Route::put('/contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);

    Route::get('/contacts/{contact}/addresses', [ContactAddressController::class, 'index']);
    Route::post('/contacts/{contact}/addresses', [ContactAddressController::class, 'store']);
    Route::put('/contacts/{contact}/addresses/{address}', [ContactAddressController::class, 'update']);
    Route::delete('/contacts/{contact}/addresses/{address}', [ContactAddressController::class, 'destroy']);
    Route::patch('/contacts/{contact}/addresses/{address}/default', [ContactAddressController::class, 'setDefault']);

    // Purchase Orders (أوامر الشراء)
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
    Route::get('/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show']);
    Route::put('/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update']);
    Route::delete('/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy']);
    Route::post('/purchase-orders/{purchase_order}/confirm', [PurchaseOrderController::class, 'confirm']);
    Route::post('/purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel']);

    // Warehouses (Strictly view & update only; no create, no delete)
    Route::get('/warehouses', [WarehouseController::class, 'index']);
    Route::get('/warehouses/default', [WarehouseController::class, 'defaultWarehouse']);
    Route::get('/warehouses/{warehouse}', [WarehouseController::class, 'show']);
    Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update']);

    // Inventory Documents & Workflow
    Route::get('/inventory-documents', [InventoryDocumentController::class, 'index']);
    Route::post('/inventory-documents', [InventoryDocumentController::class, 'store']);
    Route::get('/inventory-documents/{inventory_document}', [InventoryDocumentController::class, 'show']);
    Route::put('/inventory-documents/{inventory_document}', [InventoryDocumentController::class, 'update']);
    Route::delete('/inventory-documents/{inventory_document}', [InventoryDocumentController::class, 'destroy']);
    Route::post('/inventory-documents/{inventory_document}/submit', [InventoryDocumentController::class, 'submit']);
    Route::post('/inventory-documents/{inventory_document}/approve', [InventoryDocumentController::class, 'approve']);
    Route::post('/inventory-documents/{inventory_document}/reject', [InventoryDocumentController::class, 'reject']);
    Route::post('/inventory-documents/{inventory_document}/cancel', [InventoryDocumentController::class, 'cancel']);
    Route::post('/inventory-documents/{inventory_document}/post', [InventoryDocumentController::class, 'post']);
    Route::post('/inventory-documents/{inventory_document}/reverse', [InventoryDocumentController::class, 'reverse']);

    // Warehouse Stocks & Stock Movements (Strictly read-only; updated via workflow posting/reversing only)
    Route::get('/warehouse-stocks', [WarehouseStockController::class, 'index']);
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::get('/stock-movements/stock-card', [StockMovementController::class, 'stockCard']);

    // Stocktaking Management & Workflow
    Route::get('/stocktakes', [StocktakeController::class, 'index']);
    Route::post('/stocktakes', [StocktakeController::class, 'store']);
    Route::get('/stocktakes/{stocktake}', [StocktakeController::class, 'show']);
    Route::post('/stocktakes/{stocktake}/start', [StocktakeController::class, 'start']);
    Route::post('/stocktakes/{stocktake}/submit', [StocktakeController::class, 'submit']);
    Route::post('/stocktakes/{stocktake}/approve', [StocktakeController::class, 'approve']);
    Route::post('/stocktakes/{stocktake}/reject', [StocktakeController::class, 'reject']);
    Route::post('/stocktakes/{stocktake}/cancel', [StocktakeController::class, 'cancel']);

    // Stocktake Item Counting
    Route::post('/stocktake-items/{item}/start-counting', [StocktakeController::class, 'startCounting']);
    Route::post('/stocktake-items/{item}/record-count', [StocktakeController::class, 'recordCount']);
    Route::post('/stocktake-items/{item}/cancel-count', [StocktakeController::class, 'cancelCount']);
    Route::post('/stocktake-items/{item}/recount', [StocktakeController::class, 'recount']);

    // Point of Sale (POS) Module
    // POS Payment Methods
    Route::get('/pos/payment-methods', [PosPaymentMethodController::class, 'index']);

    // POS Shifts Management
    Route::get('/pos/shifts', [PosShiftController::class, 'index']);
    Route::get('/pos/shifts/open', [PosShiftController::class, 'openShifts']);
    Route::post('/pos/shifts', [PosShiftController::class, 'store']);
    Route::get('/pos/shifts/{pos_shift}', [PosShiftController::class, 'show']);
    Route::post('/pos/shifts/{pos_shift}/close', [PosShiftController::class, 'close']);
    Route::get('/pos/shifts/{pos_shift}/transactions', [PosShiftController::class, 'transactions']);
    Route::get('/pos/shifts/{pos_shift}/sales', [PosShiftController::class, 'sales']);
    Route::post('/pos/shifts/{pos_shift}/expenses', [PosShiftController::class, 'recordExpense']);
    Route::post('/pos/shifts/{pos_shift}/withdrawals', [PosShiftController::class, 'recordWithdrawal']);

    // POS Products & Barcode Search
    Route::get('/pos/products', [PosSaleController::class, 'products']);
    Route::get('/pos/products/lookup', [PosSaleController::class, 'lookup']);

    // POS Sales
    Route::get('/pos/sales', [PosSaleController::class, 'index']);
    Route::post('/pos/sales', [PosSaleController::class, 'store']);
    Route::get('/pos/sales/by-number/{invoice_number}', [PosSaleController::class, 'byNumber']);
    Route::get('/pos/sales/{pos_sale}', [PosSaleController::class, 'show']);
    Route::get('/pos/sales/{pos_sale}/returnable-items', [PosReturnController::class, 'returnableItems']);

    // POS Returns
    Route::post('/pos/returns', [PosReturnController::class, 'store']);
    Route::get('/pos/returns/{pos_return}', [PosReturnController::class, 'show']);

    // POS Dashboard & Statistics
    Route::get('/pos/dashboard/stats', [PosDashboardController::class, 'stats']);

    // POS Offline Sync & Bootstrap
    Route::get('/pos/sync/bootstrap', [PosSyncController::class, 'bootstrap']);
    Route::post('/pos/sync/sales', [PosSyncController::class, 'syncSales']);
});
