<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      data-app-drawer
      class="fixed inset-0 z-[999] overflow-hidden"
      :dir="isRtl ? 'rtl' : 'ltr'"
      role="dialog"
      aria-modal="true"
    >
      <!-- Backdrop -->
      <Transition
        enter-active-class="transition-opacity ease-out duration-300"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity ease-in duration-200"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div
          v-if="isOpen"
          data-app-drawer-backdrop
          class="fixed inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
          @click="handleBackdropClick"
        />
      </Transition>

      <!-- Drawer Panel Wrapper -->
      <div
        data-app-drawer-wrapper
        class="fixed inset-y-0 flex max-w-full pointer-events-none"
        :class="placementClasses"
      >
        <Transition
          enter-active-class="transform transition ease-out duration-300"
          :enter-from-class="slideFromClass"
          enter-to-class="translate-x-0"
          leave-active-class="transform transition ease-in duration-200"
          leave-from-class="translate-x-0"
          :leave-to-class="slideFromClass"
        >
          <div
            v-if="isOpen"
            data-app-drawer-panel
            class="pointer-events-auto relative flex h-full w-screen flex-col overflow-hidden bg-[var(--card)] text-[var(--text)] shadow-2xl transition-all"
            :class="[
              widthClass,
              borderSideClass
            ]"
          >
            <!-- Drawer Header -->
            <div
              v-if="$slots.header || title"
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
                    class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :aria-label="$t ? $t('common.close') : 'Close'"
                    @click="close"
                  >
                    <X class="h-5 w-5" />
                  </button>
                </div>
              </slot>
            </div>

            <!-- Sticky Top Actions Bar (Above content body) -->
            <div
              v-if="$slots.actions"
              class="shrink-0 border-b border-[var(--border)] bg-[var(--bg)]/90 px-5 py-3 backdrop-blur-xs sm:px-6"
            >
              <slot name="actions" />
            </div>

            <!-- Drawer Body (Scrollable) -->
            <div data-app-drawer-body class="custom-scrollbar flex-1 overflow-y-auto px-5 py-5 sm:px-6">
              <slot />
            </div>

            <!-- Optional Footer -->
            <div
              v-if="$slots.footer"
              class="shrink-0 border-t border-[var(--border)] bg-[var(--bg)] px-5 py-3 sm:px-6"
            >
              <slot name="footer" />
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, watch, onMounted, onUnmounted } from 'vue';
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
    default: 'max-w-2xl sm:max-w-3xl lg:max-w-4xl xl:max-w-5xl',
  },
  side: {
    type: String,
    default: 'end', // 'end' | 'start' | 'right' | 'left'
    validator: (v) => ['end', 'start', 'right', 'left'].includes(v),
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

// Determine physical anchor side ('right' or 'left')
const physicalSide = computed(() => {
  if (props.side === 'right') return 'right';
  if (props.side === 'left') return 'left';
  if (props.side === 'start') {
    return isRtl.value ? 'right' : 'left';
  }
  // props.side === 'end' (default)
  return isRtl.value ? 'left' : 'right';
});

const placementClasses = computed(() => {
  return physicalSide.value === 'right' ? 'right-0' : 'left-0';
});

const borderSideClass = computed(() => {
  return physicalSide.value === 'right'
    ? 'border-s border-[var(--border)]'
    : 'border-e border-[var(--border)]';
});

const slideFromClass = computed(() => {
  return physicalSide.value === 'right' ? 'translate-x-full' : '-translate-x-full';
});

function close() {
  emit('close');
  emit('update:modelValue', false);
}

function handleBackdropClick() {
  if (props.closeOnBackdrop) {
    close();
  }
}

function handleKeyDown(event) {
  if (props.closeOnEsc && event.key === 'Escape' && isOpen.value) {
    close();
  }
}

// Lock body scrolling when drawer is open
watch(
  isOpen,
  (val) => {
    if (typeof document !== 'undefined') {
      if (val) {
        document.body.style.overflow = 'hidden';
      } else {
        document.body.style.overflow = '';
      }
    }
  },
  { immediate: true }
);

onMounted(() => {
  if (typeof window !== 'undefined') {
    window.addEventListener('keydown', handleKeyDown);
  }
});

onUnmounted(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('keydown', handleKeyDown);
  }
  if (typeof document !== 'undefined') {
    document.body.style.overflow = '';
  }
});
</script>
