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

            <!-- Contact (Customer or Supplier) -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.relatedParty') }}</label>
              <select
                v-model="filters.contact_id"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                @change="loadPayments(1)"
              >
                <option :value="null">{{ $t('allPayments.allParties') }}</option>
                <option v-for="c in contacts" :key="c.id" :value="c.id">
                  {{ c.name }}
                </option>
              </select>
            </div>

            <!-- Payment Method & Account -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.paymentMethod') }}</label>
                <select
                  v-model="filters.payment_method_id"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                >
                  <option :value="null">{{ $t('supplierPayments.allPaymentMethods') }}</option>
                  <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">
                    {{ pm.name }}
                  </option>
                </select>
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.account') }}</label>
                <select
                  v-model="filters.account_id"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                >
                  <option :value="null">{{ $t('allPayments.allAccounts') }}</option>
                  <option v-for="acc in accounts" :key="acc.id" :value="acc.id">
                    {{ acc.name }}
                  </option>
                </select>
              </div>
            </div>

            <!-- Date Range -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
                <input
                  v-model="filters.payment_date_from"
                  type="date"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                />
              </div>
              <div>
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
                <input
                  v-model="filters.payment_date_to"
                  type="date"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                />
              </div>
            </div>
          </div>
        </FilterToolbar>
      </template>

      <template #head>
        <tr>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.paymentNumber') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.paymentType') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.relatedType') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.relatedParty') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.paymentDate') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.amount') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.paymentMethod') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.account') }}</th>
          <th class="px-4 py-3.5 text-center">{{ $t('supplierPayments.status') }}</th>
          <th class="px-4 py-3.5 text-start">{{ $t('supplierPayments.createdBy') }}</th>
          <th class="px-4 py-3.5 text-end">{{ $t('supplierPayments.actions') }}</th>
        </tr>
      </template>

      <template #row="{ item: payment }">
        <td class="px-4 py-4 whitespace-nowrap">
          <button
            type="button"
            class="font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
            @click="openDetailModal(payment)"
          >
            {{ payment.payment_number }}
          </button>
        </td>

        <td class="px-4 py-4 text-center">
          <span class="inline-flex rounded-md border border-[var(--border)] bg-[var(--bg)] px-2 py-0.5 text-xs font-bold text-[var(--text)]">
            {{ payment.payment_type ? $t(`supplierPayments.paymentTypes.${payment.payment_type}`) : '—' }}
          </span>
        </td>

        <td class="px-4 py-4 text-center text-xs text-[var(--muted)]">
          {{ payment.related_type ? $t(`supplierPayments.relatedTypes.${payment.related_type}`) : '—' }}
        </td>

        <td class="px-4 py-4 font-bold text-[var(--text)]">
          {{ payment.supplier?.name || payment.customer?.name || payment.contact?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-center font-mono text-xs font-bold text-[var(--text)] whitespace-nowrap">
          {{ formatDateOnly(payment.payment_date) }}
        </td>

        <td class="px-4 py-4 text-center font-mono text-sm font-black text-[var(--text)] whitespace-nowrap">
          {{ formatMoney(payment.amount) }}
          <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
        </td>

        <td class="px-4 py-4 text-xs font-semibold text-[var(--text)]">
          {{ payment.payment_method?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-xs text-[var(--muted)]">
          {{ payment.account?.name || '—' }}
        </td>

        <td class="px-4 py-4 text-center">
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

    <!-- Shared Read-Only Details Drawer -->
    <SupplierPaymentDetailModal
      :open="detailOpen"
      :payment="selectedPayment"
      :read-only="true"
      @close="detailOpen = false"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { ExternalLink, Eye } from 'lucide-vue-next';
import DataTable from '../../components/ui/DataTable.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SupplierPaymentDetailModal from '../purchases/SupplierPaymentDetailModal.vue';
import api from '../../services/api';
import { fetchSupplierPayments } from '../../services/supplierPaymentsApi';
import { formatDateOnly } from '../../utils/date';

const { t, locale } = useI18n();

const payments = ref([]);
const contacts = ref([]);
const paymentMethods = ref([]);
const accounts = ref([]);

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

const loadLookups = async () => {
  try {
    const [contactRes, pmRes, accRes] = await Promise.all([
      api.get('/contacts', { params: { is_active: 1, per_page: 100 } }),
      api.get('/payment-methods'),
      api.get('/accounting/accounts', { params: { is_active: 1, per_page: 100 } }),
    ]);

    contacts.value = contactRes.data?.data ?? [];
    paymentMethods.value = (pmRes.data?.data ?? []).filter((pm) => pm.is_active);
    accounts.value = accRes.data?.data ?? [];
  } catch {
    // Non-blocking
  }
};

const loadPayments = async (page = 1) => {
  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      page,
      per_page: perPage.value,
      ...(filters.value.search.trim() ? { search: filters.value.search.trim() } : {}),
      ...(filters.value.payment_type ? { payment_type: filters.value.payment_type } : {}),
      ...(filters.value.related_type ? { related_type: filters.value.related_type } : {}),
      ...(filters.value.status ? { status: filters.value.status } : {}),
      ...(filters.value.contact_id ? { contact_id: filters.value.contact_id } : {}),
      ...(filters.value.payment_method_id ? { payment_method_id: filters.value.payment_method_id } : {}),
      ...(filters.value.account_id ? { account_id: filters.value.account_id } : {}),
      ...(filters.value.payment_date_from ? { payment_date_from: filters.value.payment_date_from } : {}),
      ...(filters.value.payment_date_to ? { payment_date_to: filters.value.payment_date_to } : {}),
    };

    const res = await fetchSupplierPayments(params);
    let rows = res.data?.data ?? [];

    // Ensure client-side consistency if payment_type/related_type filter is set
    if (filters.value.payment_type) {
      rows = rows.filter((r) => r.payment_type === filters.value.payment_type);
    }
    if (filters.value.related_type) {
      rows = rows.filter((r) => r.related_type === filters.value.related_type);
    }

    payments.value = rows;
    meta.value = res.data?.meta ?? { current_page: page, last_page: 1, per_page: perPage.value, total: rows.length };
  } catch (err) {
    loadError.value = err.response?.data?.message || t('allPayments.loadError');
  } finally {
    loading.value = false;
  }
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

onMounted(() => {
  loadLookups();
  loadPayments(1);
});
</script>
