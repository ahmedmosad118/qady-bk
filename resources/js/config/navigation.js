import { LayoutDashboard, Users } from 'lucide-vue-next';

/**
 * Sidebar navigation definition.
 *
 * Every group is rendered as a collapsible section when it has `children`,
 * or as a plain list when it has `items`.
 *
 * To add a new module, push an entry here and register its route in
 * `resources/js/router/routes.js`.
 */
export const navigation = [
  {
    key: 'main',
    label: 'nav.main',
    items: [
      {
        label: 'nav.dashboard',
        to: '/',
        icon: LayoutDashboard,
      },
    ],
  },
  {
    key: 'user-management',
    label: 'nav.userManagement',
    children: [
      {
        label: 'nav.users',
        to: '/users',
        icon: Users,
      },
    ],
  },
];

export default navigation;
