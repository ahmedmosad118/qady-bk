<template>
  <div class="flex flex-col gap-2.5 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-center gap-2 min-w-0">
      <h1 class="text-lg font-extrabold tracking-tight leading-tight text-[var(--text)] sm:text-[22px] truncate">
        {{ title }}
      </h1>

      <div v-if="subtitle" class="group relative inline-flex shrink-0 items-center">
        <button
          type="button"
          class="inline-flex h-6 w-6 items-center justify-center rounded-full text-[var(--muted)] transition-colors hover:bg-[var(--card)] hover:text-[var(--text)] focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--crimson)]/30 cursor-pointer"
          :aria-label="subtitle"
          :title="subtitle"
        >
          <Info class="h-4 w-4" aria-hidden="true" />
        </button>
        <div
          role="tooltip"
          class="pointer-events-none invisible absolute start-0 top-full z-30 mt-1.5 w-64 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-2 text-xs font-medium leading-relaxed text-[var(--muted)] opacity-0 shadow-lg transition-all duration-150 group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100 sm:w-72"
        >
          {{ subtitle }}
        </div>
      </div>
    </div>

    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2 shrink-0">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup>
import { Info } from 'lucide-vue-next';

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
