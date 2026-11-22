import { useAuthStore } from '../stores/auth';
import { useThemeStore } from '../stores/theme';

import AuthLayout from '../layouts/AuthLayout.vue';
import AppLayout from '../layouts/AppLayout.vue';

import LoginView from '../views/auth/LoginView.vue';
import HomeView from '../views/HomeView.vue';
import UsersView from '../views/team/UsersView.vue';
import RolesView from '../views/team/RolesView.vue';
import ProductsView from '../views/catalog/ProductsView.vue';
import BarcodePrintView from '../views/catalog/BarcodePrintView.vue';
import ProductCategoriesView from '../views/catalog/ProductCategoriesView.vue';
import ContactsView from '../views/sales/ContactsView.vue';
import InventoryDocumentsView from '../views/inventory/InventoryDocumentsView.vue';
import WarehouseStocksView from '../views/inventory/WarehouseStocksView.vue';
import WarehouseEditView from '../views/inventory/WarehouseEditView.vue';
import StocktakesView from '../views/inventory/StocktakesView.vue';
import PosView from '../views/pos/PosView.vue';
import PosSalesView from '../views/pos/management/PosSalesView.vue';
import PosReturnsView from '../views/pos/management/PosReturnsView.vue';
import PosShiftsView from '../views/pos/management/PosShiftsView.vue';
import PurchaseOrdersView from '../views/purchases/PurchaseOrdersView.vue';
import PurchaseInvoicesView from '../views/purchases/PurchaseInvoicesView.vue';
import ExpensesView from '../views/expenses/ExpensesView.vue';
import ExpenseCategoriesView from '../views/expenses/ExpenseCategoriesView.vue';
import ChartOfAccountsView from '../views/accounting/ChartOfAccountsView.vue';
import AccountingSettingsView from '../views/accounting/AccountingSettingsView.vue';
import SettingsView from '../views/settings/SettingsView.vue';
import ComingSoonView from '../views/ComingSoonView.vue';

/**
 * Application routes.
 *
 * Every new module adds its route here.
 */
const routes = [
  {
    path: '/login',
    component: AuthLayout,
    meta: { guestOnly: true },
    children: [
      {
        path: '',
        name: 'login',
        component: LoginView,
      },
    ],
  },

  {
    path: '/pos',
    name: 'pos',
    component: PosView,
    meta: { requiresAuth: true, titleKey: 'nav.pos', permission: 'pos.sell' },
  },

  {
    path: '/',
    component: AppLayout,
    meta: { requiresAuth: true },
    children: [
      {
        path: '',
        name: 'home',
        component: HomeView,
      },
      {
        path: 'users',
        name: 'users',
        component: UsersView,
        meta: { permission: 'users.view' },
      },
      {
        path: 'roles',
        name: 'roles',
        component: RolesView,
        meta: { titleKey: 'nav.roles', permission: 'roles.view' },
      },
      {
        path: 'products',
        name: 'products',
        component: ProductsView,
        meta: { permission: 'products.view' },
      },
      {
        path: 'products/barcodes',
        name: 'products.barcodes',
        component: BarcodePrintView,
        meta: { permission: 'products.print_barcodes' },
      },
      {
        path: 'product-categories',
        name: 'product-categories',
        component: ProductCategoriesView,
        meta: { permission: 'product_categories.view' },
      },
      {
        path: 'contacts',
        name: 'contacts',
        component: ContactsView,
        meta: { titleKey: 'nav.contactsGroup', permission: 'contacts.view' },
      },
      {
        path: 'customers',
        name: 'customers',
        component: ContactsView,
        props: { role: 'customer' },
        meta: { titleKey: 'nav.customers', permission: 'contacts.view' },
      },
      {
        path: 'suppliers',
        name: 'suppliers',
        component: ContactsView,
        props: { role: 'supplier' },
        meta: { titleKey: 'nav.suppliers', permission: 'contacts.view' },
      },
      {
        path: 'purchase-orders',
        name: 'purchase-orders',
        component: PurchaseOrdersView,
        meta: { titleKey: 'nav.purchaseOrders', permission: 'purchase_orders.view' },
      },
      {
        path: 'purchase-invoices',
        name: 'purchase-invoices',
        component: PurchaseInvoicesView,
        meta: { titleKey: 'nav.purchaseInvoices', permission: 'purchase_invoices.view' },
      },
      {
        path: 'invoices',
        name: 'invoices',
        component: PosSalesView,
        meta: { titleKey: 'nav.invoices', permission: ['pos_sales.view', 'pos.sell'] },
      },
      {
        path: 'pos/sales',
        redirect: '/invoices',
      },
      {
        path: 'pos/returns',
        name: 'pos.returns',
        component: PosReturnsView,
        meta: { titleKey: 'nav.posReturns', permission: ['pos_returns.view', 'pos_returns.create'] },
      },
      {
        path: 'pos/shifts',
        name: 'pos.shifts',
        component: PosShiftsView,
        meta: { titleKey: 'nav.posShifts', permission: ['pos_shifts.view', 'pos_shifts.manage'] },
      },
      {
        path: 'publications',
        name: 'publications',
        component: ComingSoonView,
        meta: { titleKey: 'nav.publications' },
      },
      {
        path: 'inventory',
        name: 'inventory',
        component: InventoryDocumentsView,
        meta: { titleKey: 'nav.inventory', permission: 'inventory_documents.view' },
      },
      {
        path: 'inventory/stocks',
        name: 'inventory.stocks',
        component: WarehouseStocksView,
        meta: { titleKey: 'nav.warehouseStocks', permission: 'warehouse_stocks.view' },
      },
      {
        path: 'inventory/stocktakes',
        name: 'inventory.stocktakes',
        component: StocktakesView,
        meta: { titleKey: 'nav.stocktaking', permission: 'stocktakes.view' },
      },
      {
        path: 'inventory/stock-issue',
        redirect: '/inventory',
      },
      {
        path: 'inventory/:type',
        name: 'inventory.by-type',
        component: InventoryDocumentsView,
        meta: { titleKey: 'nav.inventory', permission: 'inventory_documents.view' },
      },
      {
        path: 'warehouses/:id?',
        name: 'warehouses.edit',
        component: WarehouseEditView,
        meta: { titleKey: 'warehouses.editWarehouse', permission: 'warehouses.update' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ComingSoonView,
        meta: { titleKey: 'nav.reports' },
      },
      {
        path: 'expenses',
        name: 'expenses',
        component: ExpensesView,
        meta: { titleKey: 'nav.expenses', permission: 'expenses.view' },
      },
      {
        path: 'expense-categories',
        name: 'expense-categories',
        component: ExpenseCategoriesView,
        meta: { titleKey: 'nav.expenseCategories', permission: 'expense_categories.view' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: SettingsView,
        meta: { titleKey: 'nav.settings', permission: ['purchase_settings.view', 'accounting.settings.view'] },
      },
      {
        path: 'accounting/accounts',
        name: 'accounting.accounts',
        component: ChartOfAccountsView,
        meta: { titleKey: 'nav.chartOfAccounts', permission: 'accounting.accounts.view' },
      },
      {
        path: 'accounting/settings',
        name: 'accounting.settings',
        component: AccountingSettingsView,
        meta: { titleKey: 'nav.accountingSettings', permission: 'accounting.settings.view' },
      },
    ],
  },

  {
    path: '/:pathMatch(.*)*',
    redirect: '/',
  },
];

export default routes;

export const setupGuards = (router) => {
  router.beforeEach((to) => {
    const themeStore = useThemeStore();
    const authStore = useAuthStore();

    themeStore.initTheme();

    if (to.meta?.requiresAuth && !authStore.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } };
    }

    if (to.meta?.guestOnly && authStore.isAuthenticated) {
      return { name: 'home' };
    }

    if (to.meta?.permission && !authStore.hasPermission(to.meta.permission)) {
      return { name: 'home' };
    }

    return true;
  });
};
