<template>
  <DataTable
    :items="sales"
    :loading="loading"
    :colspan="colspan"
    :meta="meta"
    :per-page="currentPerPage"
    :loading-text="$t('pos.salesPage.loadingSales')"
    :empty-title="$t('pos.salesPage.noSalesTitle')"
    :empty-subtitle="$t('pos.salesPage.noSalesDesc')"
    row-clickable
    @update:per-page="currentPerPage = $event"
    @change-per-page="fetchSales(1)"
    @change-page="fetchSales"
    @row-click="(sale) => emit('open-sale', sale.id, 'info')"
  >
    <template #toolbar>
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
      v-model:search="filters.search"
      :search-placeholder="$t('pos.salesPage.searchPlaceholder')"
      :active-filters-count="activeFiltersCount"
      @search="onSearchDebounced"
      @reset="resetFilters"
    >
      <div class="space-y-4">
        <!-- Payment Method -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('pos.salesPage.paymentMethod') }}</label>
          <select
            v-model="filters.payment_method_id"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            @change="fetchSales(1)"
          >
            <option value="">{{ $t('pos.salesPage.allPaymentMethods') }}</option>
            <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">
              {{ paymentMethodLabel(pm) }}
            </option>
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
              @change="fetchSales(1)"
            />
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
            <input
              v-model="filters.date_to"
              type="date"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="fetchSales(1)"
            />
          </div>
        </div>
        </div>
      </FilterToolbar>
    </template>

    <template #empty-icon>
      <Receipt class="h-12 w-12 text-[var(--muted)]/50" />
    </template>

    <template #head>
      <tr>
        <th class="px-4 py-3.5 text-start">{{ $t('pos.salesPage.invoiceNumber') }}</th>
        <th class="px-4 py-3.5 text-start">{{ $t('pos.salesPage.dateTime') }}</th>
        <th class="px-4 py-3.5 text-start">{{ $t('pos.salesPage.customer') }}</th>
        <th class="px-4 py-3.5 text-start">{{ $t('pos.salesPage.cashier') }}</th>
        <th class="px-4 py-3.5 text-center">{{ $t('pos.salesPage.paymentMethod') }}</th>
        <th v-if="!shiftId" class="px-4 py-3.5 text-center">{{ $t('pos.salesPage.shift') }}</th>
        <th class="px-4 py-3.5 text-center">{{ $t('pos.salesPage.items') }}</th>
        <th class="px-4 py-3.5 text-center">{{ $t('pos.salesPage.grandTotal') }}</th>
        <th class="px-4 py-3.5 text-center">{{ $t('pos.salesPage.returns') }}</th>
        <th class="px-4 py-3.5 text-end">{{ $t('pos.salesPage.actions') }}</th>
      </tr>
    </template>

    <template #row="{ item: sale }">
            <!-- Invoice Number -->
            <td class="px-4 py-3.5 whitespace-nowrap">
              <button
                type="button"
                class="font-mono text-sm font-extrabold text-[var(--text)] hover:text-[var(--crimson)] hover:underline inline-flex items-center gap-1.5 cursor-pointer"
                @click.stop="emit('open-sale', sale.id, 'info')"
              >
                <Receipt class="w-3.5 h-3.5 shrink-0 text-[var(--muted)]" />
                <span>{{ sale.invoice_number }}</span>
              </button>
            </td>

            <!-- Date and Time -->
            <td class="px-4 py-3.5 whitespace-nowrap text-xs text-[var(--muted)]">
              <div class="font-mono text-xs font-semibold text-[var(--text)]">
                {{ formatDateTime(sale.sold_at || sale.created_at, locale) }}
              </div>
            </td>

            <!-- Customer -->
            <td class="px-4 py-3.5 whitespace-nowrap">
              <div v-if="sale.customer" class="flex flex-col">
                <span class="text-xs font-bold text-[var(--text)]">{{ sale.customer.name }}</span>
                <span v-if="sale.customer.phone" class="text-[11px] font-mono text-[var(--muted)]">
                  {{ sale.customer.phone }}
                </span>
              </div>
              <span
                v-else
                class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-[var(--bar)] text-[var(--muted)]"
              >
                {{ $t('pos.walkInCustomer') }}
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
                {{ paymentMethodLabel(sale.payment_method) }}
              </span>
            </td>

            <!-- Shift -->
            <td v-if="!shiftId" class="px-4 py-3.5 whitespace-nowrap text-center">
              <button
                v-if="sale.shift || sale.pos_shift_id"
                type="button"
                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-mono font-bold border border-[var(--border)] hover:bg-[var(--bg)] transition cursor-pointer"
                :title="$t('pos.salesPage.viewShiftTooltip')"
                @click.stop="emit('open-shift', sale.pos_shift_id || sale.shift?.id)"
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
            <td class="px-4 py-3.5 whitespace-nowrap text-center">
              <b class="text-sm font-black text-[var(--text)] font-mono">
                {{ Number(sale.grand_total || 0).toFixed(2) }}
                <span class="text-xs font-sans font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </b>
            </td>

            <!-- Returns Status -->
            <td class="px-4 py-3.5 whitespace-nowrap text-center">
              <button
                v-if="Number(sale.returns_count || 0) > 0"
                type="button"
                class="inline-flex flex-col items-center gap-0.5 rounded-xl border px-2.5 py-1 transition cursor-pointer"
                :class="isFullReturn(sale)
                  ? 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-400 hover:bg-red-500/20'
                  : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400 hover:bg-amber-500/20'"
                :title="$t('pos.salesPage.viewReturnsTooltip')"
                @click.stop="emit('open-sale', sale.id, 'returns')"
              >
                <span class="inline-flex items-center gap-1 text-[11px] font-extrabold">
                  <RotateCcw class="w-3 h-3" />
                  <span>{{ isFullReturn(sale) ? $t('pos.salesPage.fullReturn') : $t('pos.salesPage.partialReturn') }}</span>
                </span>
                <span class="font-mono text-[10px] font-bold">
                  -{{ Number(sale.returns_total_amount || 0).toFixed(2) }} {{ $t('common.currency') }} ({{ sale.returns_count }})
                </span>
              </button>
              <span v-else class="text-xs text-[var(--muted)]">—</span>
            </td>

            <!-- Actions -->
            <td class="px-4 py-3.5 whitespace-nowrap text-end" @click.stop>
              <div class="flex items-center justify-end gap-1.5">
                <!-- Print Thermal Receipt -->
                <button
                  type="button"
                  class="h-8 px-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-xs cursor-pointer"
                  :title="$t('pos.salesPage.printReceiptTooltip')"
                  @click="emit('print', sale)"
                >
                  <Printer class="w-3.5 h-3.5" />
                  <span class="hidden sm:inline">{{ $t('pos.salesPage.printBtn') }}</span>
                </button>

                <!-- View Details -->
                <button
                  type="button"
                  class="h-8 px-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] hover:bg-[var(--bg)] text-xs font-bold text-[var(--text)] transition flex items-center gap-1.5 cursor-pointer"
                  :title="$t('pos.salesPage.viewDetailsTooltip')"
                  @click="emit('open-sale', sale.id, 'info')"
                >
                  <Eye class="w-3.5 h-3.5 text-[var(--muted)]" />
                  <span class="hidden sm:inline">{{ $t('pos.salesPage.detailsBtn') }}</span>
                </button>
              </div>
            </td>
    </template>
  </DataTable>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Receipt,
  Clock,
  UserCheck,
  Eye,
  Printer,
  RotateCcw,
} from 'lucide-vue-next';
import api from '../../../services/api';
import { formatDateTime } from '../../../utils/date';
import DataTable from '../../../components/ui/DataTable.vue';
import FilterToolbar from '../../../components/ui/FilterToolbar.vue';

const { t, te, locale } = useI18n();

const props = defineProps({
  shiftId: {
    type: [Number, String],
    default: null,
  },
  perPage: {
    type: Number,
    default: 10,
  },
});

const emit = defineEmits(['open-sale', 'open-shift', 'print', 'total-updated']);

const sales = ref([]);
const loading = ref(true);
const paymentMethods = ref([]);
const currentPerPage = ref(props.perPage);

const filters = reactive({
  search: '',
  date_from: '',
  date_to: '',
  payment_method_id: '',
});

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: props.perPage,
  total: 0,
});

const colspan = computed(() => (props.shiftId ? 9 : 10));

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
  searchTimer = setTimeout(() => {
    fetchSales(1);
  }, 350);
}

function resetFilters() {
  filters.search = '';
  filters.date_from = '';
  filters.date_to = '';
  filters.payment_method_id = '';
  fetchSales(1);
}

async function fetchSales(page = 1) {
  loading.value = true;
  try {
    const params = {
      page,
      per_page: currentPerPage.value,
      search: filters.search || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      payment_method_id: filters.payment_method_id || undefined,
      shift_id: props.shiftId || undefined,
    };

    const res = await api.get('/pos/sales', { params });
    if (res.data?.success) {
      sales.value = res.data.data || [];
      if (res.data.meta) {
        meta.current_page = res.data.meta.current_page;
        meta.last_page = res.data.meta.last_page;
        meta.total = res.data.meta.total;
        emit('total-updated', res.data.meta.total);
      }
    }
  } catch (err) {
    console.error('Failed to load POS sales:', err);
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

function paymentMethodLabel(pm) {
  if (!pm) return t('pos.paymentMethods.cash');
  if (pm.type && te(`pos.paymentMethods.${pm.type}`)) {
    return t(`pos.paymentMethods.${pm.type}`);
  }
  return pm.name || t('pos.paymentMethods.cash');
}

function isFullReturn(sale) {
  const grandTotal = Number(sale?.grand_total || 0);
  const returned = Number(sale?.returns_total_amount || 0);
  return returned > 0 && returned >= grandTotal - 0.01;
}

watch(() => props.shiftId, () => {
  fetchSales(1);
});

onMounted(() => {
  fetchPaymentMethods();
  fetchSales(1);
});
</script>
