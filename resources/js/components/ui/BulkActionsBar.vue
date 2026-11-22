<template>
  <div
    v-if="count > 0"
    class="flex flex-col gap-3 border-b border-[var(--border)] bg-[var(--crimson-soft)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
  >
    <p class="text-sm font-bold text-[var(--text)]">
      {{ label || $t('common.selectedItemsCount', { count }) }}
    </p>
    <div class="flex flex-wrap items-center gap-2">
      <slot name="actions" />
      <button
        v-if="canDelete"
        type="button"
        :disabled="loading"
        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[var(--crimson)] px-3.5 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)] disabled:cursor-not-allowed disabled:opacity-60 cursor-pointer"
        @click="$emit('delete')"
      >
        <Trash2 class="h-4 w-4" aria-hidden="true" />
        <span>{{ deleteLabel || $t('common.deleteSelected') }}</span>
      </button>
      <button
        type="button"
        :disabled="loading"
        class="inline-flex h-9 items-center rounded-lg border border-[var(--border)] bg-[var(--card)] px-3.5 text-sm font-semibold text-[var(--muted)] transition-colors hover:text-[var(--text)] disabled:cursor-not-allowed disabled:opacity-60 cursor-pointer"
        @click="$emit('clear')"
      >
        {{ clearLabel || $t('common.clearSelection') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { Trash2 } from 'lucide-vue-next';

defineProps({
  count: {
    type: Number,
    required: true,
  },
  label: {
    type: String,
    default: '',
  },
  deleteLabel: {
    type: String,
    default: '',
  },
  clearLabel: {
    type: String,
    default: '',
  },
  canDelete: {
    type: Boolean,
    default: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['delete', 'clear']);
</script>
