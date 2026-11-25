<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('supplierPayments.title')" :subtitle="$t('supplierPayments.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('supplierPayments.create') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- DataTable Card -->
    <DataTable
      :items="payments"
      :loading="loading"
      :error="loadError"
      :colspan="9"
      :meta="meta"
      :per-page="perPage"
      :empty-title="$t('supplierPayments.emptyTitle')"
      :empty-subtitle="$t('supplierPayments.emptySubtitle')"
      @update:per-page="perPage = $event"
      @change-per-page="loadPayments(1)"
      @change-page="loadPayments"
      @retry="loadPayments(meta.current_page)"
    >
      <template #toolbar>
        <FilterToolbar
          v-model:search="filters.search"
          :search-placeholder="$t('supplierPayments.searchPlaceholder')"
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

            <!-- Supplier -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.supplier') }}</label>
              <select
                v-model="filters.contact_id"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                @change="loadPayments(1)"
              >
                <option :value="null">{{ $t('supplierPayments.allSuppliers') }}</option>
                <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
                  {{ sup.name }}
                </option>
              </select>
            </div>

            <!-- Payment Method & Journal -->
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
                <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('supplierPayments.journal') }}</label>
                <select
                  v-model="filters.journal_id"
                  class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  @change="loadPayments(1)"
                >
                  <option :value="null">{{ $t('supplierPayments.allJournals') }}</option>
                  <option v-for="j in journals" :key="j.id" :value="j.id">
                    {{ j.name }}
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
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.paymentNumber') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.supplier') }}</th>
          <th class="px-5 py-3.5 text-center">{{ $t('supplierPayments.paymentDate') }}</th>
          <th class="px-5 py-3.5 text-center">{{ $t('supplierPayments.amount') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.paymentMethod') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.journal') }}</th>
          <th class="px-5 py-3.5 text-center">{{ $t('supplierPayments.status') }}</th>
          <th class="px-5 py-3.5 text-start">{{ $t('supplierPayments.createdBy') }}</th>
          <th class="px-5 py-3.5 text-end">{{ $t('supplierPayments.actions') }}</th>
        </tr>
      </template>

      <template #row="{ item: payment }">
        <td class="px-5 py-4 whitespace-nowrap">
          <button
            type="button"
            class="font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
            @click="openDetailModal(payment)"
          >
            {{ payment.payment_number }}
          </button>
          <span
            v-if="(payment.allocations_count ?? 0) === 0"
            class="ms-2 inline-flex rounded-md border border-[var(--gold)]/30 bg-[var(--gold-soft)] px-1.5 py-0.5 text-[10px] font-bold text-[var(--gold-strong)]"
          >
            {{ $t('supplierPayments.advanceBadge') }}
          </span>
        </td>

        <td class="px-5 py-4 font-bold text-[var(--text)]">
          {{ payment.supplier?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-center font-mono text-xs font-bold text-[var(--text)] whitespace-nowrap">
          {{ formatDateOnly(payment.payment_date) }}
        </td>

        <td class="px-5 py-4 text-center font-mono text-sm font-black text-[var(--text)] whitespace-nowrap">
          {{ formatMoney(payment.amount) }}
          <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
        </td>

        <td class="px-5 py-4 text-xs font-semibold text-[var(--text)]">
          {{ payment.payment_method?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-xs font-semibold text-[var(--muted)]">
          {{ payment.journal?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-center">
          <span
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
            :class="statusBadgeClass(payment.status)"
          >
            <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
            {{ $t(`supplierPayments.statuses.${payment.status}`) }}
          </span>
        </td>

        <td class="px-5 py-4 text-xs text-[var(--muted)]">
          {{ payment.creator?.name || '—' }}
        </td>

        <td class="px-5 py-4 text-end">
          <div class="inline-flex items-center justify-end gap-1">
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
              :title="$t('supplierPayments.view')"
              @click="openDetailModal(payment)"
            >
              <Eye class="h-4 w-4" />
            </button>

            <template v-if="payment.status === 'draft'">
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                :title="$t('supplierPayments.edit')"
                @click="openEditModal(payment)"
              >
                <Pencil class="h-4 w-4" />
              </button>
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--success)] hover:bg-[var(--success-soft)] cursor-pointer"
                :title="$t('supplierPayments.post')"
                @click="handlePost(payment)"
              >
                <CheckCircle2 class="h-4 w-4" />
              </button>
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] cursor-pointer"
                :title="$t('supplierPayments.cancelPayment')"
                @click="handleCancel(payment)"
              >
                <XCircle class="h-4 w-4" />
              </button>
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--crimson)] hover:bg-[var(--crimson-soft)] cursor-pointer"
                :title="$t('supplierPayments.delete')"
                @click="handleDelete(payment)"
              >
                <Trash2 class="h-4 w-4" />
              </button>
            </template>
          </div>
        </td>
      </template>
    </DataTable>

    <!-- Create / Edit Modal -->
    <SupplierPaymentFormModal
      :open="formOpen"
      :payment="editingPayment"
      locked-payment-type="send"
      @close="formOpen = false"
      @saved="onPaymentSaved"
    />

    <!-- Details Modal -->
    <SupplierPaymentDetailModal
      :open="detailOpen"
      :payment="selectedPayment"
      :action-loading="actionLoading"
      @close="detailOpen = false"
      @edit="onEditFromDetail"
      @post="handlePost"
      @cancel-payment="handleCancel"
      @delete="handleDelete"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, Eye, Pencil, Plus, Trash2, XCircle } from 'lucide-vue-next';
import DataTable from '../../components/ui/DataTable.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SupplierPaymentDetailModal from './SupplierPaymentDetailModal.vue';
import SupplierPaymentFormModal from './SupplierPaymentFormModal.vue';
import api from '../../services/api';
import { fetchJournals } from '../../services/accountingApi';
import { confirmAction, confirmDelete } from '../../services/alert';
import {
  cancelSupplierPayment,
  deleteSupplierPayment,
  fetchSupplierPayments,
  postSupplierPayment,
} from '../../services/supplierPaymentsApi';
import { useNotificationStore } from '../../stores/notification';
import { formatDateOnly } from '../../utils/date';

const { t, locale } = useI18n();
const notificationStore = useNotificationStore();

const payments = ref([]);
const suppliers = ref([]);
const paymentMethods = ref([]);
const journals = ref([]);

const loading = ref(false);
const actionLoading = ref(false);
const loadError = ref('');
const perPage = ref(15);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });

const filters = ref({
  search: '',
  status: '',
  contact_id: null,
  payment_method_id: null,
  journal_id: null,
  payment_date_from: '',
  payment_date_to: '',
});

const formOpen = ref(false);
const editingPayment = ref(null);
const detailOpen = ref(false);
const selectedPayment = ref(null);

let searchTimer = null;

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.value.status) count += 1;
  if (filters.value.contact_id) count += 1;
  if (filters.value.payment_method_id) count += 1;
  if (filters.value.journal_id) count += 1;
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
    const [supRes, pmRes, cashRes, bankRes] = await Promise.all([
      api.get('/contacts', { params: { is_supplier: 1, is_active: 1, per_page: 100 } }),
      api.get('/payment-methods'),
      fetchJournals({ type: 'cash', is_active: 1, per_page: 100 }),
      fetchJournals({ type: 'bank', is_active: 1, per_page: 100 }),
    ]);

    suppliers.value = supRes.data?.data ?? [];
    paymentMethods.value = (pmRes.data?.data ?? []).filter((pm) => pm.is_active && pm.account_id);
    journals.value = [...(cashRes.data?.data ?? []), ...(bankRes.data?.data ?? [])];
  } catch {
    // Non-blocking; lookups can be retried
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
      ...(filters.value.status ? { status: filters.value.status } : {}),
      ...(filters.value.contact_id ? { contact_id: filters.value.contact_id } : {}),
      ...(filters.value.payment_method_id ? { payment_method_id: filters.value.payment_method_id } : {}),
      ...(filters.value.journal_id ? { journal_id: filters.value.journal_id } : {}),
      ...(filters.value.payment_date_from ? { payment_date_from: filters.value.payment_date_from } : {}),
      ...(filters.value.payment_date_to ? { payment_date_to: filters.value.payment_date_to } : {}),
    };

    const res = await fetchSupplierPayments(params);
    payments.value = res.data?.data ?? [];
    meta.value = res.data?.meta ?? { current_page: page, last_page: 1, per_page: perPage.value, total: 0 };
  } catch (err) {
    loadError.value = err.response?.data?.message || t('supplierPayments.loadError');
  } finally {
    loading.value = false;
  }
};

const debouncedSearch = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadPayments(1), 300);
};

const setStatusFilter = (status) => {
  filters.value.status = status;
  loadPayments(1);
};

const resetFilters = () => {
  filters.value = {
    search: '',
    status: '',
    contact_id: null,
    payment_method_id: null,
    journal_id: null,
    payment_date_from: '',
    payment_date_to: '',
  };
  loadPayments(1);
};

const openCreateModal = () => {
  editingPayment.value = null;
  formOpen.value = true;
};

const openEditModal = (payment) => {
  editingPayment.value = payment;
  formOpen.value = true;
};

const openDetailModal = (payment) => {
  selectedPayment.value = payment;
  detailOpen.value = true;
};

const onEditFromDetail = (payment) => {
  detailOpen.value = false;
  openEditModal(payment);
};

const onPaymentSaved = (saved) => {
  loadPayments(meta.value.current_page);
  if (detailOpen.value && saved?.id === selectedPayment.value?.id) {
    selectedPayment.value = saved;
  }
};

const handlePost = async (payment) => {
  const confirmed = await confirmAction({
    title: t('supplierPayments.postConfirmTitle'),
    text: t('supplierPayments.postConfirmText'),
    confirmButtonText: t('supplierPayments.postConfirmBtn'),
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    const res = await postSupplierPayment(payment.id);
    notificationStore.success(t('supplierPayments.postedSuccess'));
    if (selectedPayment.value?.id === payment.id) {
      selectedPayment.value = res.data?.data ?? selectedPayment.value;
    }
    await loadPayments(meta.value.current_page);
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  } finally {
    actionLoading.value = false;
  }
};

const handleCancel = async (payment) => {
  const confirmed = await confirmAction({
    title: t('supplierPayments.cancelConfirmTitle'),
    text: t('supplierPayments.cancelConfirmText'),
    confirmButtonText: t('supplierPayments.cancelConfirmBtn'),
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    const res = await cancelSupplierPayment(payment.id);
    notificationStore.success(t('supplierPayments.cancelledSuccess'));
    if (selectedPayment.value?.id === payment.id) {
      selectedPayment.value = res.data?.data ?? selectedPayment.value;
    }
    await loadPayments(meta.value.current_page);
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  } finally {
    actionLoading.value = false;
  }
};

const handleDelete = async (payment) => {
  const confirmed = await confirmDelete({
    title: t('supplierPayments.deleteConfirmTitle'),
    text: t('supplierPayments.deleteConfirmText'),
    confirmButtonText: t('supplierPayments.deleteConfirmBtn'),
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    await deleteSupplierPayment(payment.id);
    notificationStore.success(t('supplierPayments.deletedSuccess'));
    if (selectedPayment.value?.id === payment.id) {
      detailOpen.value = false;
      selectedPayment.value = null;
    }
    await loadPayments(meta.value.current_page);
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  } finally {
    actionLoading.value = false;
  }
};

onMounted(() => {
  loadLookups();
  loadPayments(1);
});
</script>
