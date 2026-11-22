import { useAuthStore } from '../stores/auth';
import { useThemeStore } from '../stores/theme';

import AuthLayout from '../layouts/AuthLayout.vue';
import AppLayout from '../layouts/AppLayout.vue';

import LoginView from '../views/auth/LoginView.vue';
import HomeView from '../views/HomeView.vue';
import UsersView from '../views/team/UsersView.vue';
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
    meta: { requiresAuth: true, titleKey: 'nav.pos' },
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
      },
      {
        path: 'products',
        name: 'products',
        component: ProductsView,
      },
      {
        path: 'products/barcodes',
        name: 'products.barcodes',
        component: BarcodePrintView,
      },
      {
        path: 'product-categories',
        name: 'product-categories',
        component: ProductCategoriesView,
      },
      {
        path: 'contacts',
        name: 'contacts',
        component: ContactsView,
        meta: { titleKey: 'nav.contactsGroup' },
      },
      {
        path: 'customers',
        name: 'customers',
        component: ContactsView,
        props: { role: 'customer' },
        meta: { titleKey: 'nav.customers' },
      },
      {
        path: 'suppliers',
        name: 'suppliers',
        component: ContactsView,
        props: { role: 'supplier' },
        meta: { titleKey: 'nav.suppliers' },
      },
      {
        path: 'purchase-orders',
        name: 'purchase-orders',
        component: PurchaseOrdersView,
        meta: { titleKey: 'nav.purchaseOrders' },
      },
      {
        path: 'purchase-invoices',
        name: 'purchase-invoices',
        component: PurchaseInvoicesView,
        meta: { titleKey: 'nav.purchaseInvoices' },
      },
      {
        path: 'invoices',
        name: 'invoices',
        component: PosSalesView,
        meta: { titleKey: 'nav.invoices' },
      },
      {
        path: 'pos/sales',
        redirect: '/invoices',
      },
      {
        path: 'pos/returns',
        name: 'pos.returns',
        component: PosReturnsView,
        meta: { titleKey: 'nav.posReturns' },
      },
      {
        path: 'pos/shifts',
        name: 'pos.shifts',
        component: PosShiftsView,
        meta: { titleKey: 'nav.posShifts' },
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
        meta: { titleKey: 'nav.inventory' },
      },
      {
        path: 'inventory/stocks',
        name: 'inventory.stocks',
        component: WarehouseStocksView,
        meta: { titleKey: 'nav.warehouseStocks' },
      },
      {
        path: 'inventory/stocktakes',
        name: 'inventory.stocktakes',
        component: StocktakesView,
        meta: { titleKey: 'nav.stocktaking' },
      },
      {
        path: 'inventory/stock-issue',
        redirect: '/inventory',
      },
      {
        path: 'inventory/:type',
        name: 'inventory.by-type',
        component: InventoryDocumentsView,
        meta: { titleKey: 'nav.inventory' },
      },
      {
        path: 'warehouses/:id?',
        name: 'warehouses.edit',
        component: WarehouseEditView,
        meta: { titleKey: 'warehouses.editWarehouse' },
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
        meta: { titleKey: 'nav.expenses' },
      },
      {
        path: 'expense-categories',
        name: 'expense-categories',
        component: ExpenseCategoriesView,
        meta: { titleKey: 'nav.expenseCategories' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: SettingsView,
        meta: { titleKey: 'nav.settings' },
      },
      {
        path: 'accounting/accounts',
        name: 'accounting.accounts',
        component: ChartOfAccountsView,
        meta: { titleKey: 'nav.chartOfAccounts' },
      },
      {
        path: 'accounting/settings',
        name: 'accounting.settings',
        component: AccountingSettingsView,
        meta: { titleKey: 'nav.accountingSettings' },
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

    return true;
  });
};
