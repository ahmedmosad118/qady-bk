import {
  BarChart3,
  BookOpen,
  Boxes,
  Home,
  ReceiptText,
  ShieldCheck,
  ShoppingCart,
  Store,
} from 'lucide-vue-next';

/**
 * Sidebar navigation definition for compact category rail + floating flyout menu.
 *
 * - Direct entries have `to` (e.g. Dashboard, Reports).
 * - Grouped categories have `sections: [{ label?, items: [{ label, to }] }]`.
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
          { label: 'nav.pos', to: '/pos' },
          { label: 'nav.invoices', to: '/invoices' },
          { label: 'nav.posReturns', to: '/pos/returns' },
          { label: 'nav.posShifts', to: '/pos/shifts' },
          { label: 'nav.customers', to: '/customers' },
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
          { label: 'nav.purchaseOrders', to: '/purchase-orders' },
          { label: 'nav.purchaseInvoices', to: '/purchase-invoices' },
          { label: 'nav.suppliers', to: '/suppliers' },
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
          { label: 'nav.products', to: '/products' },
          { label: 'nav.productCategories', to: '/product-categories' },
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
          { label: 'nav.allInventoryDocs', to: '/inventory' },
          { label: 'nav.goodsReceipt', to: '/inventory/goods-receipt' },
          { label: 'nav.goodsDelivery', to: '/inventory/goods-delivery' },
          { label: 'nav.stockAdjustment', to: '/inventory/stock-adjustment' },
          { label: 'nav.inventoryReturn', to: '/inventory/inventory-return' },
          { label: 'nav.stockWriteOff', to: '/inventory/stock-write-off' },
        ],
      },
      {
        label: 'nav.inventoryTransfersSection',
        items: [
          { label: 'nav.openingBalance', to: '/inventory/opening-balance' },
          { label: 'nav.warehouseStocks', to: '/inventory/stocks' },
          { label: 'nav.stocktaking', to: '/inventory/stocktakes' },
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
          { label: 'nav.expenseCategories', to: '/expense-categories' },
          { label: 'nav.expenses', to: '/expenses' },
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
          { label: 'nav.users', to: '/users' },
          { label: 'nav.settings', to: '/settings' },
        ],
      },
    ],
  },
];

export default navigation;
