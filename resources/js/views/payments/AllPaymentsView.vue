<template>
  <div class="space-y-4">
    <!-- Page Header (Read-Only Monitoring — No Create Button) -->
    <PageHeader :title="$t('allPayments.title')" :subtitle="$t('allPayments.subtitle')">
      <template #actions>
        <router-link
          to="/supplier-payments"
          class="inline-flex h-10 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-xs font-bold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 hover:text-[var(--crimson)]"
        >
          <span>{{ $t('allPayments.goToSupplierPayments') }}</span>
          <ExternalLink class="h-3.5 w-3.5" aria-hidden="true" />
        </router-link>
      </template>
    </PageHeader>

    <!-- Backend Unified Endpoint Notice -->
    <div
      class="flex items-start gap-3 rounded-2xl border border-[var(--gold)]/35 bg-[var(--gold-soft)] px-4 py-3.5 text-xs text-[var(--text)]"
      role="status"
    >
      <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-[var(--gold-strong)]" aria-hidden="true" />
      <div>
        <p class="font-bold text-[var(--gold-strong)]">{{ $t('allPayments.apiUnavailableTitle') }}</p>
        <p class="mt-0.5 text-[var(--muted)]">{{ $t('allPayments.apiUnavailableDesc') }}</p>
      </div>
    </div>

    <!-- DataTable Card -->
    <DataTable
      :items="payments"
      :loading="loading"
      :error="loadError"
      :colspan="11"
      :meta="meta"
      :per-page="perPage"
      :empty-title="$t('allPayments.emptyTitle')"
      :empty-subtitle="$t('allPayments.emptySubtitle')"
      @update:per-page="perPage = $event"
      @change-per-page="loadPayments(1)"
      @change-page="loadPayments"
      @retry="loadPayments(meta.current_page)"
    >
      <template #toolbar>
        <FilterToolbar
          v-model:search="filters.search"
          :search-placeholder="$t('allPayments.searchPlaceholder')"
          :active-filters-count="activeFiltersCount"
          @search="debouncedSearch"
          @reset="resetFilters"
        >
          <div class="space-y-4">
            <!-- Payment Type -->
            <div>
              <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.paymentType') }}</label>
              <div class="flex flex-wrap gap-2">
                <FilterChip
                  :label="$t('allPayments.allPaymentTypes')"
                  :active="filters.payment_type === ''"
                  @click="setFilter('payment_type', '')"
                />
                <FilterChip
                  :label="$t('supplierPayments.paymentTypes.send')"
                  :active="filters.payment_type === 'send'"
                  @click="setFilter('payment_type', 'send')"
                />
                <FilterChip
                  :label="$t('supplierPayments.paymentTypes.receive')"
                  :active="filters.payment_type === 'receive'"
                  @click="setFilter('payment_type', 'receive')"
                />
                <FilterChip
                  :label="$t('supplierPayments.paymentTypes.transfer')"
                  :active="filters.payment_type === 'transfer'"
                  @click="setFilter('payment_type', 'transfer')"
                />
              </div>
            </div>

            <!-- Related Party Type -->
            <div>
              <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.relatedType') }}</label>
              <div class="flex flex-wrap gap-2">
                <FilterChip
                  :label="$t('allPayments.allRelatedTypes')"
                  :active="filters.related_type === ''"
                  @click="setFilter('related_type', '')"
                />
                <FilterChip
                  :label="$t('supplierPayments.relatedTypes.supplier')"
                  :active="filters.related_type === 'supplier'"
                  @click="setFilter('related_type', 'supplier')"
                />
                <FilterChip
                  :label="$t('supplierPayments.relatedTypes.customer')"
                  :active="filters.related_type === 'customer'"
                  @click="setFilter('related_type', 'customer')"
                />
              </div>
            </div>

            <!-- Status Chips -->
            <div>
              <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.status') }}</label>
              <div class="flex flex-wrap gap-2">
                <FilterChip
                  :label="$t('supplierPayments.allStatuses')"
                  :active="filters.status === ''"
                  @click="setFilter('status', '')"
                />
                <FilterChip
                  :label="$t('supplierPayments.statuses.draft')"
                  :active="filters.status === 'draft'"
                  @click="setFilter('status', 'draft')"
                />
                <FilterChip
                  :label="$t('supplierPayments.statuses.posted')"
                  :active="filters.status === 'posted'"
                  @click="setFilter('status', 'posted')"
                />
                <FilterChip
                  :label="$t('supplierPayments.statuses.cancelled')"
                  :active="filters.status === 'cancelled'"
                  @click="setFilter('status', 'cancelled')"
                />
              </div>
            </div>

            <!-- Contact / Party -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.relatedParty') }}</label>
              <SearchableSelect
                v-model="filters.contact_id"
                :fetch-options="fetchContactOptions"
                :paginate="15"
                :placeholder="$t('allPayments.allParties')"
                @change="loadPayments(1)"
              />
            </div>

            <!-- Payment Method & Account -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.paymentMethod') }}</label>
                <SearchableSelect
                  v-model="filters.payment_method_id"
                  :fetch-options="fetchPaymentMethodOptions"
                  :placeholder="$t('supplierPayments.allPaymentMethods')"
                  @change="loadPayments(1)"
                />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.account') }}</label>
                <SearchableSelect
                  v-model="filters.account_id"
                  :fetch-options="fetchAccountOptions"
                  :paginate="15"
                  :placeholder="$t('allPayments.allAccounts')"
                  @change="loadPayments(1)"
                />
              </div>
            </div>

            <!-- Date Range -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
                <input
                  v-model="filters.payment_date_from"
                  type="date"
                  class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
                <input
                  v-model="filters.payment_date_to"
                  type="date"
                  class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                />
              </div>
            </div>
          </div>
        </FilterToolbar>
      </template>

      <template #header>
        <tr>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.paymentNumber') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.paymentType') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.relatedType') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.relatedParty') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.paymentDate') }}</th>
          <th class="px-4 py-3.5 text-end">{{ $t('supplierPayments.amount') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.paymentMethod') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.account') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.status') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.createdBy') }}</th>
          <th class="px-4 py-3.5 text-end">{{ $t('common.actions') }}</th>
        </tr>
      </template>

      <template #row="{ item: payment }">
        <td class="px-4 py-4 font-mono text-sm font-bold whitespace-nowrap">
          <button
            type="button"
            class="text-[var(--text)] hover:text-[var(--crimson)] transition-colors cursor-pointer"
            @click="openDetailModal(payment)"
          >
            {{ payment.payment_number }}
          </button>
        </td>

        <td class="px-4 py-4 text-center whitespace-nowrap">
          <span
            v-if="payment.payment_type"
            class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 text-xs font-bold text-[var(--text)]"
          >
            {{ $t(`supplierPayments.paymentTypes.${payment.payment_type}`) }}
          </span>
          <span v-else class="text-xs text-[var(--muted)]">—</span>
        </td>

        <td class="px-4 py-4 text-center whitespace-nowrap text-xs text-[var(--muted)]">
          {{ payment.related_type ? $t(`supplierPayments.relatedTypes.${payment.related_type}`) : '—' }}
        </td>

        <td class="px-4 py-4 text-sm font-semibold text-[var(--text)]">
          {{ payment.supplier?.name || payment.customer?.name || payment.contact?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-center font-mono text-xs text-[var(--muted)] whitespace-nowrap">
          {{ formatDateOnly(payment.payment_date) }}
        </td>

        <td class="px-4 py-4 text-end font-mono text-sm font-black text-[var(--text)] whitespace-nowrap">
          {{ formatMoney(payment.amount) }} {{ $t('common.currency') }}
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ payment.payment_method?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ payment.account?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-center whitespace-nowrap">
          <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
            :class="statusBadgeClass(payment.status)"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
            {{ $t(`supplierPayments.statuses.${payment.status}`) }}
          </span>
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ payment.creator?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-end">
          <div class="inline-flex items-center justify-end gap-1">
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
              :title="$t('supplierPayments.view')"
              @click="openDetailModal(payment)"
            >
              <Eye class="h-4 w-4" />
            </button>
          </div>
        </td>
      </template>
    </DataTable>

    <!-- Shared Read-Only Details Modal -->
    <SupplierPaymentDetailModal
      :open="detailOpen"
      :payment="selectedPayment"
      :read-only="true"
      @close="detailOpen = false"
    />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, ExternalLink, Eye } from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SupplierPaymentDetailModal from '../purchases/SupplierPaymentDetailModal.vue';
import api from '../../services/api';
import { formatDateOnly } from '../../utils/date';
import { createContactFetcher, createPaginatedFetcher } from '../../utils/selectFetchers';

const { locale } = useI18n();

const payments = ref([]);
const loading = ref(false);
const loadError = ref('');
const perPage = ref(15);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });

const filters = ref({
  search: '',
  payment_type: '',
  related_type: '',
  status: '',
  contact_id: null,
  payment_method_id: null,
  account_id: null,
  payment_date_from: '',
  payment_date_to: '',
});

const detailOpen = ref(false);
const selectedPayment = ref(null);
let searchTimer = null;

const fetchContactOptions = createContactFetcher({ activeOnly: true });
const fetchAccountOptions = createPaginatedFetcher(
  '/accounting/accounts',
  () => ({ is_active: 1 }),
  (acc) => ({
    value: acc.id,
    label: acc.code ? `${acc.code} — ${acc.name}` : acc.name,
    raw: acc,
  })
);
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
  if (filters.value.payment_type) count += 1;
  if (filters.value.related_type) count += 1;
  if (filters.value.status) count += 1;
  if (filters.value.contact_id) count += 1;
  if (filters.value.payment_method_id) count += 1;
  if (filters.value.account_id) count += 1;
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

// Unified GET /api/payments endpoint is not yet implemented in Phase 7.
// Do NOT call GET /api/supplier-payments to simulate unified results.
const loadPayments = async (page = 1) => {
  payments.value = [];
  meta.value = { current_page: page, last_page: 1, per_page: perPage.value, total: 0 };
};

const debouncedSearch = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadPayments(1), 300);
};

const setFilter = (key, val) => {
  filters.value[key] = val;
  loadPayments(1);
};

const resetFilters = () => {
  filters.value = {
    search: '',
    payment_type: '',
    related_type: '',
    status: '',
    contact_id: null,
    payment_method_id: null,
    account_id: null,
    payment_date_from: '',
    payment_date_to: '',
  };
  loadPayments(1);
};

const openDetailModal = (payment) => {
  selectedPayment.value = payment;
  detailOpen.value = true;
};
</script>
