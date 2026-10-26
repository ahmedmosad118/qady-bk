import { createRouter, createWebHistory } from 'vue-router';
import routes, { setupGuards } from './routes';

/**
 * Resolve the router base path so the app works both from
 * `php artisan serve` (/) and from XAMPP sub-folders (/kady-sys/public).
 */
const resolveBasePath = () => {
  if (typeof window === 'undefined') {
    return '/';
  }

  if (window.APP_BASE_PATH) {
    return window.APP_BASE_PATH;
  }

  const { pathname } = window.location;
  const publicIndex = pathname.indexOf('/public');

  if (publicIndex > -1) {
    return pathname.slice(0, publicIndex + '/public'.length).replace(/\/+$/, '');
  }

  return '/';
};

const router = createRouter({
  history: createWebHistory(resolveBasePath()),
  routes,
  scrollBehavior: () => ({ top: 0 }),
});

setupGuards(router);

export default router;
