<template>
  <button
    type="button"
    class="inline-flex h-10 items-center gap-2 rounded-xl border px-3.5 text-sm font-semibold transition-colors"
    :class="
      active
        ? 'border-transparent bg-[var(--text)] text-[var(--card)] dark:bg-[var(--crimson)] dark:text-white'
        : 'border-[var(--border)] bg-[var(--card)] text-[var(--muted)] hover:border-[var(--crimson)]/35 hover:text-[var(--text)]'
    "
    :aria-pressed="active"
    @click="$emit('click')"
  >
    <span>{{ label }}</span>
    <span
      class="inline-flex min-w-5 items-center justify-center rounded-md px-1.5 text-sm font-bold tabular-nums"
      :class="
        active
          ? 'bg-black/10 text-[var(--card)] dark:bg-white/15 dark:text-white'
          : 'bg-[var(--bg)] text-[var(--muted)]'
      "
    >
      {{ count }}
    </span>
  </button>
</template>

<script setup>
defineProps({
  label: {
    type: String,
    required: true,
  },
  count: {
    type: [Number, String],
    default: 0,
  },
  active: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['click']);
</script>
