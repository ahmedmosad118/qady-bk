<?php

declare(strict_types=1);

$categories = [
    'Sales & POS' => ['POS Terminal', 'Sales Invoices', 'POS Returns', 'POS Shifts'],
    'Contacts' => ['Contacts'],
    'Purchases' => ['Purchase Orders', 'Purchase Invoices', 'Purchase Settings'],
    'Catalog' => ['Products', 'Product Categories'],
    'Inventory' => ['Warehouses', 'Inventory Documents', 'Warehouse Stocks', 'Stocktaking'],
    'Expenses' => ['Expense Categories', 'General Expenses'],
    'Accounting' => ['Chart of Accounts', 'Accounting Settings'],
    'Administration' => ['Users', 'Roles'],
];

$modules = [
    // Sales & POS
    [
        'name' => 'POS Terminal',
        'slug' => 'POS Terminal',
        'model' => 'PosSale',
        'prefix' => 'pos',
        'actions' => [
            'sell',
            'apply_item_discount',
            'apply_invoice_discount',
            'override_price',
            'view_dashboard',
        ],
    ],
    [
        'name' => 'Sales Invoices',
        'slug' => 'Sales Invoices',
        'model' => 'PosSale',
        'prefix' => 'pos_sales',
        'actions' => [
            'view',
        ],
    ],
    [
        'name' => 'POS Returns',
        'slug' => 'POS Returns',
        'model' => 'PosReturn',
        'prefix' => 'pos_returns',
        'actions' => [
            'view',
            'create',
        ],
    ],
    [
        'name' => 'POS Shifts',
        'slug' => 'POS Shifts',
        'model' => 'PosShift',
        'prefix' => 'pos_shifts',
        'actions' => [
            'view',
            'manage',
            'manage_cash',
        ],
    ],

    // Contacts
    [
        'name' => 'Contacts',
        'slug' => 'Contacts',
        'model' => 'Contact',
        'prefix' => 'contacts',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],

    // Purchases
    [
        'name' => 'Purchase Orders',
        'slug' => 'Purchase Orders',
        'model' => 'PurchaseOrder',
        'prefix' => 'purchase_orders',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
            'confirm',
            'cancel',
        ],
    ],
    [
        'name' => 'Purchase Invoices',
        'slug' => 'Purchase Invoices',
        'model' => 'PurchaseInvoice',
        'prefix' => 'purchase_invoices',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
            'cancel',
        ],
    ],
    [
        'name' => 'Purchase Settings',
        'slug' => 'Purchase Settings',
        'model' => 'Setting',
        'prefix' => 'purchase_settings',
        'actions' => [
            'view',
            'update',
        ],
    ],

    // Catalog
    [
        'name' => 'Products',
        'slug' => 'Products',
        'model' => 'Product',
        'prefix' => 'products',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
            'print_barcodes',
        ],
    ],
    [
        'name' => 'Product Categories',
        'slug' => 'Product Categories',
        'model' => 'ProductCategory',
        'prefix' => 'product_categories',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],

    // Inventory
    [
        'name' => 'Warehouses',
        'slug' => 'Warehouses',
        'model' => 'Warehouse',
        'prefix' => 'warehouses',
        'actions' => [
            'view',
            'update',
        ],
    ],
    [
        'name' => 'Inventory Documents',
        'slug' => 'Inventory Documents',
        'model' => 'InventoryDocument',
        'prefix' => 'inventory_documents',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
            'submit',
            'approve',
            'reject',
            'cancel',
            'post',
            'reverse',
        ],
    ],
    [
        'name' => 'Warehouse Stocks',
        'slug' => 'Warehouse Stocks',
        'model' => 'WarehouseStock',
        'prefix' => 'warehouse_stocks',
        'actions' => [
            'view',
            'view_movements',
        ],
    ],
    [
        'name' => 'Stocktaking',
        'slug' => 'Stocktaking',
        'model' => 'Stocktake',
        'prefix' => 'stocktakes',
        'actions' => [
            'view',
            'create',
            'start',
            'count',
            'submit',
            'approve',
            'reject',
            'cancel',
            'review_results',
        ],
    ],

    // Expenses
    [
        'name' => 'Expense Categories',
        'slug' => 'Expense Categories',
        'model' => 'ExpenseCategory',
        'prefix' => 'expense_categories',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],
    [
        'name' => 'General Expenses',
        'slug' => 'General Expenses',
        'model' => 'Expense',
        'prefix' => 'expenses',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],

    // Accounting
    [
        'name' => 'Chart of Accounts',
        'slug' => 'Chart of Accounts',
        'model' => 'ChartOfAccount',
        'prefix' => 'accounting.accounts',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],
    [
        'name' => 'Accounting Settings',
        'slug' => 'Accounting Settings',
        'model' => 'Setting',
        'prefix' => 'accounting.settings',
        'actions' => [
            'view',
            'update',
        ],
    ],

    // Administration
    [
        'name' => 'Users',
        'slug' => 'Users',
        'model' => 'User',
        'prefix' => 'users',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],
    [
        'name' => 'Roles',
        'slug' => 'Roles',
        'model' => 'Role',
        'prefix' => 'roles',
        'actions' => [
            'view',
            'create',
            'update',
            'delete',
        ],
    ],
];

return [
    'categories' => $categories,
    'modules' => $modules,
];
