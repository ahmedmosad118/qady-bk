<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader
      :title="$t('posReturns.title')"
      :subtitle="$t('posReturns.subtitle')"
    >
      <template #actions>
        <router-link
          to="/invoices"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Receipt class="h-[18px] w-[18px] text-[var(--crimson)]" />
          <span>{{ $t('nav.invoices') }}</span>
        </router-link>

        <router-link
          to="/pos"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
        >
          <Store class="h-[18px] w-[18px]" />
          <span>{{ $t('posReturns.cashierScreen') }}</span>
        </router-link>
      </template>
    </PageHeader>

    <!-- Data Table Container -->
    <DataTable
      :items="returns"
      :loading="loading"
      :colspan="10"
      :meta="meta"
      :per-page="meta.per_page"
      :loading-text="$t('posReturns.loadingReturns')"
      :empty-title="$t('posReturns.emptyTitle')"
      :empty-subtitle="$t('posReturns.emptyDescription')"
      row-clickable
      @update:per-page="meta.per_page = $event"
      @change-per-page="fetchReturns(1)"
      @change-page="fetchReturns"
      @row-click="(ret) => openReturnDetails(ret.id)"
    >
      <template #toolbar>
        <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('posReturns.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="onSearchDebounced"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Payment Method -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('posReturns.refundMethod') }}</label>
            <select
              v-model="filters.payment_method_id"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="fetchReturns(1)"
            >
              <option value="">{{ $t('posReturns.allRefundMethods') }}</option>
              <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">
                {{ paymentMethodLabel(pm) }}
              </option>
            </select>
          </div>

          <!-- Date From -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
            <input
              v-model="filters.date_from"
              type="date"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="fetchReturns(1)"
            />
          </div>

          <!-- Date To -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
            <input
              v-model="filters.date_to"
              type="date"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="fetchReturns(1)"
            />
          </div>
        </div>
        </FilterToolbar>
      </template>

      <template #empty-icon>
        <RotateCcw class="h-12 w-12 text-[var(--muted)]/50" />
      </template>

      <template #head>
        <tr>
          <th class="px-4 py-3.5 text-start whitespace-nowrap">{{ $t('posReturns.returnNumber') }}</th>
          <th class="px-4 py-3.5 text-start whitespace-nowrap">{{ $t('posReturns.returnedAt') }}</th>
          <th class="px-4 py-3.5 text-start whitespace-nowrap">{{ $t('posReturns.originalInvoice') }}</th>
          <th class="px-4 py-3.5 text-start whitespace-nowrap">{{ $t('posReturns.customer') }}</th>
          <th class="px-4 py-3.5 text-start whitespace-nowrap">{{ $t('posReturns.cashier') }}</th>
          <th class="px-4 py-3.5 text-center whitespace-nowrap">{{ $t('posReturns.refundMethod') }}</th>
          <th class="px-4 py-3.5 text-center whitespace-nowrap">{{ $t('posReturns.shift') }}</th>
          <th class="px-4 py-3.5 text-center whitespace-nowrap">{{ $t('posReturns.returnedItemsCount') }}</th>
          <th class="px-4 py-3.5 text-center whitespace-nowrap">{{ $t('posReturns.totalReturned') }}</th>
          <th class="px-4 py-3.5 text-end whitespace-nowrap">{{ $t('posReturns.actions') }}</th>
        </tr>
      </template>

      <template #row="{ item: ret }">
              <!-- Return Number -->
              <td class="px-4 py-3.5 whitespace-nowrap">
                <button
                  type="button"
                  class="font-mono text-sm font-extrabold text-[var(--text)] hover:text-[var(--crimson)] hover:underline inline-flex items-center gap-1.5 cursor-pointer"
                  @click.stop="openReturnDetails(ret.id)"
                >
                  <RotateCcw class="w-3.5 h-3.5 shrink-0 text-amber-600" />
                  <span>{{ ret.return_number }}</span>
                </button>
              </td>

              <!-- Date & Time -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs text-[var(--muted)]">
                <div class="font-mono text-xs font-semibold text-[var(--text)]">
                  {{ formatDateTime(ret.returned_at, locale) }}
                </div>
              </td>

              <!-- Original Invoice -->
              <td class="px-4 py-3.5 whitespace-nowrap">
                <button
                  v-if="ret.sale"
                  type="button"
                  class="font-mono text-xs font-extrabold text-[var(--text)] hover:text-[var(--crimson)] hover:underline inline-flex items-center gap-1 cursor-pointer"
                  @click.stop="openSaleDetails(ret.sale.id)"
                >
                  <Receipt class="w-3 h-3 shrink-0 text-[var(--muted)]" />
                  <span>{{ ret.sale.invoice_number }}</span>
                </button>
                <span v-else class="text-xs text-[var(--muted)]">—</span>
              </td>

              <!-- Customer -->
              <td class="px-4 py-3.5 whitespace-nowrap">
                <div v-if="ret.sale?.customer" class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)] flex items-center justify-center text-xs font-bold shrink-0">
                    <UserCheck class="w-3.5 h-3.5" />
                  </div>
                  <div>
                    <div class="font-bold text-[var(--text)] text-xs">{{ ret.sale.customer.name }}</div>
                    <div v-if="ret.sale.customer.phone" class="text-[11px] text-[var(--muted)] font-mono">{{ ret.sale.customer.phone }}</div>
                  </div>
                </div>
                <span v-else class="inline-flex items-center gap-1.5 text-xs text-[var(--muted)]">
                  <span class="w-1.5 h-1.5 rounded-full bg-[var(--muted)]/50"></span>
                  {{ $t('posReturns.walkInCustomer') }}
                </span>
              </td>

              <!-- Cashier -->
              <td class="px-4 py-3.5 whitespace-nowrap text-xs font-semibold text-[var(--text)]">
                {{ ret.creator?.name || '—' }}
              </td>

              <!-- Payment Method -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-[var(--bg)] border border-[var(--border)] text-[var(--text)]">
                  <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                  {{ paymentMethodLabel(ret.payment_method) }}
                </span>
              </td>

              <!-- Shift Number -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <button
                  v-if="ret.shift"
                  type="button"
                  class="font-mono text-xs font-bold text-[var(--text)] bg-[var(--bg)] px-2 py-1 rounded-lg border border-[var(--border)] hover:border-[var(--crimson)] hover:text-[var(--crimson)] transition cursor-pointer"
                  @click.stop="openShiftDetails(ret.shift.id)"
                >
                  {{ ret.shift.shift_number }}
                </button>
                <span v-else class="text-xs text-[var(--muted)]">—</span>
              </td>

              <!-- Items Count -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center">
                <span class="inline-flex items-center justify-center min-w-[26px] h-6 px-2 rounded-md text-xs font-extrabold bg-[var(--bg)] text-[var(--text)]">
                  {{ ret.items_count ?? 0 }}
                </span>
              </td>

              <!-- Total Returned -->
              <td class="px-4 py-3.5 whitespace-nowrap text-center font-mono font-extrabold text-[var(--text)]">
                {{ Number(ret.total_returned || 0).toFixed(2) }} <span class="text-[11px] font-sans font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </td>

              <!-- Actions -->
              <td class="px-4 py-3.5 whitespace-nowrap text-end" @click.stop>
                <div class="flex items-center justify-end gap-1.5">
                  <button
                    type="button"
                    class="w-8 h-8 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--muted)] hover:text-[var(--crimson)] hover:border-[var(--crimson)]/40 hover:bg-[var(--crimson-soft)] transition flex items-center justify-center cursor-pointer"
                    :title="$t('posReturns.openReturnDetails')"
                    @click="openReturnDetails(ret.id)"
                  >
                    <Eye class="w-4 h-4" />
                  </button>
                </div>
              </td>
      </template>
    </DataTable>

    <!-- Return Detail Drawer -->
    <PosReturnDetailModal
      v-if="selectedReturnId"
      :return-id="selectedReturnId"
      @close="selectedReturnId = null"
      @open-sale="openSaleDetails"
      @open-shift="openShiftDetails"
      @open-inventory-document="handleOpenInventoryDocumentObj"
    />

    <!-- Sale Detail Drawer (Cross-linked) -->
    <PosSaleDetailModal
      v-if="selectedSaleId"
      :sale-id="selectedSaleId"
      @close="selectedSaleId = null"
      @open-shift="openShiftDetails"
      @open-return="openReturnDetails"
      @open-inventory-document="handleOpenInventoryDocumentObj"
    />

    <!-- Shift Detail Drawer (Cross-linked) -->
    <PosShiftDetailModal
      v-if="selectedShiftId"
      :shift-id="selectedShiftId"
      @close="selectedShiftId = null"
      @open-sale="openSaleDetails"
    />

    <!-- Inventory Document Detail Drawer (Cross-linked) -->
    <InventoryDocumentDetailModal
      v-if="selectedInvDoc"
      :open="!!selectedInvDoc"
      :document="selectedInvDoc"
      @close="selectedInvDoc = null"
      @open-pos-return="openReturnDetails"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  RotateCcw,
  Receipt,
  Store,
  UserCheck,
  Eye,
  ChevronRight,
  ChevronLeft,
  Loader2,
  PackageCheck,
} from 'lucide-vue-next';
import api from '../../../services/api';
import { formatDateTime } from '../../../utils/date';
import FilterToolbar from '../../../components/ui/FilterToolbar.vue';
import DataTable from '../../../components/ui/DataTable.vue';
import PageHeader from '../../../components/ui/PageHeader.vue';
import PosReturnDetailModal from './PosReturnDetailModal.vue';
import PosSaleDetailModal from './PosSaleDetailModal.vue';
import PosShiftDetailModal from './PosShiftDetailModal.vue';
import InventoryDocumentDetailModal from '../../inventory/InventoryDocumentDetailModal.vue';

const { t, te, locale } = useI18n();

function paymentMethodLabel(pm) {
  if (!pm) return '—';
  if (pm.type && te(`pos.paymentMethods.${pm.type}`)) {
    return t(`pos.paymentMethods.${pm.type}`);
  }
  return pm.name || '—';
}

const returns = ref([]);
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
  per_page: 10,
  total: 0,
});

const selectedReturnId = ref(null);
const selectedSaleId = ref(null);
const selectedShiftId = ref(null);
const selectedInvDoc = ref(null);

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.date_from) count++;
  if (filters.date_to) count++;
  if (filters.payment_method_id) count++;
  return count;
});

let searchTimer = null;
function onSearchDebounced() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => fetchReturns(1), 350);
}

function resetFilters() {
  filters.search = '';
  filters.date_from = '';
  filters.date_to = '';
  filters.payment_method_id = '';
  fetchReturns(1);
}

async function fetchReturns(page = 1) {
  loading.value = true;
  try {
    const params = { page, per_page: meta.per_page };
    if (filters.search) params.search = filters.search;
    if (filters.date_from) params.date_from = filters.date_from;
    if (filters.date_to) params.date_to = filters.date_to;
    if (filters.payment_method_id) params.payment_method_id = filters.payment_method_id;

    const res = await api.get('/pos/returns', { params });
    if (res.data?.success) {
      returns.value = res.data.data || [];
      Object.assign(meta, res.data.meta || {});
    }
  } catch (err) {
    console.error('Failed to load POS returns:', err);
  } finally {
    loading.value = false;
  }
}

async function fetchPaymentMethods() {
  try {
    const res = await api.get('/pos/payment-methods');
    if (res.data?.success) {
      paymentMethods.value = res.data.data || [];
    }
  } catch (err) {
    console.error('Failed to load payment methods:', err);
  }
}

function openReturnDetails(returnId) {
  if (!returnId) return;
  selectedInvDoc.value = null;
  selectedReturnId.value = returnId;
}

function openSaleDetails(saleId) {
  if (!saleId) return;
  selectedInvDoc.value = null;
  selectedSaleId.value = saleId;
}

function openShiftDetails(shiftId) {
  if (!shiftId) return;
  selectedShiftId.value = shiftId;
}

function handleOpenInventoryDocumentObj(docOrId) {
  const id = typeof docOrId === 'object' ? docOrId?.id : docOrId;
  if (!id) return;
  handleOpenInventoryDocumentById(id);
}

async function handleOpenInventoryDocumentById(docId) {
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

onMounted(() => {
  fetchPaymentMethods();
  fetchReturns(1);
});
</script>
