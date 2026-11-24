<?php

use App\Http\Controllers\Api\Access\PermissionController;
use App\Http\Controllers\Api\Access\RoleController;
use App\Http\Controllers\Api\Accounting\AccountController;
use App\Http\Controllers\Api\Accounting\AccountingPeriodController;
use App\Http\Controllers\Api\Accounting\AccountingSettingController;
use App\Http\Controllers\Api\Accounting\FiscalYearController;
use App\Http\Controllers\Api\Accounting\JournalController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContactAddressController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ExcelController;
use App\Http\Controllers\Api\Expense\ExpenseAttachmentController;
use App\Http\Controllers\Api\Expense\ExpenseCategoryController;
use App\Http\Controllers\Api\Expense\ExpenseController;
use App\Http\Controllers\Api\InventoryDocumentController;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\Pos\PosDashboardController;
use App\Http\Controllers\Api\Pos\PosPaymentMethodController;
use App\Http\Controllers\Api\Pos\PosReturnController;
use App\Http\Controllers\Api\Pos\PosSaleController;
use App\Http\Controllers\Api\Pos\PosShiftController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\Purchase\PurchaseInvoiceController;
use App\Http\Controllers\Api\Purchase\PurchaseOrderController;
use App\Http\Controllers\Api\Purchase\PurchaseSettingController;
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

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Users Management
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{ids}', [UserController::class, 'destroy']);

    // Roles & Permissions Management
    Route::get('/permissions', [PermissionController::class, 'index']);
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{role}', [RoleController::class, 'show']);
    Route::put('/roles/{role}', [RoleController::class, 'update']);
    Route::delete('/roles/{role}', [RoleController::class, 'destroy']);

    // Activity Log (Audit Trail)
    Route::get('/activities', [ActivityController::class, 'index']);
    Route::delete('/activities/clear', [ActivityController::class, 'clearAll']);

    // Books (products) and their categories.
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
    Route::post('/products/barcode-labels', [ProductController::class, 'barcodeLabels']);
    Route::get('/products/lookup-barcode/{barcode}', [ProductController::class, 'lookupBarcode']);
    Route::get('/products/{product}', [ProductController::class, 'show']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    Route::get('/product-categories', [ProductCategoryController::class, 'index']);
    Route::post('/product-categories', [ProductCategoryController::class, 'store']);
    Route::put('/product-categories/{category}', [ProductCategoryController::class, 'update']);
    Route::delete('/product-categories/{category}', [ProductCategoryController::class, 'destroy']);

    // Excel Import & Export
    Route::prefix('excel')->group(function () {
        Route::get('/template/{type}', [ExcelController::class, 'downloadTemplate']);
        Route::get('/export/{type}', [ExcelController::class, 'export']);
        Route::post('/import/{type}', [ExcelController::class, 'import']);
    });

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

    // Purchase Invoices
    Route::get('/purchase-invoices', [PurchaseInvoiceController::class, 'index']);
    Route::post('/purchase-invoices', [PurchaseInvoiceController::class, 'store']);
    Route::post('/purchase-invoices/from-purchase-order/{purchase_order}', [PurchaseInvoiceController::class, 'storeFromPurchaseOrder']);
    Route::get('/purchase-invoices/{purchase_invoice}', [PurchaseInvoiceController::class, 'show']);
    Route::put('/purchase-invoices/{purchase_invoice}', [PurchaseInvoiceController::class, 'update']);
    Route::delete('/purchase-invoices/{purchase_invoice}', [PurchaseInvoiceController::class, 'destroy']);
    Route::post('/purchase-invoices/{purchase_invoice}/cancel', [PurchaseInvoiceController::class, 'cancel']);

    // Settings (Purchase & Accounting Settings)
    Route::get('/settings/purchase', [PurchaseSettingController::class, 'show']);
    Route::put('/settings/purchase', [PurchaseSettingController::class, 'update']);
    Route::get('/settings/accounting', [AccountingSettingController::class, 'show']);
    Route::put('/settings/accounting', [AccountingSettingController::class, 'update']);

    // Chart of Accounts
    Route::prefix('accounting/accounts')->group(function () {
        Route::get('/tree', [AccountController::class, 'tree']);
        Route::get('/', [AccountController::class, 'index']);
        Route::post('/', [AccountController::class, 'store']);
        Route::get('/{account}', [AccountController::class, 'show']);
        Route::put('/{account}', [AccountController::class, 'update']);
        Route::delete('/{account}', [AccountController::class, 'destroy']);
    });

    // Journals
    Route::prefix('accounting/journals')->group(function () {
        Route::get('/', [JournalController::class, 'index']);
        Route::post('/', [JournalController::class, 'store']);
        Route::get('/{journal}', [JournalController::class, 'show']);
        Route::put('/{journal}', [JournalController::class, 'update']);
        Route::delete('/{journal}', [JournalController::class, 'destroy']);
    });

    // Fiscal Years
    Route::prefix('accounting/fiscal-years')->group(function () {
        Route::get('/', [FiscalYearController::class, 'index']);
        Route::post('/', [FiscalYearController::class, 'store']);
        Route::get('/{fiscalYear}', [FiscalYearController::class, 'show']);
        Route::put('/{fiscalYear}', [FiscalYearController::class, 'update']);
        Route::delete('/{fiscalYear}', [FiscalYearController::class, 'destroy']);
    });

    // Accounting Periods
    Route::prefix('accounting/accounting-periods')->group(function () {
        Route::get('/', [AccountingPeriodController::class, 'index']);
        Route::post('/', [AccountingPeriodController::class, 'store']);
        Route::get('/{accountingPeriod}', [AccountingPeriodController::class, 'show']);
        Route::put('/{accountingPeriod}', [AccountingPeriodController::class, 'update']);
        Route::delete('/{accountingPeriod}', [AccountingPeriodController::class, 'destroy']);
    });

    // Payment Methods (Management & Account Linking)
    Route::prefix('payment-methods')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index']);
        Route::post('/', [PaymentMethodController::class, 'store']);
        Route::get('/{paymentMethod}', [PaymentMethodController::class, 'show']);
        Route::put('/{paymentMethod}', [PaymentMethodController::class, 'update']);
    });

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
    Route::get('/pos/returns', [PosReturnController::class, 'index']);
    Route::post('/pos/returns', [PosReturnController::class, 'store']);
    Route::get('/pos/returns/{pos_return}', [PosReturnController::class, 'show']);

    // POS Dashboard & Statistics
    Route::get('/pos/dashboard/stats', [PosDashboardController::class, 'stats']);

    // General Expenses & Expense Categories
    Route::prefix('expense-categories')->group(function () {
        Route::get('/', [ExpenseCategoryController::class, 'index']);
        Route::post('/', [ExpenseCategoryController::class, 'store']);
        Route::get('/{expenseCategory}', [ExpenseCategoryController::class, 'show']);
        Route::put('/{expenseCategory}', [ExpenseCategoryController::class, 'update']);
        Route::delete('/{expenseCategory}', [ExpenseCategoryController::class, 'destroy']);
    });

    Route::prefix('expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'index']);
        Route::post('/', [ExpenseController::class, 'store']);
        Route::get('/{expense}', [ExpenseController::class, 'show']);
        Route::put('/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/{expense}', [ExpenseController::class, 'destroy']);

        Route::post('/{expense}/items/{item}/attachments', [ExpenseAttachmentController::class, 'upload']);
        Route::get('/{expense}/items/{item}/attachments/{attachment}/view', [ExpenseAttachmentController::class, 'view']);
        Route::get('/{expense}/items/{item}/attachments/{attachment}/download', [ExpenseAttachmentController::class, 'download']);
        Route::delete('/{expense}/items/{item}/attachments/{attachment}', [ExpenseAttachmentController::class, 'destroy']);
    });
});
