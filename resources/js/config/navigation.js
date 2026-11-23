import {
  BarChart3,
  BookOpen,
  Boxes,
  Home,
  Landmark,
  ReceiptText,
  ShieldCheck,
  ShoppingCart,
  Store,
} from 'lucide-vue-next';

/**
 * Sidebar navigation definition for compact category rail + floating flyout menu.
 *
 * - Direct entries have `to` (e.g. Dashboard, Reports).
 * - Grouped categories have `sections: [{ label?, items: [{ label, to, permission? }] }]`.
 *
 * Note: To add a new module, register its route in `resources/js/router/routes.js`
 * and add its link here.
 */
export const navigation = [
  {
    key: 'dashboard',
    label: 'nav.dashboard',
    to: '/',
    icon: Home,
  },
  {
    key: 'sales',
    label: 'nav.salesGroup',
    icon: Store,
    sections: [
      {
        items: [
          { label: 'nav.pos', to: '/pos', permission: 'pos.sell' },
          { label: 'nav.invoices', to: '/invoices', permission: ['pos_sales.view', 'pos.sell'] },
          { label: 'nav.posReturns', to: '/pos/returns', permission: ['pos_returns.view', 'pos_returns.create'] },
          { label: 'nav.posShifts', to: '/pos/shifts', permission: ['pos_shifts.view', 'pos_shifts.manage'] },
          { label: 'nav.customers', to: '/customers', permission: 'contacts.view' },
        ],
      },
    ],
  },
  {
    key: 'purchases',
    label: 'nav.suppliersGroup',
    icon: ShoppingCart,
    sections: [
      {
        items: [
          { label: 'nav.purchaseOrders', to: '/purchase-orders', permission: 'purchase_orders.view' },
          { label: 'nav.purchaseInvoices', to: '/purchase-invoices', permission: 'purchase_invoices.view' },
          { label: 'nav.suppliers', to: '/suppliers', permission: 'contacts.view' },
        ],
      },
    ],
  },
  {
    key: 'catalog',
    label: 'nav.catalogGroup',
    icon: BookOpen,
    sections: [
      {
        items: [
          { label: 'nav.products', to: '/products', permission: 'products.view' },
          { label: 'nav.productCategories', to: '/product-categories', permission: 'product_categories.view' },
          { label: 'nav.barcodePrint', to: '/products/barcodes', permission: 'products.print_barcodes' },
        ],
      },
    ],
  },
  {
    key: 'inventory',
    label: 'nav.inventoryGroup',
    icon: Boxes,
    sections: [
      {
        label: 'nav.inventoryDocsSection',
        items: [
          { label: 'nav.allInventoryDocs', to: '/inventory', permission: 'inventory_documents.view' },
          { label: 'nav.goodsReceipt', to: '/inventory/goods-receipt', permission: 'inventory_documents.view' },
          { label: 'nav.goodsDelivery', to: '/inventory/goods-delivery', permission: 'inventory_documents.view' },
          { label: 'nav.stockAdjustment', to: '/inventory/stock-adjustment', permission: 'inventory_documents.view' },
          { label: 'nav.inventoryReturn', to: '/inventory/inventory-return', permission: 'inventory_documents.view' },
          { label: 'nav.stockWriteOff', to: '/inventory/stock-write-off', permission: 'inventory_documents.view' },
        ],
      },
      {
        label: 'nav.inventoryTransfersSection',
        items: [
          { label: 'nav.openingBalance', to: '/inventory/opening-balance', permission: 'inventory_documents.view' },
          { label: 'nav.warehouseStocks', to: '/inventory/stocks', permission: 'warehouse_stocks.view' },
          { label: 'nav.stocktaking', to: '/inventory/stocktakes', permission: 'stocktakes.view' },
        ],
      },
    ],
  },
  {
    key: 'expenses',
    label: 'nav.expensesGroup',
    icon: ReceiptText,
    sections: [
      {
        items: [
          { label: 'nav.expenseCategories', to: '/expense-categories', permission: 'expense_categories.view' },
          { label: 'nav.expenses', to: '/expenses', permission: 'expenses.view' },
        ],
      },
    ],
  },
  {
    key: 'accounting',
    label: 'nav.accountingGroup',
    icon: Landmark,
    sections: [
      {
        items: [
          { label: 'nav.chartOfAccounts', to: '/accounting/accounts', permission: 'accounting.accounts.view' },
          { label: 'nav.journals', to: '/accounting/journals' },
          { label: 'nav.fiscalYears', to: '/accounting/fiscal-years' },
          { label: 'nav.accountingPeriods', to: '/accounting/accounting-periods' },
          { label: 'nav.paymentMethods', to: '/accounting/payment-methods', permission: 'accounting.settings.view' },
        ],
      },
    ],
  },
  {
    key: 'reports',
    label: 'nav.reports',
    to: '/reports',
    icon: BarChart3,
  },
  {
    key: 'admin',
    label: 'nav.userManagement',
    icon: ShieldCheck,
    sections: [
      {
        items: [
          { label: 'nav.users', to: '/users', permission: 'users.view' },
          { label: 'nav.roles', to: '/roles', permission: 'roles.view' },
          { label: 'nav.settings', to: '/settings', permission: ['purchase_settings.view', 'accounting.settings.view'] },
        ],
      },
    ],
  },
];

export default navigation;
