import {
  ArrowDownToLine,
  ArrowLeftRight,
  ArrowUpFromLine,
  BarChart3,
  BookOpen,
  Boxes,
  ClipboardCheck,
  Clock,
  FileOutput,
  Layers,
  LayoutDashboard,
  Package,
  Receipt,
  RotateCcw,
  Settings,
  ShieldCheck,
  ShoppingBag,
  SlidersHorizontal,
  Store,
  Tags,
  Trash2,
  Truck,
  UsersRound,
} from 'lucide-vue-next';

/**
 * Sidebar navigation definition.
 *
 * Groups with `items` render as always-visible sections (matching the
 * dashboard design); groups with `children` would render as collapsible.
 *
 * To add a new module, push an entry here and register its route in
 * `resources/js/router/routes.js`.
 */
export const navigation = [
  {
    key: 'main',
    label: null,
    items: [
      {
        label: 'nav.dashboard',
        to: '/',
        icon: LayoutDashboard,
      },
    ],
  },
  {
    key: 'sales',
    label: 'nav.salesGroup',
    items: [
      {
        label: 'nav.pos',
        to: '/pos',
        icon: Store,
      },
      {
        label: 'nav.invoices',
        to: '/invoices',
        icon: Receipt,
      },
      {
        label: 'nav.posShifts',
        to: '/pos/shifts',
        icon: Clock,
      },
      {
        label: 'nav.customers',
        to: '/customers',
        icon: UsersRound,
      },
    ],
  },
  {
    key: 'suppliers',
    label: 'nav.suppliersGroup',
    items: [
      {
        label: 'nav.purchaseOrders',
        to: '/purchase-orders',
        icon: ShoppingBag,
      },
      {
        label: 'nav.suppliers',
        to: '/suppliers',
        icon: Truck,
      },
    ],
  },
  {
    key: 'catalog',
    label: 'nav.catalogGroup',
    items: [
      {
        label: 'nav.productCategories',
        to: '/product-categories',
        icon: Tags,
      },
      {
        label: 'nav.products',
        to: '/products',
        icon: BookOpen,
      },
    ],
  },
  {
    key: 'inventory',
    label: 'nav.inventoryGroup',
    children: [
      {
        label: 'nav.warehouseTransfer',
        to: '/inventory/warehouse-transfer',
        icon: ArrowLeftRight,
      },
      {
        label: 'nav.goodsReceipt',
        to: '/inventory/goods-receipt',
        icon: ArrowDownToLine,
      },
      {
        label: 'nav.goodsDelivery',
        to: '/inventory/goods-delivery',
        icon: ArrowUpFromLine,
      },
      {
        label: 'nav.stockAdjustment',
        to: '/inventory/stock-adjustment',
        icon: SlidersHorizontal,
      },
      {
        label: 'nav.stockWriteOff',
        to: '/inventory/stock-write-off',
        icon: Trash2,
      },
      {
        label: 'nav.warehouseStocks',
        to: '/inventory/stocks',
        icon: Boxes,
      },
      {
        label: 'nav.stocktaking',
        to: '/inventory/stocktakes',
        icon: ClipboardCheck,
      },
    ],
  },
  {
    key: 'admin',
    label: 'nav.adminGroup',
    items: [
      {
        label: 'nav.reports',
        to: '/reports',
        icon: BarChart3,
      },
      {
        label: 'nav.users',
        to: '/users',
        icon: ShieldCheck,
      },
      {
        label: 'nav.settings',
        to: '/settings',
        icon: Settings,
      },
    ],
  },
];

export default navigation;
