<template>
  <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
    <!-- Optional Toolbar (FilterToolbar, BulkActionsBar, etc.) -->
    <slot name="toolbar" />

    <!-- Error State -->
    <div
      v-if="error"
      class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center"
      role="alert"
    >
      <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
      <p class="text-sm font-bold text-[var(--text)]">{{ error }}</p>
      <button
        type="button"
        class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
        @click="emit('retry')"
      >
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Table Section -->
    <div v-else class="overflow-x-auto">
      <table class="w-full text-start text-sm">
        <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
          <slot name="head" />
        </thead>

        <tbody class="divide-y divide-[var(--border)]">
          <tr v-if="loading">
            <td :colspan="colspan" class="py-16 text-center">
              <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                <span>{{ loadingText || $t('common.loading') }}</span>
              </div>
            </td>
          </tr>

          <tr v-else-if="items.length === 0">
            <td :colspan="colspan" class="py-16 text-center">
              <div class="mx-auto flex max-w-sm flex-col items-center">
                <slot name="empty-icon">
                  <Inbox class="h-10 w-10 text-[var(--muted)]/50" />
                </slot>
                <h3 class="mt-3 text-sm font-extrabold text-[var(--text)]">
                  {{ emptyTitle || $t('common.noData') }}
                </h3>
                <p class="mt-1 text-xs text-[var(--muted)]">
                  {{ emptySubtitle || $t('common.noDataDesc') }}
                </p>
              </div>
            </td>
          </tr>

          <tr
            v-for="(item, index) in items"
            v-else
            :key="item?.[rowKey] ?? index"
            class="transition-colors hover:bg-[var(--bg)]/50"
            :class="{ 'cursor-pointer': rowClickable }"
            @click="rowClickable ? emit('row-click', item) : undefined"
          >
            <slot name="row" :item="item" :index="index" />
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Unified Pagination & Page Size Footer -->
    <TablePagination
      v-if="!error"
      :meta="meta"
      :items-count="items.length"
      :per-page="perPage"
      :page-sizes="pageSizes"
      :show-per-page="showPerPage"
      :loading="loading"
      @change-page="emit('change-page', $event)"
      @update:per-page="emit('update:perPage', $event)"
      @change-per-page="emit('change-per-page', $event)"
    />
  </div>
</template>

<script setup>
import { AlertCircle, Inbox } from 'lucide-vue-next';
import TablePagination from './TablePagination.vue';

const props = defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  rowKey: {
    type: String,
    default: 'id',
  },
  loading: {
    type: Boolean,
    default: false,
  },
  error: {
    type: String,
    default: '',
  },
  colspan: {
    type: Number,
    default: 1,
  },
  meta: {
    type: Object,
    default: () => ({ current_page: 1, last_page: 1, per_page: 10, total: 0 }),
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
  emptyTitle: {
    type: String,
    default: '',
  },
  emptySubtitle: {
    type: String,
    default: '',
  },
  loadingText: {
    type: String,
    default: '',
  },
  rowClickable: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits([
  'change-page',
  'update:perPage',
  'change-per-page',
  'retry',
  'row-click',
]);
</script>
