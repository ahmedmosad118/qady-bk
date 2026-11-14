<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-[var(--crimson)]">{{ $t('nav.inventoryGroup') }}</p>
        <h1 class="mt-1 text-2xl font-extrabold leading-tight text-[var(--text)] sm:text-[28px]">
          أرصدة المخازن وحركات المنتجات
        </h1>
        <p class="mt-1.5 text-sm leading-6 text-[var(--muted)]">
          متابعة الأرصدة الحالية الدقيقة لكل منتج في كل مخزن، واستعراض سجل الحركات وبطاقة الصنف التاريخية.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <router-link
          to="/inventory"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <FileText class="h-[18px] w-[18px] text-[var(--crimson)]" />
          <span>{{ $t('nav.allInventory') }}</span>
        </router-link>
      </div>
    </div>

    <!-- Quick Stats Cards -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
      <StatCard
        label="أصناف مسجلة بالمخازن"
        :value="stats.total_tracked"
        :loading="loading"
      />
      <StatCard
        label="أصناف متوفر منها رصيد"
        :value="stats.total_in_stock"
        :loading="loading"
      />
      <StatCard
        label="إجمالي كميات المخزون المتوفرة"
        :value="Number(stats.total_quantity).toLocaleString() + ' وحدة'"
        :loading="loading"
      />
    </div>

    <!-- Main Stock Table Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
      <!-- Toolbar & Filters -->
      <div class="border-b border-[var(--border)] p-4 sm:p-5">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <!-- Search by Name / SKU / Barcode -->
          <div class="relative">
            <Search
              class="pointer-events-none absolute right-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[var(--muted)]"
            />
            <input
              v-model="filters.search"
              type="search"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] pl-4 pr-11 text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/70 focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              placeholder="بحث بالاسم أو SKU أو الباركود..."
              @input="debouncedSearch"
            />
          </div>

          <!-- Warehouse Selector -->
          <div>
            <SearchableSelect
              v-model="filters.warehouse_id"
              :options="warehouseOptions"
              placeholder="كل المخازن"
              search-placeholder="بحث في المخازن..."
              allow-empty
              @change="loadStocks"
            />
          </div>

          <!-- Stock Availability Filter -->
          <div>
            <SearchableSelect
              v-model="filters.has_stock"
              :options="stockStatusOptions"
              placeholder="حالة التوفر"
              allow-empty
              @change="loadStocks"
            />
          </div>

          <!-- Reset Filters -->
          <div class="flex items-center gap-2">
            <button
              v-if="hasActiveFilters"
              type="button"
              class="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] px-4 text-xs font-bold text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
              @click="resetFilters"
            >
              <RotateCcw class="h-4 w-4" />
              <span>إعادة تعيين الفلاتر</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="w-12 px-4 py-3.5 text-center">#</th>
              <th class="px-4 py-3.5">المنتج / الإصدار</th>
              <th class="px-4 py-3.5">رمز الصنف (SKU)</th>
              <th class="px-4 py-3.5">المخزن</th>
              <th class="px-4 py-3.5 text-center">الرصيد الحالي</th>
              <th class="px-4 py-3.5 text-center">آخر تحديث</th>
              <th class="px-4 py-3.5 text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading -->
            <tr v-if="loading && stocks.length === 0">
              <td colspan="7" class="px-4 py-12 text-center text-sm text-[var(--muted)]">
                <Loader2 class="mx-auto h-6 w-6 animate-spin text-[var(--crimson)]" />
                <span class="mt-2 block">جاري تحميل الأرصدة...</span>
              </td>
            </tr>

            <!-- Empty -->
            <tr v-else-if="stocks.length === 0">
              <td colspan="7" class="px-4 py-12 text-center">
                <Boxes class="mx-auto h-10 w-10 text-[var(--muted)]/50" />
                <h3 class="mt-3 text-base font-bold text-[var(--text)]">لا توجد أرصدة مخزنية مطابقة</h3>
                <p class="mt-1 text-xs text-[var(--muted)]">لم يتم العثور على أية منتجات مطابقة للفلاتر المحددة.</p>
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

              <!-- SKU -->
              <td class="px-4 py-3.5">
                <span class="rounded-md bg-[var(--bg)] px-2.5 py-1 font-mono text-xs font-bold text-[var(--text)]" dir="ltr">
                  {{ stock.product?.sku || '—' }}
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
                    رئيسي
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
              <td class="px-4 py-3.5 text-left">
                <button
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:border-[var(--crimson)] hover:text-[var(--crimson)]"
                  title="عرض بطاقة حركة الصنف"
                  @click="openStockCard(stock)"
                >
                  <Receipt class="h-3.5 w-3.5 text-[var(--crimson)]" />
                  <span>بطاقة الصنف</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div
        v-if="meta.total > meta.per_page"
        class="flex flex-col items-center justify-between gap-4 border-t border-[var(--border)] p-4 sm:flex-row sm:px-6"
      >
        <span class="text-xs text-[var(--muted)]">
          الصفحة {{ meta.current_page }} من {{ meta.last_page }} ({{ meta.total }} منتج)
        </span>

        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="meta.current_page <= 1"
            class="rounded-lg border border-[var(--border)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-40"
            @click="changePage(meta.current_page - 1)"
          >
            السابق
          </button>
          <button
            type="button"
            :disabled="meta.current_page >= meta.last_page"
            class="rounded-lg border border-[var(--border)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-40"
            @click="changePage(meta.current_page + 1)"
          >
            التالي
          </button>
        </div>
      </div>
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
              class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--crimson)]/20 bg-[var(--crimson-soft)] text-[var(--crimson)] shadow-xs"
            >
              <Receipt class="h-6 w-6" />
            </div>

            <div>
              <div class="flex flex-wrap items-center gap-2.5">
                <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl">
                  {{ activeStockRow?.product?.name }}
                </h2>
                <span class="rounded-md bg-[var(--bg)] px-2 py-0.5 font-mono text-xs font-bold text-[var(--muted)]" dir="ltr">
                  {{ activeStockRow?.product?.sku }}
                </span>
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-3 py-0.5 text-xs font-bold text-emerald-600 border border-emerald-500/20">
                  الرصيد الحالي: {{ Number(stockCardData?.current_stock ?? activeStockRow?.quantity ?? 0).toLocaleString() }} وحدة
                </span>
              </div>
              <p class="mt-1 text-xs text-[var(--muted)]">
                بطاقة حركة الصنف في: <span class="font-bold text-[var(--text)]">{{ activeStockRow?.warehouse?.name }}</span>
              </p>
            </div>
          </div>

          <div class="flex items-center gap-1">
            <button
              type="button"
              class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
              title="طباعة بطاقة الصنف"
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
            <span class="font-bold text-[var(--muted)]">فلترة الحركات:</span>
            <!-- Direction -->
            <select
              v-model="cardFilters.direction"
              class="h-8 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2 text-xs font-bold text-[var(--text)] outline-none"
              @change="fetchStockCard"
            >
              <option value="">كل الحركات (وارد وصادر)</option>
              <option value="inbound">الوارد فقط (INBOUND)</option>
              <option value="outbound">الصادر فقط (OUTBOUND)</option>
            </select>

            <!-- Date From -->
            <input
              v-model="cardFilters.date_from"
              type="date"
              class="h-8 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2 text-xs text-[var(--text)] outline-none"
              @change="fetchStockCard"
            />
            <span class="text-[var(--muted)]">إلى</span>
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
              إجمالي الحركات: <span class="text-[var(--text)]">{{ stockCardData?.movements?.length || 0 }}</span>
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
          <button type="button" class="underline underline-offset-4" @click="fetchStockCard">إعادة المحاولة</button>
        </div>

        <!-- Loading -->
        <div v-if="cardLoading" class="py-12 text-center text-[var(--muted)]">
          <Loader2 class="mx-auto h-6 w-6 animate-spin text-[var(--crimson)]" />
          <span class="mt-2 block text-xs">جاري تجهيز بطاقة الصنف وحساب الأرصدة التراكمية...</span>
        </div>

        <!-- Movements Table -->
        <div v-else class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <div class="overflow-x-auto">
            <table class="w-full text-right text-xs">
              <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
                <tr>
                  <th class="w-10 px-3 py-3 text-center">#</th>
                  <th class="px-3 py-3">تاريخ الحركة</th>
                  <th class="px-3 py-3">رقم المستند</th>
                  <th class="px-3 py-3">نوع المستند</th>
                  <th class="px-3 py-3 text-center">الاتجاه</th>
                  <th class="px-3 py-3 text-center text-emerald-600">وارد (+)</th>
                  <th class="px-3 py-3 text-center text-[var(--crimson)]">صادر (-)</th>
                  <th class="px-3 py-3 text-center font-black">الرصيد بعد الحركة</th>
                  <th class="px-3 py-3">المستخدم</th>
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
                  <td class="px-3 py-2.5 font-mono font-bold" dir="ltr">
                    <span>{{ m.document_number }}</span>
                    <span v-if="m.is_reversal" class="ms-1 rounded bg-amber-500/10 px-1 py-0.5 text-[10px] text-amber-600 font-sans">
                      حركة عكسية
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
                      <span>{{ m.direction === 'inbound' ? 'وارد' : 'صادر' }}</span>
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
                    لا توجد حركات مخزنية مسجلة لهذا الصنف في هذا المخزن.
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
  RotateCcw,
  Search,
  X,
} from 'lucide-vue-next';
import api from '../../services/api';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import StatCard from '../../components/ui/StatCard.vue';

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
  per_page: 15,
  total: 0,
});

const filters = reactive({
  search: '',
  warehouse_id: '',
  has_stock: '',
  page: 1,
});

const hasActiveFilters = computed(() => {
  return !!filters.search || !!filters.warehouse_id || filters.has_stock !== '';
});

const warehouseOptions = computed(() => {
  return warehouses.value.map((w) => ({
    value: w.id,
    label: `${w.name} (${w.code})${w.is_default ? ' ★ الرئيسي' : ''}`,
  }));
});

const stockStatusOptions = [
  { value: '', label: 'الكل (المتوفر والصفر)' },
  { value: '1', label: 'المتوفر فقط (> 0)' },
  { value: '0', label: 'غير المتوفر فقط (= 0)' },
];

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
    cardError.value = err?.response?.data?.message || 'تعذر تحميل بيانات بطاقة الصنف من الخادم.';
    console.error('Failed to load stock card:', err);
  } finally {
    cardLoading.value = false;
  }
}

function printCard() {
  window.print();
}

function formatDateTime(dtStr) {
  if (!dtStr) return '—';
  return new Date(dtStr).toLocaleDateString('ar-EG');
}

function typeLabel(type) {
  if (!type) return '—';
  const labels = {
    goods_receipt: 'إذن استلام مخزني',
    goods_delivery: 'إذن تسليم مخزني',
    stock_issue: 'إذن صرف داخلي',
    warehouse_transfer: 'إذن تحويل مخزني',
    stock_adjustment: 'إذن تسوية جردية',
    inventory_return: 'إذن مرتجع مخزني',
    stock_write_off: 'إذن إتلاف / هالك',
    opening_balance: 'رصيد افتتاحي',
  };
  return labels[type] || type;
}

onMounted(() => {
  loadWarehouses();
  loadStocks();
});
</script>
