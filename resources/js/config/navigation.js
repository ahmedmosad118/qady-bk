import {
  BarChart3,
  BookOpen,
  LayoutDashboard,
  Package,
  Receipt,
  Settings,
  ShieldCheck,
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
        label: 'nav.invoices',
        to: '/invoices',
        icon: Receipt,
        badge: 7,
      },
      {
        label: 'nav.customers',
        to: '/customers',
        icon: UsersRound,
      },
    ],
  },
  {
    key: 'catalog',
    label: 'nav.catalogGroup',
    items: [
      {
        label: 'nav.publications',
        to: '/publications',
        icon: BookOpen,
      },
      {
        label: 'nav.inventory',
        to: '/inventory',
        icon: Package,
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
