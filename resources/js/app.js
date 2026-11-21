import './bootstrap';
import '../css/app.css';

import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router';
import i18n from './i18n';
import App from './App.vue';
import alertService from './services/alert';
import { useAuthStore } from './stores/auth';
import { setUnauthorizedHandler } from './services/api';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(i18n);
app.use(router);

let isRedirectingToLogin = false;

setUnauthorizedHandler(() => {
  const authStore = useAuthStore(pinia);
  authStore.clearAuth();

  const currentRoute = router.currentRoute.value;
  if (currentRoute.name !== 'login' && !isRedirectingToLogin) {
    isRedirectingToLogin = true;
    router
      .replace({
        name: 'login',
        query:
          currentRoute.fullPath && currentRoute.fullPath !== '/login'
            ? { redirect: currentRoute.fullPath }
            : undefined,
      })
      .finally(() => {
        isRedirectingToLogin = false;
      });
  }
});

app.config.globalProperties.$alert = alertService;
window.$alert = alertService;

app.mount('#app');


