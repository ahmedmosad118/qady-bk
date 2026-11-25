<template>
  <div class="space-y-4">
    <!-- Page Header (No Create Button — Customer Receipt creation is not supported yet) -->
    <PageHeader :title="$t('customerReceipts.title')" :subtitle="$t('customerReceipts.subtitle')" />

    <!-- DataTable Card -->
    <DataTable
      :items="receipts"
      :loading="loading"
      :error="loadError"
      :colspan="9"
      :meta="meta"
      :per-page="perPage"
      :empty-title="$t('customerReceipts.emptyTitle')"
      :empty-subtitle="$t('customerReceipts.emptySubtitle')"
      @update:per-page="perPage = $event"
      @change-per-page="loadReceipts(1)"
      @change-page="loadReceipts"
      @retry="loadReceipts(meta.current_page)"
    >
      <template #toolbar>
        <FilterToolbar
          v-model:search="filters.search"
          :search-placeholder="$t('customerReceipts.searchPlaceholder')"
          :active-filters-count="activeFiltersCount"
          @search="debouncedSearch"
          @reset="resetFilters"
        >
          <div class="space-y-4">
            <!-- Status Chips -->
            <div>
              <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.status') }}</label>
              <div class="flex flex-wrap gap-2">
                <FilterChip
                  :label="$t('supplierPayments.allStatuses')"
                  :active="filters.status === ''"
                  @click="setStatusFilter('')"
                />
                <FilterChip
                  :label="$t('supplierPayments.statuses.draft')"
                  :active="filters.status === 'draft'"
                  @click="setStatusFilter('draft')"
                />
                <FilterChip
                  :label="$t('supplierPayments.statuses.posted')"
                  :active="filters.status === 'posted'"
                  @click="setStatusFilter('posted')"
                />
                <FilterChip
                  :label="$t('supplierPayments.statuses.cancelled')"
                  :active="filters.status === 'cancelled'"
                  @click="setStatusFilter('cancelled')"
                />
              </div>
            </div>

            <!-- Customer -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('customerReceipts.customer') }}</label>
              <select
                v-model="filters.contact_id"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                @change="loadReceipts(1)"
              >
                <option :value="null">{{ $t('customerReceipts.allCustomers') }}</option>
                <option v-for="cust in customers" :key="cust.id" :value="cust.id">
                  {{ cust.name }}
                </option>
              </select>
            </div>

            <!-- Payment Method -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.paymentMethod') }}</label>
              <select
                v-model="filters.payment_method_id"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                @change="loadReceipts(1)"
              >
                <option :value="null">{{ $t('supplierPayments.allPaymentMethods') }}</option>
                <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">
                  {{ pm.name }}
                </option>
              </select>
            </div>

            <!-- Date Range -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
                <input
                  v-model="filters.payment_date_from"
                  type="date"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadReceipts(1)"
                />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
                <input
                  v-model="filters.payment_date_to"
                  type="date"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadReceipts(1)"
                />
              </div>
            </div>
          </div>
        </FilterToolbar>
      </template>

      <template #head>
        <tr>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.paymentNumber') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('customerReceipts.customer') }}</th>
          <th class="px-5 py-3.5 text-center">{{ $t('supplierPayments.paymentDate') }}</th>
          <th class="px-5 py-3.5 text-center">{{ $t('supplierPayments.amount') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.paymentMethod') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.journal') }}</th>
          <th class="px-5 py-3.5 text-center">{{ $t('supplierPayments.status') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.createdBy') }}</th>
          <th class="px-5 py-3.5 text-end">{{ $t('supplierPayments.actions') }}</th>
        </tr>
      </template>

      <template #row="{ item: receipt }">
        <td class="px-5 py-4 whitespace-nowrap">
          <button
            type="button"
            class="font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
            @click="openDetailModal(receipt)"
          >
            {{ receipt.payment_number }}
          </button>
        </td>

        <td class="px-5 py-4 font-bold text-[var(--text)]">
          {{ receipt.customer?.name || receipt.contact?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-center font-mono text-xs font-bold text-[var(--text)] whitespace-nowrap">
          {{ formatDateOnly(receipt.payment_date) }}
        </td>

        <td class="px-5 py-4 text-center font-mono text-sm font-black text-[var(--text)] whitespace-nowrap">
          {{ formatMoney(receipt.amount) }}
          <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
        </td>

        <td class="px-5 py-4 text-xs font-semibold text-[var(--text)]">
          {{ receipt.payment_method?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-xs font-semibold text-[var(--muted)]">
          {{ receipt.journal?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-center">
          <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
            :class="statusBadgeClass(receipt.status)"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
            {{ $t(`supplierPayments.statuses.${receipt.status}`) }}
          </span>
        </td>

        <td class="px-5 py-4 text-xs text-[var(--muted)]">
          {{ receipt.creator?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-end">
          <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            :title="$t('supplierPayments.view')"
            @click="openDetailModal(receipt)"
          >
            <Eye class="h-4 w-4" />
          </button>
        </td>
      </template>
    </DataTable>

    <!-- Shared Read-Only Details Drawer (lifecycle actions omitted since Phase 7 API supports supplier payments only) -->
    <SupplierPaymentDetailModal
      :open="detailOpen"
      :payment="selectedReceipt"
      :read-only="true"
      @close="detailOpen = false"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Eye } from 'lucide-vue-next';
import DataTable from '../../components/ui/DataTable.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SupplierPaymentDetailModal from '../purchases/SupplierPaymentDetailModal.vue';
import api from '../../services/api';
import { fetchSupplierPayments } from '../../services/supplierPaymentsApi';
import { formatDateOnly } from '../../utils/date';

const { t, locale } = useI18n();

const receipts = ref([]);
const customers = ref([]);
const paymentMethods = ref([]);

const loading = ref(false);
const loadError = ref('');
const perPage = ref(15);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });

const filters = ref({
  search: '',
  status: '',
  contact_id: null,
  payment_method_id: null,
  payment_date_from: '',
  payment_date_to: '',
});

const detailOpen = ref(false);
const selectedReceipt = ref(null);
let searchTimer = null;

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.value.status) count += 1;
  if (filters.value.contact_id) count += 1;
  if (filters.value.payment_method_id) count += 1;
  if (filters.value.payment_date_from) count += 1;
  if (filters.value.payment_date_to) count += 1;
  return count;
});

const formatMoney = (val) => {
  const loc = locale.value === 'ar' ? 'ar-EG' : 'en-US';
  return Number(val || 0).toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const statusBadgeClass = (status) => {
  if (status === 'posted') return 'bg-[var(--success-soft)] text-[var(--success)]';
  if (status === 'cancelled') return 'bg-[var(--crimson-soft)] text-[var(--crimson)]';
  return 'bg-[var(--gold-soft)] text-[var(--gold-strong)]';
};

const loadLookups = async () => {
  try {
    const [custRes, pmRes] = await Promise.all([
      api.get('/contacts', { params: { role: 'customer', is_active: 1, per_page: 100 } }),
      api.get('/payment-methods'),
    ]);
    customers.value = custRes.data?.data ?? [];
    paymentMethods.value = (pmRes.data?.data ?? []).filter((pm) => pm.is_active);
  } catch {
    // Non-blocking
  }
};

const loadReceipts = async (page = 1) => {
  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      page,
      per_page: perPage.value,
      payment_type: 'receive',
      related_type: 'customer',
      ...(filters.value.search.trim() ? { search: filters.value.search.trim() } : {}),
      ...(filters.value.status ? { status: filters.value.status } : {}),
      ...(filters.value.contact_id ? { contact_id: filters.value.contact_id } : {}),
      ...(filters.value.payment_method_id ? { payment_method_id: filters.value.payment_method_id } : {}),
      ...(filters.value.payment_date_from ? { payment_date_from: filters.value.payment_date_from } : {}),
      ...(filters.value.payment_date_to ? { payment_date_to: filters.value.payment_date_to } : {}),
    };

    const res = await fetchSupplierPayments(params);
    // Keep strictly customer receipts (receive + customer) so supplier payments never leak into this screen
    const rows = (res.data?.data ?? []).filter(
      (r) => r.payment_type === 'receive' && r.related_type === 'customer'
    );

    receipts.value = rows;
    meta.value = rows.length === 0
      ? { current_page: 1, last_page: 1, per_page: perPage.value, total: 0 }
      : (res.data?.meta ?? { current_page: page, last_page: 1, per_page: perPage.value, total: rows.length });
  } catch (err) {
    loadError.value = err.response?.data?.message || t('customerReceipts.loadError');
  } finally {
    loading.value = false;
  }
};

const debouncedSearch = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadReceipts(1), 300);
};

const setStatusFilter = (status) => {
  filters.value.status = status;
  loadReceipts(1);
};

const resetFilters = () => {
  filters.value = {
    search: '',
    status: '',
    contact_id: null,
    payment_method_id: null,
    payment_date_from: '',
    payment_date_to: '',
  };
  loadReceipts(1);
};

const openDetailModal = (receipt) => {
  selectedReceipt.value = receipt;
  detailOpen.value = true;
};

onMounted(() => {
  loadLookups();
  loadReceipts(1);
});
</script>
