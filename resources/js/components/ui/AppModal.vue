<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      data-app-modal
      class="fixed inset-0 z-[999] flex items-center justify-center overflow-y-auto p-3 sm:p-6"
      :dir="isRtl ? 'rtl' : 'ltr'"
      role="dialog"
      aria-modal="true"
    >
      <!-- Backdrop -->
      <Transition
        enter-active-class="transition-opacity ease-out duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity ease-in duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="isOpen"
          data-app-modal-backdrop
          class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
          @click="handleBackdropClick"
        />
      </Transition>

      <!-- Modal Panel -->
      <Transition
        enter-active-class="transition ease-out duration-200"
        enter-from-class="opacity-0 scale-95"
        enter-to-class="opacity-100 scale-100"
        leave-active-class="transition ease-in duration-150"
        leave-from-class="opacity-100 scale-100"
        leave-to-class="opacity-0 scale-95"
      >
        <div
          v-if="isOpen"
          data-app-modal-panel
          class="relative z-10 flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] shadow-2xl dark:shadow-black/40"
          :class="widthClass"
        >
          <!-- Modal Header -->
          <div
            v-if="$slots.header || title"
            data-app-modal-header
            class="shrink-0 border-b border-[var(--border)] bg-[var(--card)] px-5 py-4 sm:px-6"
          >
            <slot name="header">
              <div class="flex items-center justify-between gap-4">
                <div>
                  <h3 class="text-base font-extrabold text-[var(--text)] sm:text-lg">
                    {{ title }}
                  </h3>
                  <p v-if="subtitle" class="mt-0.5 text-xs text-[var(--muted)]">
                    {{ subtitle }}
                  </p>
                </div>
                <button
                  type="button"
                  class="print:hidden rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                  :aria-label="$t ? $t('common.close') : 'Close'"
                  :title="$t ? $t('common.close') : 'Close'"
                  @click="close"
                >
                  <X class="h-5 w-5" />
                </button>
              </div>
            </slot>
          </div>

          <!-- Optional Sticky Actions Bar -->
          <div
            v-if="$slots.actions"
            data-app-modal-actions
            class="print:hidden shrink-0 border-b border-[var(--border)] bg-[var(--bg)]/90 px-5 py-3 backdrop-blur-xs sm:px-6"
          >
            <slot name="actions" />
          </div>

          <!-- Modal Body (Scrollable) -->
          <div data-app-modal-body class="custom-scrollbar flex-1 overflow-y-auto px-5 py-5 sm:px-6">
            <slot />
          </div>

          <!-- Optional Footer -->
          <div
            v-if="$slots.footer"
            data-app-modal-footer
            class="print:hidden shrink-0 border-t border-[var(--border)] bg-[var(--bg)] px-5 py-3 sm:px-6"
          >
            <slot name="footer" />
          </div>
        </div>
      </Transition>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  modelValue: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: '',
  },
  subtitle: {
    type: String,
    default: '',
  },
  widthClass: {
    type: String,
    default: 'max-w-2xl sm:max-w-3xl lg:max-w-4xl',
  },
  closeOnBackdrop: {
    type: Boolean,
    default: true,
  },
  closeOnEsc: {
    type: Boolean,
    default: true,
  },
});

const emit = defineEmits(['close', 'update:modelValue']);

const { locale } = useI18n({ useScope: 'global' });
const isRtl = computed(() => locale.value === 'ar');
const isOpen = computed(() => props.open || props.modelValue);

const close = () => {
  emit('close');
  emit('update:modelValue', false);
};

const handleBackdropClick = () => {
  if (props.closeOnBackdrop) {
    close();
  }
};

const handleKeydown = (e) => {
  if (props.closeOnEsc && e.key === 'Escape' && isOpen.value) {
    close();
  }
};

watch(isOpen, (val) => {
  if (typeof document !== 'undefined') {
    document.body.style.overflow = val ? 'hidden' : '';
  }
});

onMounted(() => {
  if (typeof window !== 'undefined') {
    window.addEventListener('keydown', handleKeydown);
  }
});

onUnmounted(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('keydown', handleKeydown);
  }
  if (typeof document !== 'undefined') {
    document.body.style.overflow = '';
  }
});
</script>
