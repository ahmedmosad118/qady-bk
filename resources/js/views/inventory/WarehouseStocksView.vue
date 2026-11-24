<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader
      :title="$t('warehouseStocks.title')"
      :subtitle="$t('warehouseStocks.subtitle')"
    >
      <template #actions>
        <router-link
          to="/inventory"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <FileText class="h-[18px] w-[18px] text-[var(--muted)]" />
          <span>{{ $t('nav.allInventory') }}</span>
        </router-link>
      </template>
    </PageHeader>

    <!-- Main Stock Table Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('warehouseStocks.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedSearch"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Warehouse Selector -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('warehouses.title') }}</label>
            <SearchableSelect
              v-model="filters.warehouse_id"
              :fetch-options="fetchWarehouseOptions"
              :paginate="10"
              :placeholder="$t('warehouseStocks.allWarehouses')"
              :search-placeholder="$t('warehouseStocks.searchWarehouses')"
              allow-empty
              @change="filters.page = 1; loadStocks()"
            />
          </div>

          <!-- Stock Availability Filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('warehouseStocks.availability') }}</label>
            <SearchableSelect
              v-model="filters.has_stock"
              :options="stockStatusOptions"
              :placeholder="$t('warehouseStocks.availability')"
              allow-empty
              @change="filters.page = 1; loadStocks()"
            />
          </div>
        </div>
      </FilterToolbar>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="w-12 px-4 py-3.5 text-center">#</th>
              <th class="px-4 py-3.5">{{ $t('warehouseStocks.productName') }}</th>
              <th class="px-4 py-3.5">{{ $t('warehouseStocks.barcode') }}</th>
              <th class="px-4 py-3.5">{{ $t('warehouseStocks.warehouse') }}</th>
              <th class="px-4 py-3.5 text-center">{{ $t('warehouseStocks.currentStock') }}</th>
              <th class="px-4 py-3.5 text-center">{{ $t('warehouseStocks.lastUpdated') }}</th>
              <th class="px-4 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading -->
            <tr v-if="loading && stocks.length === 0">
              <td colspan="7" class="px-4 py-12 text-center text-sm text-[var(--muted)]">
                <Loader2 class="mx-auto h-6 w-6 animate-spin text-[var(--muted)]" />
                <span class="mt-2 block">{{ $t('warehouseStocks.loading') }}</span>
              </td>
            </tr>

            <!-- Empty -->
            <tr v-else-if="stocks.length === 0">
              <td colspan="7" class="px-4 py-12 text-center">
                <Boxes class="mx-auto h-10 w-10 text-[var(--muted)]/50" />
                <h3 class="mt-3 text-base font-bold text-[var(--text)]">{{ $t('warehouseStocks.noStocks') }}</h3>
                <p class="mt-1 text-xs text-[var(--muted)]">{{ $t('warehouseStocks.noStocksHint') }}</p>
              </td>
            </tr>

            <!-- Rows -->
            <tr
              v-for="(stock, idx) in stocks"
              :key="stock.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-4 py-3.5 text-center text-xs font-bold text-[var(--muted)]">
                {{ (meta.current_page - 1) * meta.per_page + idx + 1 }}
              </td>

              <!-- Product Name -->
              <td class="px-4 py-3.5">
                <div class="font-extrabold text-[var(--text)]">
                  {{ stock.product?.name || '—' }}
                </div>
                <div class="mt-0.5 flex items-center gap-2 text-xs text-[var(--muted)]">
                  <span v-if="stock.product?.category?.name" class="rounded bg-[var(--bg)] px-1.5 py-0.5 text-[11px] font-semibold">
                    {{ stock.product.category.name }}
                  </span>
                  <span v-if="stock.product?.author">{{ stock.product.author }}</span>
                </div>
              </td>

              <!-- Barcode -->
              <td class="px-4 py-3.5">
                <span class="rounded-md bg-[var(--bg)] px-2.5 py-1 font-mono text-xs font-bold text-[var(--text)]">
                  {{ stock.product?.barcode || '—' }}
                </span>
              </td>

              <!-- Warehouse -->
              <td class="px-4 py-3.5">
                <div class="flex items-center gap-1.5">
                  <Building2 class="h-4 w-4 text-[var(--muted)]" />
                  <span class="font-bold text-[var(--text)]">{{ stock.warehouse?.name || '—' }}</span>
                  <span
                    v-if="stock.warehouse?.is_default"
                    class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-bold text-amber-600 dark:text-amber-400"
                  >
                    {{ $t('warehouses.mainShort') }}
                  </span>
                </div>
              </td>

              <!-- Current Stock Balance -->
              <td class="px-4 py-3.5 text-center">
                <span
                  class="inline-flex min-w-16 items-center justify-center rounded-lg px-3 py-1 text-sm font-black shadow-xs"
                  :class="Number(stock.quantity) > 0 ? 'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20 dark:text-emerald-400' : 'bg-zinc-500/10 text-zinc-500 border border-zinc-500/20'"
                >
                  {{ Number(stock.quantity).toLocaleString() }}
                </span>
              </td>

              <!-- Last Updated -->
              <td class="px-4 py-3.5 text-center text-xs text-[var(--muted)]">
                {{ formatDateTime(stock.updated_at) }}
              </td>

              <!-- Actions -->
              <td class="px-4 py-3.5 text-end">
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)] cursor-pointer"
                  :title="$t('warehouseStocks.stockCardTitle')"
                  @click="openStockCard(stock)"
                >
                  <Receipt class="h-3.5 w-3.5 text-[var(--muted)]" />
                  <span>{{ $t('warehouseStocks.stockCard') }}</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <TablePagination
        :meta="meta"
        :items-count="stocks.length"
        :per-page="meta.per_page"
        :loading="loading"
        @update:per-page="meta.per_page = $event"
        @change-per-page="fetchStocks(1)"
        @change-page="changePage"
      />
    </div>

    <!-- Stock Card Drawer (Side Drawer) -->
    <AppDrawer
      :open="stockCardOpen"
      width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl xl:max-w-5xl"
      side="end"
      @close="stockCardOpen = false"
    >
      <template #header>
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-3">
            <div
              class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
            >
              <Receipt class="h-6 w-6" />
            </div>

            <div>
              <div class="flex flex-wrap items-center gap-2.5">
                <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl">
                  {{ activeStockRow?.product?.name }}
                </h2>
                <span v-if="activeStockRow?.product?.barcode" class="rounded-md bg-[var(--bg)] px-2 py-0.5 font-mono text-xs font-bold text-[var(--muted)]">
                  {{ activeStockRow.product.barcode }}
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-3 py-0.5 text-xs font-bold text-emerald-600 border border-emerald-500/20">
                  {{ $t('warehouseStocks.currentBalanceLabel') }} {{ Number(stockCardData?.current_stock ?? activeStockRow?.quantity ?? 0).toLocaleString() }} {{ $t('warehouseStocks.unitLabel') }}
                </span>
              </div>
              <p class="mt-1 text-xs text-[var(--muted)]">
                {{ $t('warehouseStocks.stockCardIn') }} <span class="font-bold text-[var(--text)]">{{ activeStockRow?.warehouse?.name }}</span>
              </p>
            </div>
          </div>

          <div class="flex items-center gap-1">
            <button
              type="button"
              class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
              :title="$t('warehouseStocks.printStockCard')"
              @click="printCard"
            >
              <Printer class="h-5 w-5" />
            </button>
            <button
              type="button"
              class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
              @click="stockCardOpen = false"
            >
              <X class="h-5 w-5" />
            </button>
          </div>
        </div>
      </template>

      <!-- Sticky Top Filter Bar in Drawer -->
      <template #actions>
        <div class="flex flex-wrap items-center justify-between gap-3 text-xs">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-[var(--muted)]">{{ $t('warehouseStocks.filterMovements') }}</span>
            <!-- Direction -->
            <select
              v-model="cardFilters.direction"
              class="h-8 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2 text-xs font-bold text-[var(--text)] outline-none"
              @change="fetchStockCard"
            >
              <option value="">{{ $t('warehouseStocks.allDirections') }}</option>
              <option value="inbound">{{ $t('warehouseStocks.inboundOnly') }}</option>
              <option value="outbound">{{ $t('warehouseStocks.outboundOnly') }}</option>
            </select>

            <!-- Date From -->
            <input
              v-model="cardFilters.date_from"
              type="date"
              class="h-8 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2 text-xs text-[var(--text)] outline-none"
              @change="fetchStockCard"
            />
            <span class="text-[var(--muted)]">{{ $t('warehouseStocks.to') }}</span>
            <!-- Date To -->
            <input
              v-model="cardFilters.date_to"
              type="date"
              class="h-8 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2 text-xs text-[var(--text)] outline-none"
              @change="fetchStockCard"
            />
          </div>

          <div>
            <span class="font-extrabold text-[var(--muted)]">
              {{ $t('warehouseStocks.totalMovements') }} <span class="text-[var(--text)]">{{ stockCardData?.movements?.length || 0 }}</span>
            </span>
          </div>
        </div>
      </template>

      <!-- Drawer Content: Chronological Ledger -->
      <div class="space-y-4 text-sm">
        <!-- Error Alert -->
        <div
          v-if="cardError"
          class="flex items-center justify-between rounded-xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] p-3 text-xs font-bold text-[var(--crimson)]"
        >
          <span class="flex items-center gap-2">
            <AlertCircle class="h-4 w-4 shrink-0" />
            <span>{{ cardError }}</span>
          </span>
          <button type="button" class="underline underline-offset-4" @click="fetchStockCard">{{ $t('common.retry') }}</button>
        </div>

        <!-- Loading -->
        <div v-if="cardLoading" class="py-12 text-center text-[var(--muted)]">
          <Loader2 class="mx-auto h-6 w-6 animate-spin text-[var(--crimson)]" />
          <span class="mt-2 block text-xs">{{ $t('warehouseStocks.loadingCard') }}</span>
        </div>

        <!-- Movements Table -->
        <div v-else class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <div class="overflow-x-auto">
            <table class="w-full text-start text-xs">
              <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
                <tr>
                  <th class="w-10 px-3 py-3 text-center">#</th>
                  <th class="px-3 py-3">{{ $t('warehouseStocks.movementDate') }}</th>
                  <th class="px-3 py-3">{{ $t('warehouseStocks.documentNumber') }}</th>
                  <th class="px-3 py-3">{{ $t('warehouseStocks.documentType') }}</th>
                  <th class="px-3 py-3 text-center">{{ $t('warehouseStocks.direction') }}</th>
                  <th class="px-3 py-3 text-center text-emerald-600">{{ $t('warehouseStocks.inboundCol') }}</th>
                  <th class="px-3 py-3 text-center text-[var(--crimson)]">{{ $t('warehouseStocks.outboundCol') }}</th>
                  <th class="px-3 py-3 text-center font-black">{{ $t('warehouseStocks.balanceAfter') }}</th>
                  <th class="px-3 py-3">{{ $t('warehouseStocks.user') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)]">
                <tr
                  v-for="(m, i) in stockCardData?.movements || []"
                  :key="m.id"
                  class="transition-colors hover:bg-[var(--bg)]/50"
                  :class="m.is_reversal ? 'bg-amber-500/5' : ''"
                >
                  <td class="px-3 py-2.5 text-center font-bold text-[var(--muted)]">{{ i + 1 }}</td>
                  <td class="px-3 py-2.5 font-medium">{{ m.movement_date }}</td>
                  <td class="px-3 py-2.5 font-mono font-bold">
                    <span>{{ m.document_number }}</span>
                    <span v-if="m.is_reversal" class="ms-1 rounded bg-amber-500/10 px-1 py-0.5 text-[10px] text-amber-600 font-sans">
                      {{ $t('warehouseStocks.reversalMovement') }}
                    </span>
                  </td>
                  <td class="px-3 py-2.5 font-semibold text-[var(--text)]">{{ typeLabel(m.document_type) }}</td>
                  <td class="px-3 py-2.5 text-center">
                    <span
                      class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-bold text-[11px]"
                      :class="m.direction === 'inbound' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-red-500/10 text-red-600'"
                    >
                      <ArrowDown v-if="m.direction === 'inbound'" class="h-3 w-3" />
                      <ArrowUp v-else class="h-3 w-3" />
                      <span>{{ m.direction === 'inbound' ? $t('warehouseStocks.inbound') : $t('warehouseStocks.outbound') }}</span>
                    </span>
                  </td>
                  <td class="px-3 py-2.5 text-center font-bold text-emerald-600">
                    {{ Number(m.in_quantity) > 0 ? '+' + Number(m.in_quantity).toLocaleString() : '—' }}
                  </td>
                  <td class="px-3 py-2.5 text-center font-bold text-red-600">
                    {{ Number(m.out_quantity) > 0 ? '-' + Number(m.out_quantity).toLocaleString() : '—' }}
                  </td>
                  <td class="px-3 py-2.5 text-center">
                    <span class="inline-block rounded-md bg-[var(--bg)] px-2.5 py-1 font-mono font-black text-xs text-[var(--text)]">
                      {{ Number(m.balance_after).toLocaleString() }}
                    </span>
                  </td>
                  <td class="px-3 py-2.5 text-[var(--muted)]">{{ m.created_by_name || '—' }}</td>
                </tr>
                <tr v-if="!stockCardData?.movements || stockCardData.movements.length === 0">
                  <td colspan="9" class="py-8 text-center text-xs text-[var(--muted)]">
                    {{ $t('warehouseStocks.noMovements') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </AppDrawer>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ArrowDown,
  ArrowUp,
  Boxes,
  Building2,
  FileText,
  Loader2,
  Printer,
  Receipt,
  X,
} from 'lucide-vue-next';
import api from '../../services/api';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import { formatDateOnly } from '../../utils/date';
import { createWarehouseFetcher } from '../../utils/selectFetchers';

const { t, locale } = useI18n();

const loading = ref(false);
const stocks = ref([]);
const warehouses = ref([]);

const stats = reactive({
  total_tracked: 0,
  total_in_stock: 0,
  total_quantity: '0',
});

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const filters = reactive({
  search: '',
  warehouse_id: '',
  has_stock: '',
  page: 1,
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.warehouse_id) count++;
  if (filters.has_stock !== '') count++;
  return count;
});

const hasActiveFilters = computed(() => Boolean(filters.search) || activeFiltersCount.value > 0);

const fetchWarehouseOptions = createWarehouseFetcher({
  activeOnly: true,
  formatLabel: (w) => `${w.name} (${w.code})${w.is_default ? ' ' + t('warehouses.mainBadge') : ''}`,
});

const warehouseOptions = computed(() => {
  return warehouses.value.map((w) => ({
    value: w.id,
    label: `${w.name} (${w.code})${w.is_default ? ' ' + t('warehouses.mainBadge') : ''}`,
  }));
});

const stockStatusOptions = computed(() => [
  { value: '', label: t('warehouseStocks.allAvailability') },
  { value: '1', label: t('warehouseStocks.inStockOnly') },
  { value: '0', label: t('warehouseStocks.outOfStockOnly') },
]);

let searchDebounce = null;
function debouncedSearch() {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    filters.page = 1;
    loadStocks();
  }, 300);
}

function resetFilters() {
  filters.search = '';
  filters.warehouse_id = '';
  filters.has_stock = '';
  filters.page = 1;
  loadStocks();
}

function changePage(page) {
  filters.page = page;
  loadStocks();
}

async function loadStocks() {
  loading.value = true;
  try {
    const params = {
      page: filters.page,
      per_page: meta.per_page,
    };
    if (filters.search) params.search = filters.search;
    if (filters.warehouse_id) params.warehouse_id = filters.warehouse_id;
    if (filters.has_stock !== '') params.has_stock = filters.has_stock;

    const res = await api.get('/warehouse-stocks', { params });
    stocks.value = res.data?.data || [];

    if (res.data?.meta) {
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.total = res.data.meta.total;
    }

    if (res.data?.stats) {
      stats.total_tracked = res.data.stats.total_tracked || 0;
      stats.total_in_stock = res.data.stats.total_in_stock || 0;
      stats.total_quantity = res.data.stats.total_quantity || '0';
    }
  } catch (err) {
    console.error('Failed to load stocks:', err);
  } finally {
    loading.value = false;
  }
}

async function loadWarehouses() {
  try {
    const res = await api.get('/warehouses');
    warehouses.value = res.data?.data || [];
  } catch (err) {
    console.error('Failed to load warehouses:', err);
  }
}

// ---------------- Stock Card Drawer ----------------
const stockCardOpen = ref(false);
const cardLoading = ref(false);
const cardError = ref('');
const activeStockRow = ref(null);
const stockCardData = ref(null);

const cardFilters = reactive({
  direction: '',
  date_from: '',
  date_to: '',
});

function openStockCard(stock) {
  activeStockRow.value = stock;
  cardFilters.direction = '';
  cardFilters.date_from = '';
  cardFilters.date_to = '';
  cardError.value = '';
  stockCardOpen.value = true;
  fetchStockCard();
}

async function fetchStockCard() {
  if (!activeStockRow.value) return;
  cardLoading.value = true;
  cardError.value = '';

  try {
    const params = {
      product_id: activeStockRow.value.product_id,
      warehouse_id: activeStockRow.value.warehouse_id,
    };
    if (cardFilters.direction) params.direction = cardFilters.direction;
    if (cardFilters.date_from) params.date_from = cardFilters.date_from;
    if (cardFilters.date_to) params.date_to = cardFilters.date_to;

    const res = await api.get('/stock-movements/stock-card', { params });
    stockCardData.value = res.data?.data || null;
  } catch (err) {
    cardError.value = err?.response?.data?.message || t('warehouseStocks.loadCardError');
    console.error('Failed to load stock card:', err);
  } finally {
    cardLoading.value = false;
  }
}

function printCard() {
  window.print();
}

function formatDateTime(dtStr) {
  return formatDateOnly(dtStr, locale.value) || '—';
}

function typeLabel(type) {
  if (!type) return '—';
  const labels = {
    goods_receipt: t('inventory.types.goods_receipt'),
    goods_delivery: t('inventory.types.goods_delivery'),
    warehouse_transfer: t('inventory.types.warehouse_transfer'),
    stock_adjustment: t('inventory.types.stock_adjustment'),
    inventory_return: t('inventory.types.inventory_return'),
    stock_write_off: t('inventory.types.stock_write_off'),
    opening_balance: t('inventory.types.opening_balance'),
  };
  return labels[type] || type;
}

onMounted(() => {
  loadWarehouses();
  loadStocks();
});
</script>
