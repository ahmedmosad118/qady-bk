<template>
  <div ref="containerRef" class="relative w-full min-w-0">
    <!-- Trigger Button -->
    <button
      ref="triggerRef"
      type="button"
      role="combobox"
      :aria-expanded="isOpen"
      aria-haspopup="listbox"
      :disabled="disabled"
      class="qady-select-trigger group flex h-10 w-full min-w-0 items-center justify-between gap-2 rounded-xl border px-3.5 text-start text-xs font-medium transition-all duration-150 sm:text-[13px]"
      :class="[
        isOpen
          ? 'border-slate-400/80 bg-[var(--card)] ring-2 ring-slate-900/5 dark:border-slate-500 dark:ring-white/10'
          : 'border-[var(--border)] bg-[var(--bg)] hover:border-slate-300 hover:bg-[var(--card)] dark:hover:border-slate-600',
        disabled ? 'cursor-not-allowed opacity-55' : 'cursor-pointer',
      ]"
      @click="toggleDropdown"
      @keydown="onTriggerKeyDown"
    >
      <span
        v-if="selectedOption"
        class="min-w-0 flex-1 truncate font-semibold text-[var(--text)]"
        :title="formatLabel(selectedOption.label)"
      >
        {{ formatLabel(selectedOption.label) }}
      </span>
      <span
        v-else
        class="min-w-0 flex-1 truncate font-normal text-[var(--muted)]"
      >
        {{ formatLabel(placeholder) || $t('common.searchPlaceholder') }}
      </span>

      <span class="flex shrink-0 items-center gap-1">
        <!-- Clear Selection Button -->
        <span
          v-if="clearable && selectedOption && !disabled"
          role="button"
          tabindex="-1"
          class="inline-flex h-5 w-5 items-center justify-center rounded-md text-[var(--muted)] transition-colors hover:bg-[#eef1f5] hover:text-[var(--text)] dark:hover:bg-white/10"
          :title="clearTitle"
          :aria-label="clearTitle"
          @click.stop="clearSelection"
        >
          <X class="h-3.5 w-3.5" aria-hidden="true" />
        </span>

        <!-- Chevron -->
        <ChevronDown
          class="h-4 w-4 shrink-0 text-[var(--muted)] transition-transform duration-200"
          :class="{ 'rotate-180 text-[var(--text)]': isOpen }"
          aria-hidden="true"
        />
      </span>
    </button>

    <!-- Teleported Floating Dropdown Menu -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-150 ease-out"
        :enter-from-class="actualPlacement === 'top' ? 'translate-y-1 scale-[0.98] opacity-0' : '-translate-y-1 scale-[0.98] opacity-0'"
        enter-to-class="translate-y-0 scale-100 opacity-100"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="translate-y-0 scale-100 opacity-100"
        :leave-to-class="actualPlacement === 'top' ? 'translate-y-1 scale-[0.98] opacity-0' : '-translate-y-1 scale-[0.98] opacity-0'"
      >
        <div
          v-if="isOpen"
          ref="dropdownRef"
          :dir="isRtl ? 'rtl' : 'ltr'"
          :style="dropdownCoords"
          class="fixed z-[1100] flex flex-col overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-[0_14px_34px_-10px_rgba(15,23,42,0.18),0_2px_8px_-2px_rgba(15,23,42,0.06)] dark:shadow-[0_20px_48px_-12px_rgba(0,0,0,0.72)]"
          role="listbox"
          @keydown="onPanelKeyDown"
        >
          <!-- Sticky Search Box inside Dropdown -->
          <div
            v-if="shouldShowSearch"
            class="border-b border-[var(--border)] bg-[var(--card)] p-2"
          >
            <div class="relative flex items-center">
              <Search
                class="pointer-events-none absolute start-2.5 h-3.5 w-3.5 transition-colors"
                :class="searchFocused ? 'text-[var(--text)]' : 'text-[var(--muted)]'"
                aria-hidden="true"
              />
              <input
                ref="searchInputRef"
                v-model="searchQuery"
                type="text"
                autocomplete="off"
                :placeholder="formatLabel(searchPlaceholder) || $t('common.search')"
                class="qady-select-search h-8.5 w-full rounded-lg border border-[var(--border)] bg-[var(--bg)] ps-8 pe-7 text-start text-xs font-medium text-[var(--text)] placeholder:text-[var(--muted)]/75 transition-colors focus:border-slate-400/80 focus:bg-[var(--card)] focus:ring-2 focus:ring-slate-900/5 dark:focus:border-slate-500 dark:focus:ring-white/10"
                @focus="searchFocused = true"
                @blur="searchFocused = false"
                @click.stop
              />
              <button
                v-if="searchQuery"
                type="button"
                tabindex="-1"
                class="qady-select-clear absolute end-1.5 inline-flex h-5 w-5 cursor-pointer items-center justify-center rounded-md text-[var(--muted)] transition-colors hover:bg-[#eef1f5] hover:text-[var(--text)] dark:hover:bg-white/10"
                @click.stop="clearSearchQuery"
              >
                <X class="h-3 w-3" aria-hidden="true" />
              </button>
            </div>
          </div>

          <!-- Quick Action / Add New Option inside Select -->
          <div
            v-if="actionLabel"
            class="border-b border-[var(--border)] bg-[var(--card)] p-1.5"
          >
            <button
              type="button"
              class="qady-select-action group flex w-full cursor-pointer items-center justify-between rounded-lg bg-[var(--crimson-soft)] px-2.5 py-1.5 text-xs font-bold text-[var(--crimson)] transition-colors hover:bg-[var(--crimson)]/15"
              @click.stop="triggerAction"
            >
              <span class="flex items-center gap-1.5">
                <Plus class="h-3.5 w-3.5 transition-transform group-hover:scale-110" aria-hidden="true" />
                <span>{{ formatLabel(actionLabel) }}</span>
              </span>
              <span class="rounded-md bg-[var(--card)] px-1.5 py-0.5 text-[10px] font-semibold text-[var(--crimson)] shadow-2xs">
                {{ $t('common.create') }}
              </span>
            </button>
          </div>

          <!-- Scrollable Options List -->
          <div
            ref="listRef"
            :style="{ maxHeight: `${maxListHeight}px` }"
            class="custom-scrollbar flex flex-col gap-0.5 overflow-y-auto overscroll-contain p-1.5"
            @scroll.passive="onListScroll"
            @mouseleave="highlightedIndex = -1"
          >
            <!-- Scroll-Paginated Items -->
            <button
              v-for="(opt, idx) in visibleOptions"
              :key="opt.value"
              :ref="(el) => setOptionEl(idx, el)"
              type="button"
              role="option"
              :aria-selected="isSelected(opt.value)"
              class="qady-select-item flex min-h-[34px] w-full shrink-0 cursor-pointer items-center justify-between gap-2 rounded-lg px-2.5 py-1.5 text-start text-xs leading-5 transition-colors sm:text-[13px]"
              :class="[
                isSelected(opt.value)
                  ? 'bg-[var(--crimson)]/[0.05] font-bold text-[var(--text)] hover:bg-[var(--crimson)]/[0.08]'
                  : highlightedIndex === idx
                    ? 'bg-[#eef2f6] font-medium text-[var(--text)] dark:bg-white/[0.08]'
                    : 'font-medium text-[var(--text)] hover:bg-[#eef2f6] dark:hover:bg-white/[0.08]',
              ]"
              @mouseenter="highlightedIndex = idx"
              @click="selectOption(opt.value)"
            >
              <span class="min-w-0 flex-1 truncate">{{ formatLabel(opt.label) }}</span>
              <Check
                v-if="isSelected(opt.value)"
                class="h-3.5 w-3.5 shrink-0 text-[var(--crimson)]/80"
                aria-hidden="true"
              />
            </button>

            <!-- Empty State -->
            <div
              v-if="filteredOptions.length === 0"
              class="flex flex-col items-center justify-center gap-1.5 px-3 py-6 text-center"
            >
              <SearchX class="h-5 w-5 text-[var(--muted)]/60" aria-hidden="true" />
              <p class="text-xs font-medium text-[var(--muted)]">{{ $t('common.noData') }}</p>
            </div>

            <!-- Scroll Pagination Loader / Sentinel -->
            <div
              v-if="hasMore"
              ref="sentinelRef"
              class="flex shrink-0 items-center justify-center gap-1.5 py-2 text-[11px] font-semibold text-[var(--muted)]"
            >
              <Loader2 class="h-3.5 w-3.5 animate-spin text-[var(--muted)]" aria-hidden="true" />
              <span>{{ loadingMoreLabel }}</span>
            </div>
          </div>

          <!-- Subtle Scroll Pagination Status Footer -->
          <div
            v-if="showScrollFooter"
            class="flex items-center justify-between border-t border-[var(--border)] bg-[var(--bg)]/60 px-3 py-1.5 text-[10px] font-semibold text-[var(--muted)]"
          >
            <span>{{ countSummaryLabel }}</span>
            <span v-if="hasMore">{{ scrollHintLabel }}</span>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import {
  ref,
  computed,
  watch,
  nextTick,
  onMounted,
  onBeforeUnmount,
} from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ChevronDown,
  Search,
  SearchX,
  Check,
  X,
  Plus,
  Loader2,
} from 'lucide-vue-next';

const props = defineProps({
  modelValue: {
    type: [String, Number, Boolean, null],
    default: null,
  },
  options: {
    type: Array,
    default: () => [],
  },
  placeholder: {
    type: String,
    default: '',
  },
  searchPlaceholder: {
    type: String,
    default: '',
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  clearable: {
    type: Boolean,
    default: true,
  },
  allowEmpty: {
    type: Boolean,
    default: false,
  },
  showSearchAlways: {
    type: Boolean,
    default: true,
  },
  actionLabel: {
    type: String,
    default: '',
  },
  paginate: {
    type: [Boolean, Number],
    default: false,
  },
  placement: {
    type: String,
    default: 'auto', // 'bottom', 'top', or 'auto'
  },
  minDropdownWidth: {
    type: Number,
    default: 220,
  },
});

const emit = defineEmits(['update:modelValue', 'change', 'action']);

const { t, te, locale } = useI18n();
const isRtl = computed(() => locale.value === 'ar');

const containerRef = ref(null);
const triggerRef = ref(null);
const dropdownRef = ref(null);
const searchInputRef = ref(null);
const listRef = ref(null);
const sentinelRef = ref(null);

const isOpen = ref(false);
const searchQuery = ref('');
const searchFocused = ref(false);
const highlightedIndex = ref(-1);
const optionRefs = new Map();

const actualPlacement = ref(props.placement === 'top' ? 'top' : 'bottom');
const dropdownCoords = ref({ top: '0px', bottom: 'auto', left: '0px', width: '240px' });
const maxListHeight = ref(240);

let intersectionObserver = null;

const isEmptyValue = (val) => val === null || val === undefined || val === '';

const formatLabel = (key) => {
  if (key === null || key === undefined || key === '') return '';
  const str = String(key);
  return te(str) ? t(str) : str;
};

const normalizeSearchText = (str) => {
  return String(str ?? '')
    .toLowerCase()
    .normalize('NFKC')
    .replace(/[\u064B-\u065F\u0670]/g, '')
    .replace(/[\u0623\u0625\u0622\u0671]/g, '\u0627')
    .replace(/\u0629/g, '\u0647')
    .replace(/\u0649/g, '\u064a')
    .trim();
};

const normalizeOpt = (opt) => {
  if (typeof opt === 'object' && opt !== null) {
    const val = opt.value !== undefined ? opt.value : (opt.id !== undefined ? opt.id : opt.key);
    const lbl = opt.label !== undefined ? opt.label : (opt.name || opt.title || opt.display_name || String(val ?? ''));
    return { ...opt, value: val, label: lbl };
  }
  return { value: opt, label: String(opt) };
};

const rawNormalizedOptions = computed(() => (props.options || []).map(normalizeOpt));

const normalizedOptions = computed(() => {
  if (props.allowEmpty) {
    return rawNormalizedOptions.value.filter((opt) => !isEmptyValue(opt.value));
  }
  return rawNormalizedOptions.value;
});

const selectedOption = computed(() => {
  if (isEmptyValue(props.modelValue)) {
    return null;
  }
  return normalizedOptions.value.find((opt) => String(opt.value) === String(props.modelValue)) || null;
});

const isSelected = (val) => {
  if (isEmptyValue(props.modelValue)) return false;
  return String(val) === String(props.modelValue);
};

const shouldShowSearch = computed(() => {
  return normalizedOptions.value.length > 5 || props.showSearchAlways;
});

const filteredOptions = computed(() => {
  const q = normalizeSearchText(searchQuery.value);
  if (!q) {
    return normalizedOptions.value;
  }
  return normalizedOptions.value.filter((opt) => {
    const label = normalizeSearchText(formatLabel(opt.label));
    const val = normalizeSearchText(opt.value);
    const barcode = opt.barcode ? normalizeSearchText(opt.barcode) : '';
    return label.includes(q) || val.includes(q) || barcode.includes(q);
  });
});

// Scroll Pagination chunk size: honors numeric :paginate, otherwise 15 (if paginate=true) or 20
const scrollChunkSize = computed(() => {
  if (typeof props.paginate === 'number' && props.paginate > 0) {
    return props.paginate;
  }
  return props.paginate ? 15 : 20;
});

const visibleLimit = ref(scrollChunkSize.value);

const visibleOptions = computed(() => {
  return filteredOptions.value.slice(0, visibleLimit.value);
});

const hasMore = computed(() => {
  return visibleLimit.value < filteredOptions.value.length;
});

const showScrollFooter = computed(() => {
  return filteredOptions.value.length > scrollChunkSize.value;
});

const clearTitle = computed(() => t('common.clear'));
const loadingMoreLabel = computed(() => t('common.loadingMore'));
const scrollHintLabel = computed(() => t('common.scrollForMore'));
const countSummaryLabel = computed(() => {
  return t('common.showingCountOf', {
    visible: visibleOptions.value.length,
    total: filteredOptions.value.length,
  });
});

watch(searchQuery, () => {
  visibleLimit.value = scrollChunkSize.value;
  highlightedIndex.value = -1;
  if (listRef.value) {
    listRef.value.scrollTop = 0;
  }
});

watch(
  () => props.options.length,
  () => {
    if (!isOpen.value) {
      visibleLimit.value = scrollChunkSize.value;
    }
  }
);

const loadMore = () => {
  if (!hasMore.value) return;
  visibleLimit.value = Math.min(
    filteredOptions.value.length,
    visibleLimit.value + scrollChunkSize.value
  );
};

const onListScroll = (e) => {
  const el = e.target;
  if (!el) return;
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 48) {
    loadMore();
  }
};

const teardownIntersectionObserver = () => {
  if (intersectionObserver) {
    intersectionObserver.disconnect();
    intersectionObserver = null;
  }
};

const setupIntersectionObserver = () => {
  teardownIntersectionObserver();
  if (!listRef.value || !sentinelRef.value || typeof IntersectionObserver === 'undefined') return;

  intersectionObserver = new IntersectionObserver(
    (entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        loadMore();
      }
    },
    {
      root: listRef.value,
      rootMargin: '48px 0px',
      threshold: 0,
    }
  );
  intersectionObserver.observe(sentinelRef.value);
};

watch(
  [() => isOpen.value, () => hasMore.value, () => visibleOptions.value.length],
  async ([open, more]) => {
    if (open && more) {
      await nextTick();
      setupIntersectionObserver();
    } else {
      teardownIntersectionObserver();
    }
  }
);

const updatePosition = () => {
  if (!isOpen.value || !containerRef.value) return;

  const rect = containerRef.value.getBoundingClientRect();
  if (rect.width === 0 && rect.height === 0) {
    closeDropdown();
    return;
  }

  const margin = 12;
  const gap = 4;
  const maxAllowedWidth = Math.max(180, window.innerWidth - margin * 2);
  const width = Math.min(Math.max(rect.width, props.minDropdownWidth || 220), maxAllowedWidth);

  let left = isRtl.value ? rect.right - width : rect.left;
  left = Math.max(margin, Math.min(left, window.innerWidth - width - margin));

  const spaceBelow = window.innerHeight - rect.bottom - margin;
  const spaceAbove = rect.top - margin;

  let place = 'bottom';
  if (props.placement === 'top') {
    place = spaceAbove < 160 && spaceBelow > spaceAbove ? 'bottom' : 'top';
  } else if (props.placement === 'bottom') {
    place = spaceBelow < 160 && spaceAbove > spaceBelow ? 'top' : 'bottom';
  } else {
    place = spaceBelow < 240 && spaceAbove > spaceBelow ? 'top' : 'bottom';
  }
  actualPlacement.value = place;

  const availableVertical = place === 'top' ? spaceAbove : spaceBelow;
  const overhead = (shouldShowSearch.value ? 58 : 0) + (props.actionLabel ? 52 : 0) + (showScrollFooter.value ? 30 : 0);
  maxListHeight.value = Math.max(120, Math.min(240, availableVertical - overhead - gap));

  if (place === 'top') {
    dropdownCoords.value = {
      top: 'auto',
      bottom: `${Math.max(margin, window.innerHeight - rect.top + gap)}px`,
      left: `${Math.round(left)}px`,
      width: `${Math.round(width)}px`,
    };
  } else {
    dropdownCoords.value = {
      top: `${Math.round(rect.bottom + gap)}px`,
      bottom: 'auto',
      left: `${Math.round(left)}px`,
      width: `${Math.round(width)}px`,
    };
  }
};

const attachPositionListeners = () => {
  window.addEventListener('scroll', updatePosition, true);
  window.addEventListener('resize', updatePosition);
};

const detachPositionListeners = () => {
  window.removeEventListener('scroll', updatePosition, true);
  window.removeEventListener('resize', updatePosition);
};

const openDropdown = async () => {
  if (props.disabled || isOpen.value) return;

  searchQuery.value = '';
  visibleLimit.value = scrollChunkSize.value;
  highlightedIndex.value = -1;
  isOpen.value = true;

  await nextTick();
  updatePosition();
  attachPositionListeners();

  nextTick(() => {
    searchInputRef.value?.focus({ preventScroll: true });
  });
};

const closeDropdown = () => {
  if (!isOpen.value) return;
  isOpen.value = false;
  searchQuery.value = '';
  highlightedIndex.value = -1;
  detachPositionListeners();
  teardownIntersectionObserver();
};

const toggleDropdown = () => {
  if (props.disabled) return;
  if (isOpen.value) {
    closeDropdown();
  } else {
    openDropdown();
  }
};

const selectOption = (val) => {
  emit('update:modelValue', val);
  emit('change', val);
  closeDropdown();
};

const clearSelection = () => {
  emit('update:modelValue', null);
  emit('change', null);
};

const clearSearchQuery = () => {
  searchQuery.value = '';
  nextTick(() => {
    searchInputRef.value?.focus({ preventScroll: true });
  });
};

const triggerAction = () => {
  closeDropdown();
  emit('action');
};

const setOptionEl = (idx, el) => {
  if (el) {
    optionRefs.set(idx, el);
  } else {
    optionRefs.delete(idx);
  }
};

const scrollHighlightedIntoView = () => {
  const el = optionRefs.get(highlightedIndex.value);
  if (el && typeof el.scrollIntoView === 'function') {
    el.scrollIntoView({ block: 'nearest' });
  }
};

const moveHighlight = (delta) => {
  const len = visibleOptions.value.length;
  if (len === 0) return;

  if (highlightedIndex.value < 0) {
    highlightedIndex.value = delta > 0 ? 0 : len - 1;
  } else {
    const next = highlightedIndex.value + delta;
    if (next >= len && hasMore.value) {
      loadMore();
    }
    highlightedIndex.value = Math.max(0, Math.min(visibleOptions.value.length - 1, next));
  }
  nextTick(scrollHighlightedIntoView);
};

const onTriggerKeyDown = (e) => {
  if (props.disabled) return;
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
    e.preventDefault();
    if (!isOpen.value) {
      openDropdown();
    } else {
      moveHighlight(e.key === 'ArrowDown' ? 1 : -1);
    }
  } else if (e.key === 'Enter' || e.key === ' ') {
    if (!isOpen.value) {
      e.preventDefault();
      openDropdown();
    }
  } else if (e.key === 'Escape') {
    closeDropdown();
  }
};

const onPanelKeyDown = (e) => {
  if (e.key === 'ArrowDown') {
    e.preventDefault();
    moveHighlight(1);
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    moveHighlight(-1);
  } else if (e.key === 'Enter') {
    const opt = visibleOptions.value[highlightedIndex.value];
    if (opt) {
      e.preventDefault();
      selectOption(opt.value);
    }
  } else if (e.key === 'Escape') {
    e.preventDefault();
    closeDropdown();
    triggerRef.value?.focus({ preventScroll: true });
  } else if (e.key === 'Tab') {
    closeDropdown();
  }
};

const handlePointerDownOutside = (e) => {
  if (!isOpen.value) return;
  const inContainer = containerRef.value && containerRef.value.contains(e.target);
  const inDropdown = dropdownRef.value && dropdownRef.value.contains(e.target);
  if (!inContainer && !inDropdown) {
    closeDropdown();
  }
};

const handleGlobalKeyDown = (e) => {
  if (e.key === 'Escape' && isOpen.value) {
    closeDropdown();
  }
};

onMounted(() => {
  document.addEventListener('pointerdown', handlePointerDownOutside, true);
  document.addEventListener('keydown', handleGlobalKeyDown);
});

onBeforeUnmount(() => {
  document.removeEventListener('pointerdown', handlePointerDownOutside, true);
  document.removeEventListener('keydown', handleGlobalKeyDown);
  detachPositionListeners();
  teardownIntersectionObserver();
});
</script>

<style scoped>
.qady-select-trigger:focus,
.qady-select-trigger:focus-visible,
.qady-select-search:focus,
.qady-select-search:focus-visible,
.qady-select-item:focus,
.qady-select-item:focus-visible,
.qady-select-action:focus,
.qady-select-action:focus-visible,
.qady-select-clear:focus,
.qady-select-clear:focus-visible {
  outline: none;
}
</style>
