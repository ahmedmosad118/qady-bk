<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('purchaseInvoices.title')" :subtitle="$t('purchaseInvoices.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('purchaseInvoices.create') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Error Banner -->
    <div
      v-if="loadError"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadInvoices">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('purchaseInvoices.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedSearch"
        @reset="clearFilters"
      >
        <div class="space-y-4">
          <!-- Status -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('purchaseInvoices.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('purchaseInvoices.allStatuses')"
                :active="filters.status === null"
                @click="setStatusFilter(null)"
              />
              <FilterChip
                :label="$t('purchaseInvoices.statuses.draft')"
                :active="filters.status === 'draft'"
                @click="setStatusFilter('draft')"
              />
              <FilterChip
                :label="$t('purchaseInvoices.statuses.cancelled')"
                :active="filters.status === 'cancelled'"
                @click="setStatusFilter('cancelled')"
              />
            </div>
          </div>

          <!-- Supplier -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('purchaseInvoices.supplier') }}</label>
            <select
              v-model="filters.supplier_id"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="onFilterChange"
            >
              <option :value="null">{{ $t('purchaseInvoices.allSuppliers') }}</option>
              <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
                {{ sup.name }}
              </option>
            </select>
          </div>

          <!-- Date Range -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
              <input
                v-model="filters.invoice_date_from"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="onFilterChange"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
              <input
                v-model="filters.invoice_date_to"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="onFilterChange"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Bulk Actions -->
      <BulkActionsBar
        :count="selectedIds.length"
        :loading="bulkDeleting"
        @delete="deleteSelectedInvoices"
        @clear="clearSelection"
      />

      <!-- Table Section -->
      <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th scope="col" class="w-12 px-4 py-3.5 text-start">
                <AppCheckbox
                  :checked="allPageSelected"
                  :indeterminate="somePageSelected && !allPageSelected"
                  :disabled="selectableItems.length === 0"
                  :aria-label="$t('common.selectAll')"
                  @change="toggleAllOnPage"
                />
              </th>
              <th class="px-5 py-3.5 text-start">{{ $t('purchaseInvoices.invoiceNumber') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('purchaseInvoices.documentSource') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('purchaseInvoices.supplier') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseInvoices.invoiceDate') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseInvoices.dueDate') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseInvoices.status') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseInvoices.itemsCount') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseInvoices.discounts') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseInvoices.total') }}</th>
              <th class="px-5 py-3.5 text-end">{{ $t('purchaseInvoices.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading State -->
            <tr v-if="loading && invoices.length === 0">
              <td colspan="11" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="invoices.length === 0">
              <td colspan="11" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
                    <FileText class="h-7 w-7" />
                  </div>
                  <h3 class="mt-4 text-base font-extrabold text-[var(--text)]">
                    {{ $t('purchaseInvoices.noInvoices') }}
                  </h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">
                    {{ $t('purchaseInvoices.noInvoicesSubtitle') }}
                  </p>
                  <button
                    type="button"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white hover:bg-[var(--crimson-hover)]"
                    @click="openCreateModal"
                  >
                    <Plus class="h-4 w-4" />
                    <span>{{ $t('purchaseInvoices.create') }}</span>
                  </button>
                </div>
              </td>
            </tr>

            <!-- Table Rows -->
            <tr
              v-for="invoice in invoices"
              :key="invoice.id"
              class="transition-colors"
              :class="isSelected(invoice) ? 'bg-[var(--crimson-soft)]' : 'hover:bg-[var(--bg)]/50'"
            >
              <td class="px-4 py-4">
                <AppCheckbox
                  :checked="isSelected(invoice)"
                  :disabled="!canModify(invoice)"
                  :aria-label="$t('common.selectRow')"
                  @change="toggleRow(invoice)"
                />
              </td>
              <!-- Invoice Number -->
              <td class="px-5 py-4 whitespace-nowrap">
                <button
                  type="button"
                  class="font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                  @click="openDetailModal(invoice)"
                >
                  {{ invoice.invoice_number }}
                </button>
              </td>

              <!-- Document Source -->
              <td class="px-5 py-4 whitespace-nowrap">
                <button
                  v-if="invoice.purchase_order?.id"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 font-mono text-xs font-bold text-[var(--text)] transition-colors hover:border-[var(--crimson)] hover:text-[var(--crimson)] cursor-pointer"
                  :title="$t('purchaseInvoices.openPurchaseOrder')"
                  @click="goToPurchaseOrder(invoice.purchase_order.id)"
                >
                  <span>{{ invoice.purchase_order.order_number }}</span>
                  <ExternalLink class="h-3 w-3 shrink-0" aria-hidden="true" />
                </button>
                <span
                  v-else
                  class="inline-flex rounded-md bg-[var(--bg)] px-2.5 py-1 text-xs font-semibold text-[var(--muted)]"
                >
                  {{ $t('purchaseInvoices.directSource') }}
                </span>
              </td>

              <!-- Supplier -->
              <td class="px-5 py-4">
                <div class="font-bold text-[var(--text)]">{{ invoice.supplier?.name || '—' }}</div>
              </td>

              <!-- Invoice Date -->
              <td class="px-5 py-4 text-center text-xs font-mono font-bold text-[var(--text)]">
                {{ formatDateOnly(invoice.invoice_date) }}
              </td>

              <!-- Due Date -->
              <td class="px-5 py-4 text-center text-xs font-mono text-[var(--muted)]">
                {{ formatDateOnly(invoice.due_date) }}
              </td>

              <!-- Status Badge -->
              <td class="px-5 py-4 text-center">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
                  :class="statusBadgeClass(invoice.status)"
                >
                  <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                  {{ statusLabel(invoice.status) }}
                </span>
              </td>

              <!-- Items Count -->
              <td class="px-5 py-4 text-center">
                <span class="inline-flex rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--text)] border border-[var(--border)]">
                  {{ invoice.items_count ?? invoice.items?.length ?? 0 }}
                </span>
              </td>

              <!-- Discounts -->
              <td class="px-5 py-4 text-center font-mono text-xs font-bold">
                <span
                  v-if="totalDiscountAmount(invoice) > 0"
                  class="text-rose-600 dark:text-rose-400"
                  :title="discountBreakdownTitle(invoice)"
                >
                  -{{ formatMoney(totalDiscountAmount(invoice)) }}
                  <span class="text-[11px] font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </span>
                <span v-else class="text-[var(--muted)]">—</span>
              </td>

              <!-- Total -->
              <td class="px-5 py-4 text-center font-mono text-sm font-black text-[var(--text)]">
                {{ formatMoney(invoice.total) }} <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </td>

              <!-- Actions -->
              <td class="px-5 py-4 text-end">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('purchaseInvoices.view')"
                    @click="openDetailModal(invoice)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>

                  <button
                    v-if="invoice.status === 'draft'"
                    type="button"
                    :disabled="actionLoading"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/30 disabled:opacity-50"
                    :title="$t('purchaseInvoices.post')"
                    @click="postInvoiceAction(invoice)"
                  >
                    <CheckCircle2 class="h-4 w-4" />
                  </button>

                  <button
                    v-if="canModify(invoice)"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('purchaseInvoices.edit')"
                    @click="openEditModal(invoice)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>

                  <button
                    v-if="canModify(invoice)"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30"
                    :title="$t('purchaseInvoices.cancel')"
                    @click="cancelInvoiceAction(invoice)"
                  >
                    <XCircle class="h-4 w-4" />
                  </button>

                  <button
                    v-if="canModify(invoice)"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30"
                    :title="$t('purchaseInvoices.delete')"
                    @click="deleteInvoiceAction(invoice)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <TablePagination
        :meta="meta"
        :items-count="invoices.length"
        :per-page="meta.per_page"
        :loading="loading"
        @update:per-page="meta.per_page = $event"
        @change-per-page="fetchInvoices(1)"
        @change-page="changePage"
      />
    </div>

    <!-- Create / Edit Form Modal -->
    <PurchaseInvoiceFormModal
      :open="formModalOpen"
      :invoice="selectedInvoice"
      :suppliers="suppliers"
      :products="products"
      :purchase-orders="confirmedOrders"
      @close="formModalOpen = false"
      @saved="onInvoiceSaved"
    />

    <!-- Detail Drawer -->
    <PurchaseInvoiceDetailModal
      :open="detailModalOpen"
      :invoice="selectedInvoice"
      :action-loading="actionLoading"
      @close="detailModalOpen = false"
      @edit="openEditFromDetail"
      @post="postInvoiceAction"
      @cancel="cancelInvoiceAction"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { formatMoney } from '../../utils/format';
import { formatDateOnly } from '../../utils/date';
import {
  discountBreakdownTitle as buildDiscountBreakdownTitle,
  itemDiscountsAmount,
  purchaseStatusBadgeClass as statusBadgeClass,
  totalDiscountAmount,
} from '../../utils/purchaseFormatters';
import api from '../../services/api';
import { confirmAction, confirmDelete, showSuccess, showError, getSwalInstance } from '../../services/alert';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import BulkActionsBar from '../../components/ui/BulkActionsBar.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import { deleteRecordsSequentially, useBulkSelection } from '../../composables/useBulkSelection';
import PurchaseInvoiceFormModal from './PurchaseInvoiceFormModal.vue';
import PurchaseInvoiceDetailModal from './PurchaseInvoiceDetailModal.vue';
import {
  FileText,
  Plus,
  Eye,
  Pencil,
  Trash2,
  XCircle,
  CheckCircle2,
  AlertCircle,
  ExternalLink,
} from 'lucide-vue-next';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const invoices = ref([]);
const suppliers = ref([]);
const products = ref([]);
const confirmedOrders = ref([]);
const loading = ref(false);
const loadError = ref('');
const actionLoading = ref(false);

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const filters = reactive({
  search: '',
  supplier_id: null,
  status: null,
  invoice_date_from: null,
  invoice_date_to: null,
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.supplier_id !== null) count++;
  if (filters.status !== null) count++;
  if (filters.invoice_date_from) count++;
  if (filters.invoice_date_to) count++;
  return count;
});

const hasActiveFilters = computed(() => Boolean(filters.search) || activeFiltersCount.value > 0);

const formModalOpen = ref(false);
const detailModalOpen = ref(false);
const selectedInvoice = ref(null);

function canModify(invoice) {
  if (!invoice || invoice.status !== 'draft') return false;
  const grStatus = invoice.goods_receipt?.status;
  return grStatus !== 'posted' && grStatus !== 'reversed';
}

let searchTimeout = null;
function debouncedSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    meta.current_page = 1;
    loadInvoices();
  }, 300);
}

function onFilterChange() {
  meta.current_page = 1;
  loadInvoices();
}

function setStatusFilter(status) {
  filters.status = status;
  meta.current_page = 1;
  loadInvoices();
}

function clearFilters() {
  filters.search = '';
  filters.supplier_id = null;
  filters.status = null;
  filters.invoice_date_from = null;
  filters.invoice_date_to = null;
  meta.current_page = 1;
  loadInvoices();
}

function changePage(page) {
  if (page < 1 || page > meta.last_page) return;
  meta.current_page = page;
  loadInvoices();
}

function discountBreakdownTitle(invoice) {
  return buildDiscountBreakdownTitle(invoice, 'purchaseInvoices');
}

function goToPurchaseOrder(purchaseOrderId) {
  if (!purchaseOrderId) return;
  router.push({ name: 'purchase-orders', query: { open: String(purchaseOrderId) } });
}

function statusLabel(status) {
  if (!status) return '—';
  return t(`purchaseInvoices.statuses.${status}`) || status;
}

async function loadInvoices() {
  clearSelection();
  loading.value = true;
  loadError.value = '';

  try {
    const params = {
      page: meta.current_page,
      per_page: meta.per_page,
    };
    if (filters.search) params.search = filters.search;
    if (filters.supplier_id) params.supplier_id = filters.supplier_id;
    if (filters.status) params.status = filters.status;
    if (filters.invoice_date_from) params.invoice_date_from = filters.invoice_date_from;
    if (filters.invoice_date_to) params.invoice_date_to = filters.invoice_date_to;

    const res = await api.get('/purchase-invoices', { params });
    invoices.value = res.data.data || [];
    if (res.data.meta) {
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.per_page = res.data.meta.per_page;
      meta.total = res.data.meta.total;
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || t('purchaseInvoices.loadError');
  } finally {
    loading.value = false;
  }
}

function openCreateModal() {
  selectedInvoice.value = null;
  formModalOpen.value = true;
}

async function openEditModal(invoice) {
  try {
    const res = await api.get(`/purchase-invoices/${invoice.id}`);
    selectedInvoice.value = res.data.data;
  } catch {
    selectedInvoice.value = invoice;
  }
  formModalOpen.value = true;
}

function openEditFromDetail(invoice) {
  detailModalOpen.value = false;
  openEditModal(invoice);
}

async function openDetailModal(invoice) {
  try {
    const res = await api.get(`/purchase-invoices/${invoice.id}`);
    selectedInvoice.value = res.data.data;
  } catch {
    selectedInvoice.value = invoice;
  }
  detailModalOpen.value = true;
}

function onInvoiceSaved() {
  showSuccess({
    title: t('purchaseInvoices.saveSuccessTitle'),
    text: t('purchaseInvoices.saveSuccess'),
  });
  loadInvoices();
}

async function postInvoiceAction(invoice) {
  const confirmed = await confirmAction({
    title: t('purchaseInvoices.postTitle'),
    text: t('purchaseInvoices.postConfirm'),
    confirmButtonText: t('purchaseInvoices.confirmPostBtn'),
    cancelButtonText: t('common.cancel'),
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    const res = await api.post(`/purchase-invoices/${invoice.id}/post`);
    if (detailModalOpen.value && selectedInvoice.value?.id === invoice.id) {
      selectedInvoice.value = res.data.data;
    }
    showSuccess({
      title: t('purchaseInvoices.postSuccessTitle'),
      text: t('purchaseInvoices.postSuccess'),
    });
    loadInvoices();
  } catch (err) {
    showError({
      title: t('common.error'),
      text: err.response?.data?.message || t('purchaseInvoices.unexpectedError'),
    });
  } finally {
    actionLoading.value = false;
  }
}

async function cancelInvoiceAction(invoice) {
  const swal = getSwalInstance();
  const resPrompt = await swal.fire({
    title: t('purchaseInvoices.cancelTitle'),
    text: t('purchaseInvoices.cancelMessage'),
    input: 'text',
    inputPlaceholder: t('purchaseInvoices.cancelReason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: t('purchaseInvoices.confirmCancelBtn'),
    cancelButtonText: t('common.cancel'),
    customClass: {
      confirmButton: 'sarh-swal-btn sarh-swal-delete',
      cancelButton: 'sarh-swal-btn sarh-swal-cancel',
    },
  });

  if (!resPrompt.isConfirmed) return;

  actionLoading.value = true;
  try {
    const res = await api.post(`/purchase-invoices/${invoice.id}/cancel`, {
      reason: resPrompt.value || null,
    });
    if (detailModalOpen.value && selectedInvoice.value?.id === invoice.id) {
      selectedInvoice.value = res.data.data;
    }
    showSuccess({
      title: t('purchaseInvoices.cancelSuccessTitle'),
      text: t('purchaseInvoices.cancelSuccess'),
    });
    loadInvoices();
  } catch (err) {
    showError({
      title: t('common.error'),
      text: err.response?.data?.message || t('purchaseInvoices.unexpectedError'),
    });
  } finally {
    actionLoading.value = false;
  }
}

async function deleteInvoiceAction(invoice) {
  const confirmed = await confirmDelete({
    title: t('purchaseInvoices.delete'),
    text: t('purchaseInvoices.deleteConfirm'),
    confirmButtonText: t('purchaseInvoices.confirmDeleteBtn'),
    cancelButtonText: t('common.cancel'),
  });
  if (!confirmed) return;

  try {
    await api.delete(`/purchase-invoices/${invoice.id}`);
    removeId(invoice.id);
    if (detailModalOpen.value && selectedInvoice.value?.id === invoice.id) {
      detailModalOpen.value = false;
    }
    showSuccess({
      title: t('purchaseInvoices.deleteSuccessTitle'),
      text: t('purchaseInvoices.deleteSuccess'),
    });
    loadInvoices();
  } catch (err) {
    showError({
      title: t('common.error'),
      text: err.response?.data?.message || t('purchaseInvoices.unexpectedError'),
    });
  }
}

const bulkDeleting = ref(false);
const {
  selectedIds,
  selectableItems,
  allPageSelected,
  somePageSelected,
  isSelected,
  toggleAllOnPage,
  toggleRow,
  clearSelection,
  removeId,
} = useBulkSelection(invoices, { canSelect: (invoice) => canModify(invoice) });

async function deleteSelectedInvoices() {
  if (!selectedIds.value.length || bulkDeleting.value) return;

  const count = selectedIds.value.length;
  const confirmed = await confirmDelete({
    title: t('common.confirmBulkDeleteTitle'),
    text: t('common.confirmBulkDeleteText', { count }),
    confirmButtonText: t('common.confirmBulkDeleteBtn', { count }),
    cancelButtonText: t('common.cancel'),
  });
  if (!confirmed) return;

  bulkDeleting.value = true;
  const { deletedCount, failedCount, firstErrorMessage } = await deleteRecordsSequentially(
    [...selectedIds.value],
    (id) => api.delete(`/purchase-invoices/${id}`)
  );
  bulkDeleting.value = false;

  clearSelection();
  await loadInvoices();

  if (deletedCount > 0 && failedCount === 0) {
    showSuccess({
      title: t('purchaseInvoices.deleteSuccessTitle'),
      text: t('common.bulkDeleteSuccess', { count: deletedCount }),
    });
  } else {
    showError({
      title: t('common.error'),
      text: firstErrorMessage || t('common.bulkDeletePartialError', { count: failedCount }),
    });
  }
}

function openFromQuery() {
  if (route.query.open) {
    const id = Number(route.query.open);
    if (Number.isFinite(id) && id > 0) {
      openDetailModal({ id });
    }
  }
}

watch(
  () => route.query.open,
  (openId) => {
    if (openId) {
      openFromQuery();
    }
  }
);

onMounted(() => {
  loadInvoices();
  openFromQuery();
});
</script>
