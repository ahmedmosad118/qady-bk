import { defineStore } from 'pinia';
import i18n from '../i18n/index.js';

export const useNotificationStore = defineStore('notification', {
  state: () => ({
    toasts: [],
  }),

  actions: {
    addToast({ type = 'info', title = '', message = '', duration = 4000 }) {
      const id = Date.now() + Math.random().toString(36).substring(2, 7);
      const toast = { id, type, title, message };
      this.toasts.push(toast);

      if (duration > 0) {
        setTimeout(() => {
          this.removeToast(id);
        }, duration);
      }
    },

    success(message, title = '') {
      const resolvedTitle = title || i18n.global.t('common.successTitle');
      this.addToast({ type: 'success', title: resolvedTitle, message });
    },

    error(message, title = '') {
      const resolvedTitle = title || i18n.global.t('common.errorAlertTitle');
      this.addToast({ type: 'error', title: resolvedTitle, message });
    },

    info(message, title = '') {
      const resolvedTitle = title || i18n.global.t('common.info');
      this.addToast({ type: 'info', title: resolvedTitle, message });
    },

    show(message, type = 'info', title = '') {
      const resolvedTitle = title || (
        type === 'success'
          ? i18n.global.t('common.successTitle')
          : type === 'error'
            ? i18n.global.t('common.errorAlertTitle')
            : i18n.global.t('common.info')
      );
      this.addToast({ type, title: resolvedTitle, message });
    },

    removeToast(id) {
      this.toasts = this.toasts.filter((t) => t.id !== id);
    },
  },
});
