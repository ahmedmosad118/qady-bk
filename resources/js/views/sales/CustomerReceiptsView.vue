<template>
  <div class="space-y-4">
    <!-- Page Header (No Create Button — Customer Receipt creation is not supported yet) -->
    <PageHeader :title="$t('customerReceipts.title')" :subtitle="$t('customerReceipts.subtitle')" />

    <!-- Backend Customer Receipts Endpoint Notice -->
    <div
      class="flex items-start gap-3 rounded-2xl border border-[var(--gold)]/35 bg-[var(--gold-soft)] px-4 py-3.5 text-xs text-[var(--text)]"
      role="status"
    >
      <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-[var(--gold-strong)]" aria-hidden="true" />
      <div>
        <p class="font-bold text-[var(--gold-strong)]">{{ $t('customerReceipts.apiUnavailableTitle') }}</p>
        <p class="mt-0.5 text-[var(--muted)]">{{ $t('customerReceipts.apiUnavailableDesc') }}</p>
      </div>
    </div>

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
              <SearchableSelect
                v-model="filters.contact_id"
                :fetch-options="fetchCustomerOptions"
                :paginate="15"
                :placeholder="$t('customerReceipts.allCustomers')"
                :search-placeholder="$t('supplierPayments.searchCustomers')"
                @change="loadReceipts(1)"
              />
            </div>

            <!-- Payment Method -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.paymentMethod') }}</label>
              <SearchableSelect
                v-model="filters.payment_method_id"
                :fetch-options="fetchPaymentMethodOptions"
                :placeholder="$t('supplierPayments.allPaymentMethods')"
                :search-placeholder="$t('supplierPayments.searchPaymentMethods')"
                @change="loadReceipts(1)"
              />
            </div>

            <!-- Date Range -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
                <input
                  v-model="filters.payment_date_from"
                  type="date"
                  class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadReceipts(1)"
                />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
                <input
                  v-model="filters.payment_date_to"
                  type="date"
                  class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadReceipts(1)"
                />
              </div>
            </div>
          </div>
        </FilterToolbar>
      </template>

      <template #header>
        <tr>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.paymentNumber') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('customerReceipts.customer') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.paymentDate') }}</th>
          <th class="px-4 py-3.5 text-end">{{ $t('supplierPayments.amount') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.paymentMethod') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.journal') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.status') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.createdBy') }}</th>
          <th class="px-4 py-3.5 text-end">{{ $t('common.actions') }}</th>
        </tr>
      </template>

      <template #row="{ item: receipt }">
        <td class="px-4 py-4 font-mono text-sm font-bold whitespace-nowrap text-[var(--text)]">
          {{ receipt.payment_number }}
        </td>

        <td class="px-4 py-4 text-sm font-semibold text-[var(--text)]">
          {{ receipt.customer?.name || receipt.contact?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-center font-mono text-xs text-[var(--muted)] whitespace-nowrap">
          {{ formatDateOnly(receipt.payment_date) }}
        </td>

        <td class="px-4 py-4 text-end font-mono text-sm font-black text-[var(--text)] whitespace-nowrap">
          {{ formatMoney(receipt.amount) }} {{ $t('common.currency') }}
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ receipt.payment_method?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ receipt.journal?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-center whitespace-nowrap">
          <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
            :class="statusBadgeClass(receipt.status)"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
            {{ $t(`supplierPayments.statuses.${receipt.status}`) }}
          </span>
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ receipt.creator?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-end">
          <span class="text-xs text-[var(--muted)]">—</span>
        </td>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle } from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import api from '../../services/api';
import { formatDateOnly } from '../../utils/date';
import { createContactFetcher } from '../../utils/selectFetchers';

const { locale } = useI18n();

const receipts = ref([]);
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

let searchTimer = null;

const fetchCustomerOptions = createContactFetcher({ isCustomer: true, activeOnly: true });
const fetchPaymentMethodOptions = async ({ search = '' } = {}) => {
  const res = await api.get('/payment-methods');
  const rows = Array.isArray(res.data?.data) ? res.data.data : [];
  const active = rows.filter((pm) => pm.is_active !== false && pm.is_active !== 0);
  const query = search.trim().toLowerCase();
  const filtered = query
    ? active.filter((pm) => String(pm.name || '').toLowerCase().includes(query))
    : active;
  const options = filtered.map((pm) => ({ value: pm.id, label: pm.name, raw: pm }));
  return { options, hasMore: false, total: options.length, currentPage: 1, lastPage: 1 };
};

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

// Customer Receipts API (GET /api/customer-receipts) is not yet implemented in Phase 7.
// Do NOT call GET /api/supplier-payments and filter locally.
const loadReceipts = async (page = 1) => {
  receipts.value = [];
  meta.value = { current_page: page, last_page: 1, per_page: perPage.value, total: 0 };
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
</script>
