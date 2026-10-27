<template>
  <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5">
    <div class="flex items-start justify-between gap-2 sm:gap-3">
      <div class="min-w-0">
        <p class="text-sm font-semibold leading-5 text-[var(--muted)] sm:leading-6">{{ label }}</p>
        <p class="mt-2 text-[22px] font-extrabold leading-none tabular-nums text-[var(--text)] sm:text-[26px]">
          {{ value }}
          <span v-if="unit" class="text-sm font-semibold text-[var(--muted)]">{{ unit }}</span>
        </p>
      </div>

      <span
        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[var(--crimson-soft)] text-[var(--crimson)] sm:h-10 sm:w-10"
        aria-hidden="true"
      >
        <component :is="icon" class="h-5 w-5" />
      </span>
    </div>

    <div class="mt-3 flex items-center justify-between gap-2 sm:mt-4">
      <span
        class="inline-flex items-center rounded-full px-2.5 py-1 text-sm font-semibold leading-5"
        :class="toneClass"
      >
        {{ delta }}
      </span>
      <span v-if="hint" class="hidden truncate text-sm text-[var(--muted)] sm:inline">{{ hint }}</span>
    </div>

    <SparkLine :points="points" class="mt-2 h-8 w-full sm:mt-3 sm:h-9" />
  </div>
</template>

<script setup>
import { computed } from 'vue';
import SparkLine from './SparkLine.vue';

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  value: {
    type: [String, Number],
    required: true,
  },
  unit: {
    type: String,
    default: '',
  },
  delta: {
    type: String,
    default: '',
  },
  hint: {
    type: String,
    default: '',
  },
  tone: {
    type: String,
    default: 'success',
  },
  icon: {
    type: [Object, Function],
    required: true,
  },
  points: {
    type: Array,
    default: () => [],
  },
});

const toneClass = computed(() => {
  const tones = {
    success: 'bg-[var(--success-soft)] text-[var(--success)]',
    warning: 'bg-[var(--gold-soft)] text-[var(--gold-strong)]',
    danger: 'bg-[var(--crimson-soft)] text-[var(--crimson)]',
    neutral: 'bg-[var(--bg)] text-[var(--muted)]',
  };

  return tones[props.tone] || tones.neutral;
});
</script>
