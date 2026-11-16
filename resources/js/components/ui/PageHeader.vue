<template>
  <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="space-y-1.5">
      <h1 class="text-2xl font-extrabold tracking-tight leading-tight text-[var(--text)] sm:text-[30px]">
        {{ title }}
      </h1>
      <p
        v-if="subtitle"
        class="max-w-2xl text-sm leading-relaxed text-[var(--muted)]"
      >
        {{ subtitle }}
      </p>
    </div>

    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2.5 shrink-0">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  title: {
    type: String,
    required: true,
  },
  subtitle: {
    type: String,
    default: '',
  },
});
</script>
