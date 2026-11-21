import axios from 'axios';
import { AUTH_STORAGE_KEYS, STORAGE_KEYS } from '../config/storage.js';

// Calculate dynamic API base URL
const getApiBaseUrl = () => {
  if (typeof window !== 'undefined' && window.APP_BASE_URL) {
    try {
      const url = new URL(window.APP_BASE_URL);
      const pathname = url.pathname.replace(/\/+$/, '');
      return `${pathname}/api`;
    } catch (e) {
      // Ignore
    }
  }

  if (typeof window !== 'undefined' && window.location.pathname.includes('/public')) {
    const idx = window.location.pathname.indexOf('/public');
    const prefix = window.location.pathname.substring(0, idx + 7).replace(/\/+$/, '');
    return `${prefix}/api`;
  }

  return '/api';
};

const api = axios.create({
  baseURL: getApiBaseUrl(),
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

// Request Interceptor: Attach Token & Locale Context
api.interceptors.request.use((config) => {
  const token = localStorage.getItem(STORAGE_KEYS.TOKEN) || sessionStorage.getItem(STORAGE_KEYS.TOKEN);
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  // Locale header
  const locale = localStorage.getItem(STORAGE_KEYS.LOCALE) || 'ar';
  config.headers['X-Locale'] = locale;
  config.headers['Accept-Language'] = locale;

  if (config.data instanceof FormData) {
    delete config.headers['Content-Type'];
  }

  return config;
}, (error) => {
  return Promise.reject(error);
});

let onUnauthorized = null;

/**
 * Register a callback invoked when an authenticated API request receives 401.
 */
export const setUnauthorizedHandler = (handler) => {
  onUnauthorized = handler;
};

// Response Interceptor: Handle Global Auth Expiry
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const url = error.config?.url || '';
    // Only handle global session expiry on 401 when calling protected routes (skip login/logout)
    if (
      error.response?.status === 401 &&
      !url.includes('/auth/login') &&
      !url.includes('/auth/logout')
    ) {
      AUTH_STORAGE_KEYS.forEach((key) => {
        localStorage.removeItem(key);
        sessionStorage.removeItem(key);
      });
      if (typeof onUnauthorized === 'function') {
        onUnauthorized();
      }
    }
    return Promise.reject(error);
  }
);

export default api;
