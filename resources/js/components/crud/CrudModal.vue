<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-[999] flex items-center justify-center overflow-y-auto bg-black/60 p-3 backdrop-blur-sm transition-opacity sm:p-6"
      :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
    >
      <div
        class="relative flex max-h-[92vh] w-full flex-col rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 shadow-2xl animate-in fade-in zoom-in-95 duration-200 dark:shadow-black/40 sm:p-7"
        :class="modalSizeClass"
      >
        <!-- Modal Header -->
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[var(--border)] pb-4">
          <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
              <PencilLine v-if="mode === 'edit'" class="h-5 w-5" aria-hidden="true" />
              <Plus v-else class="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
              <h3 class="text-base font-extrabold text-[var(--text)]">
                {{ title }}
              </h3>
            </div>
          </div>
          <button
            type="button"
            class="rounded-lg p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            :aria-label="$t('common.close')"
            :title="$t('common.close')"
            @click="$emit('close')"
          >
            <X class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <!-- Modal Body (Scrollable) -->
        <div class="custom-scrollbar flex-1 overflow-y-auto px-1 py-5">
          <slot />
        </div>

        <!-- Modal Footer -->
        <div
          v-if="showFooter"
          class="mt-1 flex shrink-0 items-center justify-end gap-3 border-t border-[var(--border)] pt-4"
        >
          <button
            v-if="showCancelButton"
            type="button"
            class="cursor-pointer rounded-xl border border-[var(--border)] px-5 py-2.5 text-sm font-semibold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
            @click="$emit('close')"
          >
            {{ cancelText || (!showSaveButton ? $t('common.close') : $t('common.cancel')) }}
          </button>

          <button
            v-if="showSaveButton"
            type="button"
            :disabled="loading"
            class="flex cursor-pointer items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2.5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50"
            @click="$emit('save')"
          >
            <span v-if="loading" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            <span>{{ saveText || $t('common.save') }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';
import { X, Plus, PencilLine } from 'lucide-vue-next';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: '',
  },
  mode: {
    type: String,
    default: 'create',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  size: {
    type: String,
    default: '5xl',
  },
  showSaveButton: {
    type: Boolean,
    default: true,
  },
  showCancelButton: {
    type: Boolean,
    default: true,
  },
  showFooter: {
    type: Boolean,
    default: true,
  },
  saveText: {
    type: String,
    default: '',
  },
  cancelText: {
    type: String,
    default: '',
  },
});

defineEmits(['close', 'save']);

const modalSizeClass = computed(() => {
  const sizeMap = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-3xl',
    '2xl': 'max-w-4xl',
    '3xl': 'max-w-4xl',
    '4xl': 'max-w-5xl',
    '5xl': 'max-w-6xl',
    '6xl': 'max-w-7xl',
    full: 'max-w-[95vw]',
  };
  return sizeMap[props.size] || 'max-w-5xl';
});
</script>
