<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-sm font-semibold leading-5"
    :class="
      active
        ? 'border-[var(--success-border)] bg-[var(--success-soft)] text-[var(--success)]'
        : 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)]'
    "
  >
    <span
      class="h-1.5 w-1.5 shrink-0 rounded-full"
      :class="active ? 'bg-[var(--success)]' : 'bg-[var(--muted)]'"
      aria-hidden="true"
    ></span>
    <span>{{ active ? $t('users.statusActive') : $t('users.statusInactive') }}</span>
  </span>
</template>

<script setup>
defineProps({
  active: {
    type: Boolean,
    default: false,
  },
});
</script>
