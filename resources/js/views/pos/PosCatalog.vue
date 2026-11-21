<template>
  <section class="flex flex-col min-h-0 p-4 overflow-hidden">
    <!-- Search Bar with Scanner Support -->
    <div class="relative flex items-center gap-2.5 h-[54px] px-4 rounded-2xl border-2 border-[var(--border)] bg-[var(--card)] focus-within:border-[var(--crimson)] transition">
      <Barcode class="w-6 h-6 text-[var(--muted)] flex-none" />
      <input
        ref="searchInputRef"
        v-model="posStore.searchQuery"
        type="text"
        :placeholder="$t('pos.scanOrSearchPlaceholder')"
        autocomplete="off"
        class="flex-1 bg-transparent border-0 outline-none text-[var(--text)] font-bold text-base placeholder:text-[var(--muted)]/70"
        @keydown.enter="handleBarcodeOrEnter"
        @input="onSearchInput"
      />
      <button
        v-if="posStore.searchQuery"
        type="button"
        class="p-1 rounded-lg text-[var(--muted)] hover:bg-[var(--bar)] transition"
        :title="$t('pos.clearSearch')"
        @click="clearSearch"
      >
        <X class="w-4 h-4" />
      </button>
      <kbd class="hidden sm:inline font-mono text-xs font-bold px-2 py-0.5 rounded-md border border-[var(--border)] text-[var(--muted)] bg-[var(--bar)]">
        F2
      </kbd>
    </div>

    <!-- Category Filters -->
    <div class="flex items-center gap-2 my-3 overflow-x-auto pb-1 scrollbar-none flex-nowrap">
      <button
        type="button"
        :class="[
          'px-4 py-1.5 rounded-full text-xs font-bold border transition whitespace-nowrap',
          posStore.selectedCategoryId === null
            ? 'bg-[var(--text)] text-[var(--card)] border-[var(--text)] shadow-sm'
            : 'bg-[var(--card)] text-[var(--text)] border-[var(--border)] hover:border-[var(--muted)]'
        ]"
        @click="onSelectCategory(null)"
      >
        {{ $t('common.all') }}
      </button>
      <button
        v-for="cat in posStore.categories"
        :key="cat.id"
        type="button"
        :class="[
          'px-4 py-1.5 rounded-full text-xs font-bold border transition whitespace-nowrap',
          posStore.selectedCategoryId === cat.id
            ? 'bg-[var(--text)] text-[var(--card)] border-[var(--text)] shadow-sm'
            : 'bg-[var(--card)] text-[var(--text)] border-[var(--border)] hover:border-[var(--muted)]'
        ]"
        @click="onSelectCategory(cat.id)"
      >
        {{ cat.name }}
      </button>
    </div>

    <!-- Products Grid (32 items per page, server-side paginated) -->
    <div
      ref="scrollContainerRef"
      class="flex-1 overflow-y-auto pe-1"
    >
      <!-- Initial / Search Loading State -->
      <div v-if="posStore.loadingProducts && posStore.products.length === 0" class="py-20 text-center text-[var(--muted)]">
        <Loader2 class="w-8 h-8 animate-spin mx-auto mb-2 text-[var(--crimson)]" />
        <p class="text-xs font-bold">{{ $t('pos.loadingBooks') }}</p>
      </div>

      <div
        v-else-if="posStore.filteredProducts.length > 0"
        class="grid grid-cols-[repeat(auto-fill,minmax(168px,1fr))] gap-3.5 pb-4 items-start transition-opacity duration-150"
        :class="{ 'opacity-55 pointer-events-none': posStore.loadingProducts }"
      >
        <button
          v-for="prod in posStore.filteredProducts"
          :key="prod.id"
          type="button"
          :disabled="!posStore.isOpenShift || Number(prod.stock_quantity || 0) < 1"
          class="group rounded-2xl border border-[var(--border)] bg-[var(--card)] p-2.5 text-start transition flex flex-col gap-2 hover:border-[var(--crimson)] hover:-translate-y-0.5 hover:shadow-md disabled:opacity-45 disabled:cursor-not-allowed disabled:transform-none select-none cursor-pointer"
          @click="posStore.addToCart(prod)"
        >
          <!-- Elegant Book Cover Mockup matching reference design -->
          <div
            class="aspect-[4/3] rounded-[10px_4px_4px_10px] p-2 text-center text-white font-extrabold text-xs leading-snug flex items-center justify-center relative overflow-hidden shadow-inner"
            :style="{ background: getCoverBackground(prod) }"
          >
            <!-- Spine shadow simulation on left -->
            <div class="absolute inset-y-0 left-0 w-2.5 bg-black/35 pointer-events-none"></div>
            <!-- Gold accent band -->
            <span class="w-full py-1.5 px-1 border-y border-[#D7B26A] text-[11px] line-clamp-2 drop-shadow-sm">
              {{ prod.name }}
            </span>
          </div>

          <!-- Title -->
          <h3 class="text-xs font-bold text-[var(--text)] line-clamp-2 min-h-[34px] leading-tight group-hover:text-[var(--crimson)] transition-colors">
            {{ prod.name }}
          </h3>

          <!-- Price & Stock Footer -->
          <div class="flex items-center justify-between text-xs pt-1 border-t border-[var(--border)]/60">
            <b class="text-sm font-black text-[var(--text)]">
              {{ formatMoney(prod.selling_price) }} <small class="text-[10px] font-normal text-[var(--muted)]">{{ $t('common.currency') }}</small>
            </b>
            <span
              :class="[
                'text-[11px]',
                Number(prod.stock_quantity || 0) < 1
                  ? 'text-[var(--crimson)] font-black'
                  : Number(prod.stock_quantity || 0) < 5
                    ? 'text-amber-600 font-bold'
                    : 'text-[var(--muted)]'
              ]"
            >
              {{ Number(prod.stock_quantity || 0) < 1 ? $t('pos.outOfStock') : $t('pos.remainingStock', { count: prod.stock_quantity }) }}
            </span>
          </div>
        </button>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-20 text-[var(--muted)]">
        <BookOpen class="w-12 h-12 mx-auto mb-3 text-[var(--muted)]/50" />
        <h4 class="font-bold text-base text-[var(--text)] mb-1">{{ $t('pos.noMatchingBooks') }}</h4>
        <p class="text-xs">{{ $t('pos.noMatchingBooksHint') }}</p>
      </div>
    </div>

    <!-- Pagination Bar (32 per page) -->
    <div
      v-if="posStore.productsMeta.total > 0"
      class="flex-none mt-2.5 pt-2.5 border-t border-[var(--border)] flex flex-wrap items-center justify-between gap-2 text-xs"
    >
      <div class="flex items-center gap-2 font-semibold text-[var(--muted)]">
        <Loader2 v-if="posStore.loadingProducts" class="w-3.5 h-3.5 animate-spin text-[var(--crimson)]" aria-hidden="true" />
        <span>
          {{ $t('pos.catalogPaginationSummary', {
            from: posStore.productsMeta.from,
            to: posStore.productsMeta.to,
            total: posStore.productsMeta.total,
            page: posStore.productsMeta.currentPage,
            lastPage: posStore.productsMeta.lastPage,
          }) }}
        </span>
      </div>

      <div v-if="posStore.productsMeta.lastPage > 1" class="flex items-center gap-1">
        <button
          type="button"
          :disabled="posStore.loadingProducts || posStore.productsMeta.currentPage <= 1"
          class="inline-flex h-8 items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 font-bold text-[var(--text)] transition hover:border-[var(--crimson)]/50 disabled:cursor-not-allowed disabled:opacity-40 cursor-pointer"
          @click="changePage(posStore.productsMeta.currentPage - 1)"
        >
          <component :is="prevIcon" class="h-3.5 w-3.5" aria-hidden="true" />
          <span>{{ $t('common.previous') }}</span>
        </button>

        <template v-for="(item, idx) in paginationItems" :key="`p-${idx}-${item}`">
          <span
            v-if="item === '...'"
            class="px-1.5 text-[var(--muted)] select-none"
          >…</span>
          <button
            v-else
            type="button"
            :disabled="posStore.loadingProducts"
            :class="[
              'inline-flex h-8 min-w-8 items-center justify-center rounded-lg border px-2 font-mono font-bold transition cursor-pointer disabled:cursor-not-allowed',
              item === posStore.productsMeta.currentPage
                ? 'border-[var(--crimson)] bg-[var(--crimson)] text-white shadow-xs'
                : 'border-[var(--border)] bg-[var(--card)] text-[var(--text)] hover:border-[var(--crimson)]/50'
            ]"
            @click="changePage(item)"
          >
            {{ item }}
          </button>
        </template>

        <button
          type="button"
          :disabled="posStore.loadingProducts || posStore.productsMeta.currentPage >= posStore.productsMeta.lastPage"
          class="inline-flex h-8 items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 font-bold text-[var(--text)] transition hover:border-[var(--crimson)]/50 disabled:cursor-not-allowed disabled:opacity-40 cursor-pointer"
          @click="changePage(posStore.productsMeta.currentPage + 1)"
        >
          <span>{{ $t('common.next') }}</span>
          <component :is="nextIcon" class="h-3.5 w-3.5" aria-hidden="true" />
        </button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Barcode, X, BookOpen, Loader2, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { usePosStore } from '../../stores/pos';

const { locale } = useI18n();
const posStore = usePosStore();
const searchInputRef = ref(null);
const scrollContainerRef = ref(null);

const isRtl = computed(() => locale.value === 'ar');
const prevIcon = computed(() => (isRtl.value ? ChevronRight : ChevronLeft));
const nextIcon = computed(() => (isRtl.value ? ChevronLeft : ChevronRight));

const paginationItems = computed(() => {
  const current = posStore.productsMeta.currentPage || 1;
  const last = posStore.productsMeta.lastPage || 1;

  if (last <= 7) {
    return Array.from({ length: last }, (_, i) => i + 1);
  }

  const pages = [1];
  const start = Math.max(2, current - 1);
  const end = Math.min(last - 1, current + 1);

  if (start > 2) pages.push('...');
  for (let p = start; p <= end; p++) {
    pages.push(p);
  }
  if (end < last - 1) pages.push('...');
  pages.push(last);

  return pages;
});

// Curated Al-Qadi book mockup palette
const bookColors = [
  '#A3162B', // Al-Qadi brand crimson
  '#16181D', // Dark ink
  '#1F2A44', // Legal Navy
  '#1F4B3F', // Classic Forest Green
  '#5A1020', // Deep Maroon
  '#74101F', // Wine Red
];

function getCoverBackground(product) {
  // Deterministic color based on product ID
  const idx = Math.abs(Number(product.id || 0)) % bookColors.length;
  const col = bookColors[idx];
  return `linear-gradient(to left, rgba(0,0,0,0.3) 0 6%, transparent 6%), ${col}`;
}

function scrollToTop() {
  if (scrollContainerRef.value) {
    scrollContainerRef.value.scrollTop = 0;
  }
}

let searchDebounceTimer = null;
function onSearchInput() {
  clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(() => {
    scrollToTop();
    if (posStore.isOnline) {
      posStore.reloadProducts({
        query: posStore.searchQuery,
        categoryId: posStore.selectedCategoryId,
        page: 1,
      });
    }
  }, 300);
}

function clearSearch() {
  clearTimeout(searchDebounceTimer);
  posStore.searchQuery = '';
  scrollToTop();
  if (posStore.isOnline) {
    posStore.reloadProducts({
      query: '',
      categoryId: posStore.selectedCategoryId,
      page: 1,
    });
  }
}

function onSelectCategory(catId) {
  posStore.selectedCategoryId = catId;
  scrollToTop();
  if (posStore.isOnline) {
    posStore.reloadProducts({
      query: posStore.searchQuery,
      categoryId: catId,
      page: 1,
    });
  }
}

async function changePage(targetPage) {
  if (!posStore.isOnline) return;
  scrollToTop();
  await posStore.goToProductsPage(targetPage);
}

async function handleBarcodeOrEnter() {
  const query = posStore.searchQuery.trim();
  if (!query) return;

  // 1. Try exact barcode match first
  const exactBarcodeProduct = posStore.products.find((p) => p.barcode === query);
  if (exactBarcodeProduct) {
    posStore.addToCart(exactBarcodeProduct);
    clearSearch();
    return;
  }

  // 2. If current filtered list has exactly 1 match on Enter, add it directly
  if (posStore.filteredProducts.length === 1 && posStore.productsMeta.total === 1) {
    posStore.addToCart(posStore.filteredProducts[0]);
    clearSearch();
    return;
  }

  // 3. Otherwise try server lookup
  const res = await posStore.lookupBarcode(query);
  if (res) {
    clearSearch();
  }
}

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

defineExpose({
  focusSearch: () => {
    searchInputRef.value?.focus();
    searchInputRef.value?.select();
  },
});
</script>
