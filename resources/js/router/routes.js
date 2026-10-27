import { useAuthStore } from '../stores/auth';
import { useThemeStore } from '../stores/theme';

import AuthLayout from '../layouts/AuthLayout.vue';
import AppLayout from '../layouts/AppLayout.vue';

import LoginView from '../views/auth/LoginView.vue';
import HomeView from '../views/HomeView.vue';
import UsersView from '../views/team/UsersView.vue';
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
        path: 'invoices',
        name: 'invoices',
        component: ComingSoonView,
        meta: { titleKey: 'nav.invoices' },
      },
      {
        path: 'customers',
        name: 'customers',
        component: ComingSoonView,
        meta: { titleKey: 'nav.customers' },
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
        component: ComingSoonView,
        meta: { titleKey: 'nav.inventory' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ComingSoonView,
        meta: { titleKey: 'nav.reports' },
      },
      {
        path: 'settings',
        name: 'settings',
        component: ComingSoonView,
        meta: { titleKey: 'nav.settings' },
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
