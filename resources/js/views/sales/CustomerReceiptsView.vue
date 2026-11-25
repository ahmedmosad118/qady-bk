<template>
  <div class="space-y-4">
    <!-- Page Header (No Create Button) -->
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
        <td class="px-4 py-4 font-mono text-sm font-bold whitespace-nowrap">
          <button
            type="button"
            class="text-[var(--text)] hover:text-[var(--crimson)] transition-colors cursor-pointer"
            @click="openDetailModal(receipt)"
          >
            {{ receipt.payment_number }}
          </button>
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
          <div class="inline-flex items-center justify-end gap-1">
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
              :title="$t('supplierPayments.view')"
              @click="openDetailModal(receipt)"
            >
              <Eye class="h-4 w-4" />
            </button>
            <template v-if="receipt.status === 'draft'">
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                :title="$t('supplierPayments.edit')"
                @click="openEditModal(receipt)"
              >
                <Pencil class="h-4 w-4" />
              </button>
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--success)] hover:bg-[var(--success-soft)] cursor-pointer"
                :title="$t('supplierPayments.post')"
                @click="handlePost(receipt)"
              >
                <CheckCircle2 class="h-4 w-4" />
              </button>
              <button
                type="button"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--crimson)] hover:bg-[var(--crimson-soft)] cursor-pointer"
                :title="$t('common.delete')"
                @click="handleDelete(receipt)"
              >
                <Trash2 class="h-4 w-4" />
              </button>
            </template>
          </div>
        </td>
      </template>
    </DataTable>

    <!-- Edit Draft Customer Receipt Modal (Locked to Receive) -->
    <SupplierPaymentFormModal
      :open="formOpen"
      :payment="editingReceipt"
      locked-payment-type="receive"
      @close="formOpen = false"
      @saved="onReceiptSaved"
    />

    <!-- Details Modal -->
    <SupplierPaymentDetailModal
      :open="detailOpen"
      :payment="selectedReceipt"
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
import { CheckCircle2, Eye, Pencil, Trash2 } from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import DataTable from '../../components/ui/DataTable.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SupplierPaymentDetailModal from '../purchases/SupplierPaymentDetailModal.vue';
import SupplierPaymentFormModal from '../purchases/SupplierPaymentFormModal.vue';
import api from '../../services/api';
import { confirmAction, confirmDelete } from '../../services/alert';
import {
  cancelPayment,
  deletePayment,
  fetchPayments,
  postPayment,
} from '../../services/paymentsApi';
import { useNotificationStore } from '../../stores/notification';
import { formatDateOnly } from '../../utils/date';
import { createContactFetcher } from '../../utils/selectFetchers';

const { t, locale } = useI18n();
const notificationStore = useNotificationStore();

const receipts = ref([]);
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
  payment_date_from: '',
  payment_date_to: '',
});

const formOpen = ref(false);
const detailOpen = ref(false);
const editingReceipt = ref(null);
const selectedReceipt = ref(null);
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

    const res = await fetchPayments(params);
    receipts.value = res.data?.data ?? [];
    meta.value = res.data?.meta ?? { current_page: page, last_page: 1, per_page: perPage.value, total: 0 };
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

const openEditModal = (receipt) => {
  editingReceipt.value = receipt;
  formOpen.value = true;
};

const openDetailModal = (receipt) => {
  selectedReceipt.value = receipt;
  detailOpen.value = true;
};

const onEditFromDetail = (receipt) => {
  detailOpen.value = false;
  openEditModal(receipt);
};

const onReceiptSaved = (saved) => {
  loadReceipts(meta.value.current_page || 1);
  if (detailOpen.value && saved?.id === selectedReceipt.value?.id) {
    selectedReceipt.value = saved;
  }
};

const handlePost = async (receipt) => {
  const confirmed = await confirmAction({
    title: t('supplierPayments.confirmPostTitle'),
    text: t('supplierPayments.confirmPostDesc', { number: receipt.payment_number }),
    confirmText: t('supplierPayments.post'),
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    const res = await postPayment(receipt.id);
    notificationStore.success(t('supplierPayments.postedSuccess'));
    const updated = res.data?.data;
    if (detailOpen.value && updated) {
      selectedReceipt.value = updated;
    }
    loadReceipts(meta.value.current_page || 1);
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  } finally {
    actionLoading.value = false;
  }
};

const handleCancel = async (receipt) => {
  const confirmed = await confirmAction({
    title: t('supplierPayments.confirmCancelTitle'),
    text: t('supplierPayments.confirmCancelDesc', { number: receipt.payment_number }),
    confirmText: t('supplierPayments.cancelPayment'),
    danger: true,
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    const res = await cancelPayment(receipt.id);
    notificationStore.success(t('supplierPayments.cancelledSuccess'));
    const updated = res.data?.data;
    if (detailOpen.value && updated) {
      selectedReceipt.value = updated;
    }
    loadReceipts(meta.value.current_page || 1);
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  } finally {
    actionLoading.value = false;
  }
};

const handleDelete = async (receipt) => {
  const confirmed = await confirmDelete({
    title: t('supplierPayments.confirmDeleteTitle'),
    text: t('supplierPayments.confirmDeleteDesc', { number: receipt.payment_number }),
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    await deletePayment(receipt.id);
    notificationStore.success(t('supplierPayments.deletedSuccess'));
    if (detailOpen.value && selectedReceipt.value?.id === receipt.id) {
      detailOpen.value = false;
    }
    loadReceipts(meta.value.current_page || 1);
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  } finally {
    actionLoading.value = false;
  }
};

onMounted(() => {
  loadReceipts(1);
});
</script>
