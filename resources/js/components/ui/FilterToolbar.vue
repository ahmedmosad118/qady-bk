<template>
  <div class="border-b border-[var(--border)] p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <!-- Search Input -->
      <div class="relative w-full sm:max-w-md">
        <Search
          class="pointer-events-none absolute start-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[var(--muted)]"
          aria-hidden="true"
        />
        <input
          :value="search"
          type="search"
          class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] ps-11 pe-4 text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/70 focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          :placeholder="searchPlaceholder || $t('common.searchPlaceholder')"
          :aria-label="$t('common.search')"
          @input="onSearchInput"
        />
      </div>

      <!-- Filter Drawer Trigger + Reset Button + Optional Actions -->
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="$slots.default"
          type="button"
          class="inline-flex h-11 items-center justify-center gap-2 rounded-xl border px-4 text-sm font-bold transition-colors cursor-pointer"
          :class="activeFiltersCount > 0
            ? 'border-[var(--crimson)]/40 bg-[var(--crimson-soft)] text-[var(--crimson)]'
            : 'border-[var(--border)] bg-[var(--bg)] text-[var(--text)] hover:border-[var(--crimson)]/35 hover:bg-[var(--card)]'"
          @click="drawerOpen = true"
        >
          <SlidersHorizontal class="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ $t('common.filters') }}</span>
          <span
            v-if="activeFiltersCount > 0"
            class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-[var(--crimson)] px-1.5 text-[11px] font-extrabold text-white"
          >
            {{ activeFiltersCount }}
          </span>
        </button>

        <button
          type="button"
          :disabled="!hasAnyFilter"
          class="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--muted)] transition-colors enabled:cursor-pointer enabled:hover:border-[var(--crimson)]/40 enabled:hover:text-[var(--crimson)] disabled:cursor-not-allowed disabled:opacity-45"
          :title="$t('common.resetFilters')"
          @click="handleReset"
        >
          <RotateCcw class="h-4 w-4 shrink-0" aria-hidden="true" />
          <span>{{ $t('common.resetFilters') }}</span>
        </button>

        <slot name="actions" />
      </div>
    </div>

    <!-- Filter Drawer -->
    <AppDrawer
      v-if="$slots.default"
      :open="drawerOpen"
      :title="drawerTitle || $t('common.filtersTitle')"
      :subtitle="drawerSubtitle || $t('common.filtersSubtitle')"
      size="md"
      @close="drawerOpen = false"
    >
      <div class="space-y-5">
        <slot />
      </div>

      <template #footer>
        <div class="flex items-center justify-between gap-3">
          <button
            type="button"
            :disabled="!hasAnyFilter"
            class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--muted)] transition-colors enabled:cursor-pointer enabled:hover:border-[var(--crimson)]/40 enabled:hover:text-[var(--crimson)] disabled:cursor-not-allowed disabled:opacity-45"
            @click="handleReset"
          >
            <RotateCcw class="h-4 w-4" aria-hidden="true" />
            <span>{{ $t('common.resetFilters') }}</span>
          </button>

          <button
            type="button"
            class="inline-flex h-10 flex-1 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-xs transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
            @click="applyAndClose"
          >
            <Check class="h-4 w-4" aria-hidden="true" />
            <span>{{ $t('common.applyFilters') }}</span>
          </button>
        </div>
      </template>
    </AppDrawer>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Check, RotateCcw, Search, SlidersHorizontal } from 'lucide-vue-next';
import AppDrawer from './AppDrawer.vue';

const props = defineProps({
  search: {
    type: String,
    default: '',
  },
  searchPlaceholder: {
    type: String,
    default: '',
  },
  activeFiltersCount: {
    type: Number,
    default: 0,
  },
  drawerTitle: {
    type: String,
    default: '',
  },
  drawerSubtitle: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['update:search', 'search', 'reset', 'apply']);

const drawerOpen = ref(false);

const hasAnyFilter = computed(() => {
  return Boolean((props.search || '').trim()) || props.activeFiltersCount > 0;
});

function onSearchInput(event) {
  const val = event.target.value;
  emit('update:search', val);
  emit('search', val);
}

function handleReset() {
  emit('reset');
  drawerOpen.value = false;
}

function applyAndClose() {
  emit('apply');
  drawerOpen.value = false;
}
</script>
