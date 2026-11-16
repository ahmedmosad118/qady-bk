<template>
  <div class="space-y-6" dir="rtl">
    <!-- Page Header -->
    <PageHeader
      title="الورديات"
      subtitle="متابعة ورديات الكاشير، تدقيق أرصدة الدرج، فحص الحركات النقدية والفوارق المالية عند الإغلاق."
    >
      <template #actions>
        <router-link
          to="/invoices"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Receipt class="h-[18px] w-[18px] text-[var(--crimson)]" />
          <span>فواتير POS</span>
        </router-link>

        <router-link
          to="/pos"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
        >
          <Store class="h-[18px] w-[18px]" />
          <span>شاشة الكاشير (POS)</span>
        </router-link>
      </template>
    </PageHeader>

    <!-- Data Table Container -->
    <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs overflow-hidden">
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        search-placeholder="SH-..., اسم الكاشير..."
        :active-filters-count="activeFiltersCount"
        @search="onSearchDebounced"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Status Filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">حالة الوردية</label>
            <select
              v-model="filters.status"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="fetchShifts(1)"
            >
              <option value="">كل الورديات</option>
              <option value="open">ورديات مفتوحة حالياً</option>
              <option value="closed">ورديات مغلقة ومجردة</option>
            </select>
          </div>

          <!-- Date Range -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
              <input
                v-model="filters.date_from"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="fetchShifts(1)"
              />
            </div>

            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
              <input
                v-model="filters.date_to"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="fetchShifts(1)"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>
      <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-4 py-3.5">رقم الوردية</th>
              <th class="px-4 py-3.5 text-center">الحالة</th>
              <th class="px-4 py-3.5">تاريخ الفتح</th>
              <th class="px-4 py-3.5">تاريخ الإغلاق</th>
              <th class="px-4 py-3.5">فاتح الوردية</th>
              <th class="px-4 py-3.5">مغلق الوردية</th>
              <th class="px-4 py-3.5 text-end">إجمالي المبيعات</th>
              <th class="px-4 py-3.5 text-center">فارق الدرج</th>
              <th class="px-4 py-3.5 text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading Skeletons -->
            <tr v-if="loading && shifts.length === 0">
              <td colspan="9" class="px-4 py-16 text-center text-sm text-[var(--muted)]">
                <Loader2 class="mx-auto h-7 w-7 animate-spin text-[var(--crimson)]" />
                <span class="mt-2.5 block font-bold">جاري تحميل ورديات البيع...</span>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="!loading && shifts.length === 0">
              <td colspan="9" class="px-4 py-16 text-center">
                <Clock class="mx-auto h-12 w-12 text-[var(--muted)]/50" />
                <h3 class="mt-3 text-base font-extrabold text-[var(--text)]">لا توجد ورديات مسجلة مطابقة</h3>
                <p class="mt-1 text-xs text-[var(--muted)] max-w-sm mx-auto">
                  لم يتم العثور على أي ورديات عمل مطابقة لمعايير البحث الحالية.
                </p>
              </td>
            </tr>

            <!-- Table Rows -->
            <tr
              v-for="shift in shifts"
              :key="shift.id"
              class="transition-colors hover:bg-[var(--bg)]/60 cursor-pointer"
              @click="openShiftDetails(shift.id)"
            >
              <!-- Shift Number -->
              <td class="px-4 py-3.5 whitespace-nowrap">
                <button
                  type="button"
                  class="font-mono text-sm font-extrabold text-[var(--crimson)] hover:underline flex items-center gap-1.5 cursor-pointer"
                  @click.stop="openShiftDetails(shift.id)"
                >
                  <Clock class="w-3.5 h-3.5 text-amber-600 shrink-0" />
                  <span>{{ shift.shift_number }}</span>
                </button>
              </td>

              <!-- Status Badge -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <span
                  class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold"
                  :class="shift.closed_at ? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30'"
                >
                  <span class="w-1.5 h-1.5 rounded-full" :class="shift.closed_at ? 'bg-zinc-400' : 'bg-emerald-500 animate-pulse'"></span>
                  <span>{{ shift.closed_at ? 'مغلقة' : 'مفتوحة حالياً' }}</span>
                </span>
              </td>

              <!-- Opened At -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs text-[var(--muted)]">
                <div class="font-mono text-xs font-semibold text-[var(--text)]" dir="ltr">
                  {{ formatDateTime(shift.opened_at) }}
                </div>
              </td>

              <!-- Closed At -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs text-[var(--muted)]">
                <div v-if="shift.closed_at" class="font-mono text-xs font-semibold text-[var(--text)]" dir="ltr">
                  {{ formatDateTime(shift.closed_at) }}
                </div>
                <span v-else class="text-xs font-semibold text-emerald-600">قيد التشغيل</span>
              </td>

              <!-- Opened By -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs font-medium text-[var(--text)]">
                <div class="flex items-center gap-1.5">
                  <User class="w-3.5 h-3.5 text-[var(--muted)] shrink-0" />
                  <span>{{ shift.opened_by?.name || '—' }}</span>
                </div>
              </td>

              <!-- Closed By -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs font-medium text-[var(--text)]">
                <div v-if="shift.closed_at" class="flex items-center gap-1.5">
                  <UserCheck class="w-3.5 h-3.5 text-[var(--muted)] shrink-0" />
                  <span>{{ shift.closed_by?.name || shift.opened_by?.name || '—' }}</span>
                </div>
                <span v-else class="text-[var(--muted)]">—</span>
              </td>

              <!-- Sales Total -->
              <td class="px-4 py-3.5 whitespace-nowrap text-end">
                <b class="text-sm font-black text-[var(--text)] font-mono">
                  {{ Number(shift.sales_sum_grand_total || 0).toFixed(2) }}
                  <span class="text-xs font-sans font-medium text-[var(--muted)]">ج.م</span>
                </b>
              </td>

              <!-- Cash Difference -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <span
                  v-if="shift.closed_at"
                  class="px-2 py-0.5 rounded-lg text-xs font-bold font-mono inline-flex items-center gap-1"
                  :class="diffClass(shift.difference)"
                >
                  <span>{{ diffText(shift.difference) }}</span>
                  <span class="text-[10px]">({{ diffStatusLabel(shift.difference) }})</span>
                </span>
                <span v-else class="text-[11px] font-semibold text-emerald-600">
                  قيد التشغيل
                </span>
              </td>

              <!-- Actions -->
              <td class="px-4 py-3.5 whitespace-nowrap text-left" @click.stop>
                <div class="flex items-center justify-end">
                  <button
                    type="button"
                    class="h-8 px-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] hover:bg-[var(--bg)] text-xs font-bold text-[var(--text)] transition flex items-center gap-1.5 cursor-pointer"
                    title="عرض تفاصيل الوردية"
                    @click="openShiftDetails(shift.id)"
                  >
                    <Eye class="w-3.5 h-3.5 text-[var(--muted)]" />
                    <span>التفاصيل</span>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="meta.last_page > 1" class="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 border-t border-[var(--border)] bg-[var(--card)]">
        <span class="text-xs text-[var(--muted)]">
          عرض الصفحة {{ meta.current_page }} من {{ meta.last_page }} (إجمالي {{ meta.total }} وردية)
        </span>
        <div class="flex items-center gap-1.5">
          <button
            type="button"
            class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[var(--bg)] flex items-center gap-1 cursor-pointer"
            :disabled="meta.current_page <= 1"
            @click="fetchShifts(meta.current_page - 1)"
          >
            <ChevronRight class="w-4 h-4" />
            <span>السابق</span>
          </button>
          <button
            type="button"
            class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[var(--bg)] flex items-center gap-1 cursor-pointer"
            :disabled="meta.current_page >= meta.last_page"
            @click="fetchShifts(meta.current_page + 1)"
          >
            <span>التالي</span>
            <ChevronLeft class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- Shift Detail Modal -->
    <PosShiftDetailModal
      v-if="selectedShiftId"
      :shift-id="selectedShiftId"
      @close="selectedShiftId = null"
      @open-sale="handleOpenSaleFromShift"
      @print="openPrintModal"
    />

    <!-- Sale Detail Modal (Cross-linked) -->
    <PosSaleDetailModal
      v-if="selectedSaleId"
      :sale-id="selectedSaleId"
      :initial-tab="selectedSaleTab"
      @close="selectedSaleId = null"
      @print="openPrintModal"
    />

    <!-- Thermal Receipt Modal (Reused for reprints from shift sales) -->
    <ReceiptModal
      v-if="selectedSaleForReceipt"
      :sale="selectedSaleForReceipt"
      :is-reprint="true"
      @new-sale="selectedSaleForReceipt = null"
      @close="selectedSaleForReceipt = null"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import {
  Clock,
  Store,
  Receipt,
  User,
  UserCheck,
  Eye,
  ChevronRight,
  ChevronLeft,
  Loader2,
} from 'lucide-vue-next';
import api from '../../../services/api';
import FilterToolbar from '../../../components/ui/FilterToolbar.vue';
import PageHeader from '../../../components/ui/PageHeader.vue';
import PosShiftDetailModal from './PosShiftDetailModal.vue';
import PosSaleDetailModal from './PosSaleDetailModal.vue';
import ReceiptModal from '../modals/ReceiptModal.vue';

const shifts = ref([]);
const loading = ref(true);

const filters = reactive({
  status: '',
  search: '',
  date_from: '',
  date_to: '',
});

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});

const selectedShiftId = ref(null);
const selectedSaleId = ref(null);
const selectedSaleTab = ref('info');
const selectedSaleForReceipt = ref(null);

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.status) count++;
  if (filters.date_from) count++;
  if (filters.date_to) count++;
  return count;
});

const hasActiveFilters = computed(() => Boolean(filters.search.trim()) || activeFiltersCount.value > 0);

function resetFilters() {
  filters.status = '';
  filters.search = '';
  filters.date_from = '';
  filters.date_to = '';
  fetchShifts(1);
}

let searchTimeout = null;
function onSearchDebounced() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchShifts(1);
  }, 350);
}

async function fetchShifts(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: meta.per_page,
      status: filters.status || undefined,
      search: filters.search.trim() || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
    };

    const res = await api.get('/pos/shifts', { params });
    if (res.data?.success) {
      shifts.value = res.data.data;
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.total = res.data.meta.total;
    }
  } catch (err) {
    console.error('Failed to load shifts list:', err);
  } finally {
    loading.value = false;
  }
}

function openShiftDetails(shiftId) {
  selectedShiftId.value = shiftId;
}

function handleOpenSaleFromShift(saleId, tab = 'info') {
  selectedSaleTab.value = tab;
  selectedSaleId.value = saleId;
}

async function openPrintModal(sale) {
  if (!sale.items || !sale.payment_method) {
    try {
      const res = await api.get(`/pos/sales/${sale.id}`);
      if (res.data?.success) {
        selectedSaleForReceipt.value = res.data.data;
        return;
      }
    } catch {
      // fallback
    }
  }
  selectedSaleForReceipt.value = sale;
}

// Difference logic:
// Positive difference: surplus (فائض)
// Negative difference: shortage (عجز)
// Zero difference: balanced (مطابق)
function diffStatusLabel(diff) {
  const d = Number(diff || 0);
  if (d > 0.001) return 'فائض';
  if (d < -0.001) return 'عجز';
  return 'مطابق';
}

function diffText(diff) {
  const d = Number(diff || 0);
  const prefix = d > 0 ? '+' : '';
  return `${prefix}${d.toFixed(2)} ج.م`;
}

function diffClass(diff) {
  const d = Number(diff || 0);
  if (d > 0.001) return 'bg-emerald-50 text-emerald-800 border border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-300';
  if (d < -0.001) return 'bg-red-50 text-red-800 border border-red-300 dark:bg-red-950/40 dark:text-red-300';
  return 'bg-zinc-100 text-zinc-800 border border-zinc-200 dark:bg-zinc-800 dark:text-zinc-200';
}

function formatDateTime(val) {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

onMounted(() => {
  fetchShifts(1);
});
</script>
