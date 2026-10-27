<template>
  <div
    class="pointer-events-none fixed inset-x-0 top-4 z-[100] flex flex-col items-center gap-2 px-4"
    aria-live="polite"
    aria-atomic="false"
  >
    <TransitionGroup name="toast">
      <div
        v-for="toast in notificationStore.toasts"
        :key="toast.id"
        class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-lg shadow-black/[0.06] dark:shadow-black/30"
        role="status"
      >
        <span
          class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
          :class="iconWrapClass(toast.type)"
          aria-hidden="true"
        >
          <CheckCircle2 v-if="toast.type === 'success'" class="h-[18px] w-[18px]" />
          <AlertCircle v-else-if="toast.type === 'error'" class="h-[18px] w-[18px]" />
          <Info v-else class="h-[18px] w-[18px]" />
        </span>

        <div class="min-w-0 flex-1 pt-0.5">
          <p v-if="toast.title" class="text-sm font-bold leading-5 text-[var(--text)]">
            {{ toast.title }}
          </p>
          <p v-if="toast.message" class="mt-1 text-sm leading-6 text-[var(--muted)]">
            {{ toast.message }}
          </p>
        </div>

        <button
          type="button"
          class="-me-1 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
          :aria-label="$t('common.close')"
          :title="$t('common.close')"
          @click="notificationStore.removeToast(toast.id)"
        >
          <X class="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup>
import { AlertCircle, CheckCircle2, Info, X } from 'lucide-vue-next';
import { useNotificationStore } from '../../stores/notification';

const notificationStore = useNotificationStore();

const iconWrapClass = (type) => {
  if (type === 'success') return 'bg-[var(--success-soft)] text-[var(--success)]';
  if (type === 'error') return 'bg-[var(--crimson-soft)] text-[var(--crimson)]';
  return 'bg-[var(--gold-soft)] text-[var(--gold)]';
};
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-8px);
}

.toast-move {
  transition: transform 0.18s ease;
}
</style>
