import { defineStore } from 'pinia';
import api from '../services/api.js';
import i18n from '../i18n';
import { AUTH_STORAGE_KEYS, STORAGE_KEYS } from '../config/storage.js';

/**
 * Auth persistence helpers.
 */
const readKey = (key) => {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(key) ?? sessionStorage.getItem(key);
};

const authStorage = () =>
  typeof window !== 'undefined' ? localStorage : sessionStorage;

const persistSession = (data) => {
  // Always persist in localStorage to share session seamlessly across tabs and prevent sudden logouts on tab close.
  const target = localStorage;

  // Clean up any stale sessionStorage keys
  AUTH_STORAGE_KEYS.forEach((key) => sessionStorage.removeItem(key));

  target.setItem(STORAGE_KEYS.TOKEN, data.token);
  target.setItem(STORAGE_KEYS.USER, JSON.stringify(data.user));
  target.setItem(STORAGE_KEYS.PERMISSIONS, JSON.stringify(data.permissions || []));
  target.setItem(STORAGE_KEYS.ROLES, JSON.stringify(data.roles || []));
};

const clearSession = () => {
  AUTH_STORAGE_KEYS.forEach((key) => {
    localStorage.removeItem(key);
    sessionStorage.removeItem(key);
  });
};

const persistUser = (user) => {
  authStorage().setItem(STORAGE_KEYS.USER, JSON.stringify(user));
};

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: JSON.parse(readKey(STORAGE_KEYS.USER) || 'null'),
    token: readKey(STORAGE_KEYS.TOKEN) || null,
    permissions: JSON.parse(readKey(STORAGE_KEYS.PERMISSIONS) || '[]'),
    roles: JSON.parse(readKey(STORAGE_KEYS.ROLES) || '[]'),
    loading: false,
    error: null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token && !!state.user,
    isOwner: (state) => state.roles.includes('Owner'),
    isSuperAdmin: (state) => state.roles.includes('Owner') || state.roles.includes('Super Admin'),
  },

  actions: {
    async login(email, password, remember = true) {
      this.loading = true;
      this.error = null;

      try {
        const response = await api.post('/auth/login', {
          email,
          password,
        });

        const data = response.data.data;
        const permissions = data.user.permissions || [];
        const roles = data.user.roles || [];

        this.token = data.token;
        this.user = data.user;
        this.permissions = permissions;
        this.roles = roles;

        persistSession(
          { token: data.token, user: this.user, permissions, roles },
          remember
        );

        return { success: true, data };
      } catch (err) {
        this.error = err.response?.data?.message || i18n.global.t('auth.loginFailed');
        return {
          success: false,
          message: this.error,
          errors: err.response?.data?.errors,
          status: err.response?.status,
        };
      } finally {
        this.loading = false;
      }
    },

    async fetchMe() {
      if (!this.token) return;

      try {
        const response = await api.get('/auth/me');
        const data = response.data.data;
        this.user = data.user;
        this.permissions = data.user.permissions || [];
        this.roles = data.user.roles || [];

        const storage = authStorage();
        storage.setItem(STORAGE_KEYS.USER, JSON.stringify(this.user));
        storage.setItem(STORAGE_KEYS.PERMISSIONS, JSON.stringify(this.permissions));
        storage.setItem(STORAGE_KEYS.ROLES, JSON.stringify(this.roles));
      } catch (err) {
        // Only log out if backend explicitly rejected the token (401 Unauthorized).
        // Temporary server hiccups (500), DB disconnects, or network drops should NOT kick the user out.
        if (err.response?.status === 401) {
          this.clearAuth();
        } else {
          console.warn('Failed to refresh user profile from server:', err);
        }
      }
    },

    clearAuth() {
      this.user = null;
      this.token = null;
      this.permissions = [];
      this.roles = [];

      clearSession();
    },

    async logout() {
      try {
        await api.post('/auth/logout');
      } catch (err) {
        // Ignore
      } finally {
        this.clearAuth();
      }
    },

    async updateProfile(payload) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.put('/auth/profile', payload);
        const updatedUser = response.data.data.user;
        this.user = { ...this.user, ...updatedUser };
        persistUser(this.user);
        return { success: true, message: response.data.message, data: response.data.data };
      } catch (err) {
        const message = err.response?.data?.message || i18n.global.t('profile.profileUpdateFailed');
        return {
          success: false,
          message,
          errors: err.response?.data?.errors,
        };
      } finally {
        this.loading = false;
      }
    },

    async uploadAvatar(file) {
      this.loading = true;
      this.error = null;
      try {
        const formData = new FormData();
        formData.append('avatar', file);

        const response = await api.post('/auth/profile/avatar', formData, {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        });

        const updatedUser = response.data.user;
        if (updatedUser) {
          this.user = { ...this.user, ...updatedUser, avatar_url: response.data.avatar_url };
        } else {
          this.user = { ...this.user, avatar_url: response.data.avatar_url };
        }

        persistUser(this.user);
        return {
          success: true,
          message: response.data.message,
          avatar_url: response.data.avatar_url,
        };
      } catch (err) {
        const message = err.response?.data?.message || i18n.global.t('profile.avatarUploadFailed');
        return {
          success: false,
          message,
          errors: err.response?.data?.errors,
        };
      } finally {
        this.loading = false;
      }
    },

    async changePassword(payload) {
      this.loading = true;
      this.error = null;
      try {
        const response = await api.post('/auth/change-password', payload);
        return { success: true, message: response.data.message };
      } catch (err) {
        const message = err.response?.data?.message || i18n.global.t('profile.passwordChangeFailed');
        return {
          success: false,
          message,
          errors: err.response?.data?.errors,
        };
      } finally {
        this.loading = false;
      }
    },

    hasPermission(permission) {
      if (this.isOwner || this.isSuperAdmin) return true;
      if (!this.permissions || this.permissions.length === 0) {
        // When no granular permissions list is returned from backend, operational gates default to allowed (matching Laravel Gate definitions)
        return true;
      }
      if (Array.isArray(permission)) {
        return permission.some((p) => this.permissions.includes(p));
      }
      return this.permissions.includes(permission);
    },

    hasRole(role) {
      if (Array.isArray(role)) {
        return role.some((r) => this.roles.includes(r));
      }
      return this.roles.includes(role);
    },
  },
});
