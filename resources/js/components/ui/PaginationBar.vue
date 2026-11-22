<template>
  <div
    v-if="meta.last_page > 1"
    class="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--border)] px-4 py-3 text-xs text-[var(--muted)]"
  >
    <span>
      {{ $t('common.paginationSummary', { current: meta.current_page, last: meta.last_page, total: meta.total }) }}
    </span>
    <div class="flex items-center gap-1.5">
      <button
        type="button"
        :disabled="meta.current_page <= 1"
        class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 py-1.5 font-semibold text-[var(--text)] transition hover:bg-[var(--surface)] disabled:cursor-not-allowed disabled:opacity-45"
        @click="$emit('change-page', meta.current_page - 1)"
      >
        {{ $t('common.previous') }}
      </button>
      <button
        type="button"
        :disabled="meta.current_page >= meta.last_page"
        class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 py-1.5 font-semibold text-[var(--text)] transition hover:bg-[var(--surface)] disabled:cursor-not-allowed disabled:opacity-45"
        @click="$emit('change-page', meta.current_page + 1)"
      >
        {{ $t('common.next') }}
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  meta: {
    type: Object,
    required: true,
  },
});

defineEmits(['change-page']);
</script>
