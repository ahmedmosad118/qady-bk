<template>
  <div class="space-y-6" dir="rtl">
    <!-- Page Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-[var(--crimson)]">نقاط البيع</p>
        <h1 class="mt-1 text-2xl font-extrabold leading-tight text-[var(--text)] sm:text-[28px]">
          فواتير POS
        </h1>
        <p class="mt-1.5 text-sm leading-6 text-[var(--muted)]">
          استعراض ومتابعة فواتير البيع المكتملة وإعادة طباعة الإيصالات الرسمية وتتبع سندات الصرف المخزني.
        </p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <router-link
          to="/pos/shifts"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Clock class="h-[18px] w-[18px] text-amber-600" />
          <span>الورديات</span>
        </router-link>

        <router-link
          to="/pos"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
        >
          <Store class="h-[18px] w-[18px]" />
          <span>شاشة الكاشير (POS)</span>
        </router-link>
      </div>
    </div>

    <!-- Filters Bar -->
    <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <!-- Search Input -->
        <div class="lg:col-span-2">
          <label class="block text-xs font-bold text-[var(--muted)] mb-1">بحث بالفاتورة أو العميل أو الكاشير</label>
          <div class="relative">
            <Search class="w-4 h-4 text-[var(--muted)] absolute top-1/2 -translate-y-1/2 right-3 pointer-events-none" />
            <input
              v-model="filters.search"
              type="text"
              placeholder="رقم الفاتورة، اسم العميل، الهاتف..."
              class="w-full h-10 pr-9 pl-3 text-xs rounded-xl border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] focus:outline-hidden focus:border-[var(--crimson)]"
              @input="onSearchDebounced"
            />
          </div>
        </div>

        <!-- Date From -->
        <div>
          <label class="block text-xs font-bold text-[var(--muted)] mb-1">من تاريخ</label>
          <input
            v-model="filters.date_from"
            type="date"
            class="w-full h-10 px-3 text-xs rounded-xl border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] focus:outline-hidden focus:border-[var(--crimson)]"
            @change="fetchSales(1)"
          />
        </div>

        <!-- Date To -->
        <div>
          <label class="block text-xs font-bold text-[var(--muted)] mb-1">إلى تاريخ</label>
          <input
            v-model="filters.date_to"
            type="date"
            class="w-full h-10 px-3 text-xs rounded-xl border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] focus:outline-hidden focus:border-[var(--crimson)]"
            @change="fetchSales(1)"
          />
        </div>

        <!-- Payment Method -->
        <div>
          <label class="block text-xs font-bold text-[var(--muted)] mb-1">طريقة الدفع</label>
          <select
            v-model="filters.payment_method_id"
            class="w-full h-10 px-3 text-xs rounded-xl border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] focus:outline-hidden focus:border-[var(--crimson)]"
            @change="fetchSales(1)"
          >
            <option value="">كل طرق الدفع</option>
            <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">
              {{ pm.name }}
            </option>
          </select>
        </div>
      </div>

      <!-- Active Filters Reset -->
      <div v-if="hasActiveFilters" class="mt-3 pt-3 border-t border-[var(--border)] flex justify-end">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 text-xs font-bold text-[var(--muted)] hover:text-[var(--crimson)] transition cursor-pointer"
          @click="resetFilters"
        >
          <RotateCcw class="w-3.5 h-3.5" />
          <span>إعادة تعيين الفلاتر</span>
        </button>
      </div>
    </div>

    <!-- Data Table Container -->
    <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-4 py-3.5">رقم الفاتورة</th>
              <th class="px-4 py-3.5">التاريخ والوقت</th>
              <th class="px-4 py-3.5">العميل</th>
              <th class="px-4 py-3.5">الكاشير</th>
              <th class="px-4 py-3.5 text-center">طريقة الدفع</th>
              <th class="px-4 py-3.5 text-center">الوردية</th>
              <th class="px-4 py-3.5 text-center">الأصناف</th>
              <th class="px-4 py-3.5 text-end">إجمالي الفاتورة</th>
              <th class="px-4 py-3.5 text-left">الإجراءات</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading Skeletons -->
            <tr v-if="loading && sales.length === 0">
              <td colspan="9" class="px-4 py-16 text-center text-sm text-[var(--muted)]">
                <Loader2 class="mx-auto h-7 w-7 animate-spin text-[var(--crimson)]" />
                <span class="mt-2.5 block font-bold">جاري تحميل فواتير البيع...</span>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="!loading && sales.length === 0">
              <td colspan="9" class="px-4 py-16 text-center">
                <Receipt class="mx-auto h-12 w-12 text-[var(--muted)]/50" />
                <h3 class="mt-3 text-base font-extrabold text-[var(--text)]">لا توجد فواتير مبيعات مطابقة</h3>
                <p class="mt-1 text-xs text-[var(--muted)] max-w-sm mx-auto">
                  لم يتم العثور على أي فواتير مسجلة بناءً على معايير البحث والفلترة المحددة.
                </p>
              </td>
            </tr>

            <!-- Table Rows -->
            <tr
              v-for="sale in sales"
              :key="sale.id"
              class="transition-colors hover:bg-[var(--bg)]/60 cursor-pointer"
              @click="openSaleDetails(sale.id)"
            >
              <!-- Invoice Number & Linked Inventory Document -->
              <td class="px-4 py-3.5 whitespace-nowrap">
                <button
                  type="button"
                  class="font-mono text-sm font-extrabold text-[var(--crimson)] hover:underline flex items-center gap-1.5 cursor-pointer"
                  @click.stop="openSaleDetails(sale.id)"
                >
                  <Receipt class="w-3.5 h-3.5 shrink-0" />
                  <span>{{ sale.invoice_number }}</span>
                </button>
                <div v-if="sale.inventory_documents && sale.inventory_documents.length > 0" class="mt-1">
                  <button
                    type="button"
                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-500/10 text-amber-700 dark:text-amber-400 hover:bg-amber-500/20 transition cursor-pointer"
                    title="فتح سند الصرف المخزني المرتبط"
                    @click.stop="handleOpenInventoryDocumentFromSale(sale.inventory_documents[0].id)"
                  >
                    <PackageCheck class="w-3 h-3" />
                    <span>سند: {{ sale.inventory_documents[0].document_number }}</span>
                  </button>
                </div>
              </td>

              <!-- Date and Time -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs text-[var(--muted)]">
                <div class="font-mono text-xs font-semibold text-[var(--text)]" dir="ltr">
                  {{ formatDateTime(sale.sold_at || sale.created_at) }}
                </div>
              </td>

              <!-- Customer -->
              <td class="px-4 py-3.5 whitespace-nowrap">
                <div v-if="sale.customer" class="flex flex-col">
                  <span class="text-xs font-bold text-[var(--text)]">{{ sale.customer.name }}</span>
                  <span v-if="sale.customer.phone" class="text-[11px] font-mono text-[var(--muted)]" dir="ltr">
                    {{ sale.customer.phone }}
                  </span>
                </div>
                <span
                  v-else
                  class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-[var(--bar)] text-[var(--muted)]"
                >
                  عميل نقدي (Walk-in)
                </span>
              </td>

              <!-- Cashier -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs">
                <div class="flex items-center gap-1.5 text-[var(--text)]">
                  <UserCheck class="w-3.5 h-3.5 text-[var(--muted)] shrink-0" />
                  <span class="font-medium">{{ sale.creator?.name || '—' }}</span>
                </div>
              </td>

              <!-- Payment Method -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[var(--bar)] text-[var(--text)]">
                  {{ sale.payment_method?.name || 'نقدي' }}
                </span>
              </td>

              <!-- Shift -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <button
                  v-if="sale.shift || sale.pos_shift_id"
                  type="button"
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-mono font-bold border border-[var(--border)] hover:bg-[var(--bg)] transition cursor-pointer"
                  title="عرض تفاصيل الوردية"
                  @click.stop="handleOpenShiftFromSale(sale.pos_shift_id || sale.shift?.id)"
                >
                  <Clock class="w-3 h-3 text-amber-600" />
                  <span>{{ sale.shift?.shift_number || ('SH-#' + sale.pos_shift_id) }}</span>
                </button>
                <span v-else class="text-xs text-[var(--muted)]">—</span>
              </td>

              <!-- Items count -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center text-xs font-bold text-[var(--muted)]">
                {{ sale.items_count ?? (sale.items ? sale.items.length : 1) }}
              </td>

              <!-- Grand Total -->
              <td class="px-4 py-3.5 whitespace-nowrap text-end">
                <b class="text-sm font-black text-[var(--text)] font-mono">
                  {{ Number(sale.grand_total || 0).toFixed(2) }}
                  <span class="text-xs font-sans font-medium text-[var(--muted)]">ج.م</span>
                </b>
              </td>

              <!-- Actions -->
              <td class="px-4 py-3.5 whitespace-nowrap text-left" @click.stop>
                <div class="flex items-center justify-end gap-1.5">
                  <!-- Print Thermal Receipt -->
                  <button
                    type="button"
                    class="h-8 px-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                    title="طباعة إيصال الفاتورة الحراري"
                    @click="openPrintModal(sale)"
                  >
                    <Printer class="w-3.5 h-3.5" />
                    <span class="hidden sm:inline">طباعة</span>
                  </button>

                  <!-- View Details -->
                  <button
                    type="button"
                    class="h-8 px-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] hover:bg-[var(--bg)] text-xs font-bold text-[var(--text)] transition flex items-center gap-1.5 cursor-pointer"
                    title="عرض تفاصيل الفاتورة"
                    @click="openSaleDetails(sale.id)"
                  >
                    <Eye class="w-3.5 h-3.5 text-[var(--muted)]" />
                    <span class="hidden sm:inline">تفاصيل</span>
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
          عرض الصفحة {{ meta.current_page }} من {{ meta.last_page }} (إجمالي {{ meta.total }} فاتورة)
        </span>
        <div class="flex items-center gap-1.5">
          <button
            type="button"
            class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[var(--bg)] flex items-center gap-1 cursor-pointer"
            :disabled="meta.current_page <= 1"
            @click="fetchSales(meta.current_page - 1)"
          >
            <ChevronRight class="w-4 h-4" />
            <span>السابق</span>
          </button>
          <button
            type="button"
            class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold transition disabled:opacity-40 disabled:cursor-not-allowed hover:bg-[var(--bg)] flex items-center gap-1 cursor-pointer"
            :disabled="meta.current_page >= meta.last_page"
            @click="fetchSales(meta.current_page + 1)"
          >
            <span>التالي</span>
            <ChevronLeft class="w-4 h-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- Sale Detail Modal -->
    <PosSaleDetailModal
      v-if="selectedSaleId"
      :sale-id="selectedSaleId"
      @close="selectedSaleId = null"
      @print="openPrintModal"
      @open-shift="handleOpenShiftFromSale"
      @open-inventory-document="handleOpenInventoryDocumentFromSale"
    />

    <!-- Shift Detail Modal (Cross-linked) -->
    <PosShiftDetailModal
      v-if="selectedShiftId"
      :shift-id="selectedShiftId"
      @close="selectedShiftId = null"
      @open-sale="openSaleDetails"
    />

    <!-- Inventory Document Detail Modal (Cross-linked) -->
    <InventoryDocumentDetailModal
      v-if="selectedInvDoc"
      :document="selectedInvDoc"
      @close="selectedInvDoc = null"
    />

    <!-- Thermal Receipt Modal (Reused directly from POS in Reprint Mode) -->
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
  Receipt,
  Store,
  Clock,
  Search,
  UserCheck,
  Eye,
  Printer,
  ChevronRight,
  ChevronLeft,
  Loader2,
  PackageCheck,
  RotateCcw,
} from 'lucide-vue-next';
import api from '../../../services/api';
import PosSaleDetailModal from './PosSaleDetailModal.vue';
import PosShiftDetailModal from './PosShiftDetailModal.vue';
import InventoryDocumentDetailModal from '../../inventory/InventoryDocumentDetailModal.vue';
import ReceiptModal from '../modals/ReceiptModal.vue';

const sales = ref([]);
const loading = ref(true);
const paymentMethods = ref([]);

const filters = reactive({
  search: '',
  date_from: '',
  date_to: '',
  payment_method_id: '',
});

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});

const selectedSaleId = ref(null);
const selectedShiftId = ref(null);
const selectedInvDoc = ref(null);
const selectedSaleForReceipt = ref(null);

const hasActiveFilters = computed(() => {
  return !!(filters.search.trim() || filters.date_from || filters.date_to || filters.payment_method_id);
});

function resetFilters() {
  filters.search = '';
  filters.date_from = '';
  filters.date_to = '';
  filters.payment_method_id = '';
  fetchSales(1);
}

let searchTimeout = null;
function onSearchDebounced() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    fetchSales(1);
  }, 350);
}

async function fetchSales(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: meta.per_page,
      search: filters.search.trim() || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      payment_method_id: filters.payment_method_id || undefined,
    };

    const res = await api.get('/pos/sales', { params });
    if (res.data?.success) {
      sales.value = res.data.data;
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.total = res.data.meta.total;
    }
  } catch (err) {
    console.error('Failed to load sales list:', err);
  } finally {
    loading.value = false;
  }
}

async function fetchPaymentMethods() {
  try {
    const res = await api.get('/pos/payment-methods');
    if (res.data?.success) {
      paymentMethods.value = res.data.data;
    }
  } catch (err) {
    console.error('Failed to load payment methods:', err);
  }
}

function openSaleDetails(saleId) {
  selectedSaleId.value = saleId;
}

async function openPrintModal(sale) {
  // If sale object does not have loaded items or paymentMethod, fetch fresh
  if (!sale.items || !sale.payment_method) {
    try {
      const res = await api.get(`/pos/sales/${sale.id}`);
      if (res.data?.success) {
        selectedSaleForReceipt.value = res.data.data;
        return;
      }
    } catch (e) {
      // ignore
    }
  }
  selectedSaleForReceipt.value = sale;
}

function handleOpenShiftFromSale(shiftId) {
  if (!shiftId) return;
  selectedShiftId.value = shiftId;
}

async function handleOpenInventoryDocumentFromSale(docId) {
  if (!docId) return;
  try {
    const res = await api.get(`/inventory-documents/${docId}`);
    if (res.data?.success) {
      selectedInvDoc.value = res.data.data;
    }
  } catch (err) {
    console.error('Failed to open inventory document:', err);
  }
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
  fetchPaymentMethods();
  fetchSales(1);
});
</script>
