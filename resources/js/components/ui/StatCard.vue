<template>
  <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 py-3 sm:px-5 sm:py-4">
    <p class="text-sm font-semibold leading-6 text-[var(--muted)]">
      <span class="sm:hidden">{{ shortLabel || label }}</span>
      <span class="hidden sm:inline">{{ label }}</span>
    </p>
    <p class="mt-2 text-xl font-extrabold leading-none tabular-nums text-[var(--text)] sm:mt-3 sm:text-[30px]">
      {{ loading ? '—' : value }}
    </p>
  </div>
</template>

<script setup>
defineProps({
  label: {
    type: String,
    required: true,
  },
  shortLabel: {
    type: String,
    default: '',
  },
  value: {
    type: [Number, String],
    default: 0,
  },
  loading: {
    type: Boolean,
    default: false,
  },
});
</script>
