<template>
  <div
    v-if="totalItems > 0"
    class="flex flex-col items-center justify-between gap-4 border-t border-[var(--border)] px-4 py-3.5 text-xs text-[var(--muted)] sm:flex-row sm:px-6"
  >
    <div class="flex flex-wrap items-center gap-3">
      <span>
        {{ rangeFrom }}–{{ rangeTo }} / {{ totalItems }}
        ({{ $t('common.page') }} {{ currentPage }} {{ $t('common.of') }} {{ lastPage }})
      </span>

      <label v-if="showPerPage" class="inline-flex items-center gap-2">
        <span class="font-semibold">{{ $t('common.perPage') }}</span>
        <select
          :value="activePerPage"
          class="h-8 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2 text-xs font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          @change="onPerPageChange($event)"
        >
          <option v-for="size in pageSizes" :key="size" :value="size">
            {{ size }}
          </option>
        </select>
      </label>
    </div>

    <div v-if="lastPage > 1" class="flex items-center gap-1.5">
      <button
        type="button"
        :disabled="currentPage <= 1 || loading"
        class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 font-semibold text-[var(--text)] transition hover:bg-[var(--bg)] disabled:cursor-not-allowed disabled:opacity-45 cursor-pointer"
        @click="emit('change-page', currentPage - 1)"
      >
        {{ $t('common.previous') }}
      </button>
      <button
        type="button"
        :disabled="currentPage >= lastPage || loading"
        class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 font-semibold text-[var(--text)] transition hover:bg-[var(--bg)] disabled:cursor-not-allowed disabled:opacity-45 cursor-pointer"
        @click="emit('change-page', currentPage + 1)"
      >
        {{ $t('common.next') }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  meta: {
    type: Object,
    default: () => ({ current_page: 1, last_page: 1, per_page: 10, total: 0 }),
  },
  itemsCount: {
    type: Number,
    default: 0,
  },
  perPage: {
    type: Number,
    default: null,
  },
  pageSizes: {
    type: Array,
    default: () => [10, 15, 25, 50, 100],
  },
  showPerPage: {
    type: Boolean,
    default: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['change-page', 'update:perPage', 'change-per-page']);

const currentPage = computed(() => Math.max(1, Number(props.meta?.current_page ?? 1)));
const lastPage = computed(() => Math.max(1, Number(props.meta?.last_page ?? 1)));
const totalItems = computed(() => Math.max(0, Number(props.meta?.total ?? props.itemsCount)));
const activePerPage = computed(() => Number(props.perPage ?? props.meta?.per_page ?? 10));

const rangeFrom = computed(() => {
  if (props.meta?.from !== undefined && props.meta?.from !== null) {
    return props.meta.from;
  }
  if (totalItems.value === 0 || props.itemsCount === 0) return 0;
  return (currentPage.value - 1) * activePerPage.value + 1;
});

const rangeTo = computed(() => {
  if (props.meta?.to !== undefined && props.meta?.to !== null) {
    return props.meta.to;
  }
  if (totalItems.value === 0 || props.itemsCount === 0) return 0;
  return Math.min(totalItems.value, rangeFrom.value + props.itemsCount - 1);
});

function onPerPageChange(event) {
  const nextSize = Number(event.target.value);
  emit('update:perPage', nextSize);
  emit('change-per-page', nextSize);
}
</script>
