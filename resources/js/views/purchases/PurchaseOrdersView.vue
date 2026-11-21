<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <PageHeader :title="$t('purchaseOrders.title')" :subtitle="$t('purchaseOrders.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('purchaseOrders.create') }}</span>
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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadOrders">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('purchaseOrders.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedSearch"
        @reset="clearFilters"
      >
        <div class="space-y-4">
          <!-- Status -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('purchaseOrders.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('purchaseOrders.allStatuses')"
                :active="filters.status === null"
                @click="setStatusFilter(null)"
              />
              <FilterChip
                :label="$t('purchaseOrders.statuses.draft')"
                :active="filters.status === 'draft'"
                @click="setStatusFilter('draft')"
              />
              <FilterChip
                :label="$t('purchaseOrders.statuses.converted')"
                :active="filters.status === 'converted'"
                @click="setStatusFilter('converted')"
              />
              <FilterChip
                :label="$t('purchaseOrders.statuses.cancelled')"
                :active="filters.status === 'cancelled'"
                @click="setStatusFilter('cancelled')"
              />
            </div>
          </div>

          <!-- Supplier -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('purchaseOrders.supplier') }}</label>
            <select
              v-model="filters.supplier_id"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              @change="meta.current_page = 1; loadOrders()"
            >
              <option :value="null">{{ $t('purchaseOrders.allSuppliers') }}</option>
              <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
                {{ sup.name }}
              </option>
            </select>
          </div>

          <!-- Date Range -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('purchaseOrders.orderDate') }} ({{ $t('purchaseOrders.dateFrom') }})</label>
              <input
                v-model="filters.order_date_from"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="meta.current_page = 1; loadOrders()"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('purchaseOrders.orderDate') }} ({{ $t('purchaseOrders.dateTo') }})</label>
              <input
                v-model="filters.order_date_to"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="meta.current_page = 1; loadOrders()"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Table Section -->
      <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-5 py-3.5">{{ $t('purchaseOrders.orderNumber') }}</th>
              <th class="px-5 py-3.5">{{ $t('purchaseOrders.supplier') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseOrders.orderDate') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseOrders.expectedDeliveryDate') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseOrders.status') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseOrders.itemsCount') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseOrders.totalDiscounts') }}</th>
              <th class="px-5 py-3.5 text-center">{{ $t('purchaseOrders.total') }}</th>
              <th class="px-5 py-3.5 text-end">{{ $t('purchaseOrders.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading State -->
            <tr v-if="loading && orders.length === 0">
              <td colspan="9" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="orders.length === 0">
              <td colspan="9" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
                    <ShoppingBag class="h-7 w-7" />
                  </div>
                  <h3 class="mt-4 text-base font-extrabold text-[var(--text)]">
                    {{ $t('purchaseOrders.noOrders') }}
                  </h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">
                    {{ $t('purchaseOrders.noOrdersSubtitle') }}
                  </p>
                  <button
                    type="button"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white hover:bg-[var(--crimson-hover)]"
                    @click="openCreateModal"
                  >
                    <Plus class="h-4 w-4" />
                    <span>{{ $t('purchaseOrders.create') }}</span>
                  </button>
                </div>
              </td>
            </tr>

            <!-- Table Rows -->
            <tr
              v-for="order in orders"
              :key="order.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <!-- Order Number -->
              <td class="px-5 py-4">
                <button
                  type="button"
                  class="font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                  dir="ltr"
                  @click="openDetailModal(order)"
                >
                  {{ order.order_number }}
                </button>
              </td>

              <!-- Supplier -->
              <td class="px-5 py-4">
                <div class="font-bold text-[var(--text)]">{{ order.supplier?.name || '—' }}</div>
              </td>

              <!-- Order Date -->
              <td class="px-5 py-4 text-center text-xs font-mono font-bold text-[var(--text)]">
                {{ formatDateOnly(order.order_date) }}
              </td>

              <!-- Expected Delivery Date -->
              <td class="px-5 py-4 text-center text-xs font-mono text-[var(--muted)]">
                {{ formatDateOnly(order.expected_delivery_date) }}
              </td>

              <!-- Status Badge -->
              <td class="px-5 py-4 text-center">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
                  :class="statusBadgeClass(order.status)"
                >
                  <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                  {{ statusLabel(order.status) }}
                </span>
              </td>

              <!-- Items Count -->
              <td class="px-5 py-4 text-center">
                <span class="inline-flex rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--text)] border border-[var(--border)]">
                  {{ order.items_count ?? order.items?.length ?? 0 }}
                </span>
              </td>

              <!-- Total Discounts -->
              <td class="px-5 py-4 text-center font-mono text-xs font-bold">
                <span
                  v-if="totalDiscountAmount(order) > 0"
                  class="text-rose-600 dark:text-rose-400"
                  :title="discountBreakdownTitle(order)"
                >
                  -{{ formatMoney(totalDiscountAmount(order)) }}
                  <span class="text-[11px] font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </span>
                <span v-else class="text-[var(--muted)]">—</span>
              </td>

              <!-- Total -->
              <td class="px-5 py-4 text-center font-mono text-sm font-black text-[var(--text)]">
                {{ formatMoney(order.total) }} <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </td>

              <!-- Actions -->
              <td class="px-5 py-4 text-end">
                <div class="flex items-center justify-end gap-1">
                  <!-- View Details -->
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('purchaseOrders.view')"
                    @click="openDetailModal(order)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>

                  <!-- Open Linked Purchase Invoice -->
                  <button
                    v-if="getLinkedInvoice(order)"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--crimson)] hover:bg-[var(--crimson-soft)]"
                    :title="$t('purchaseOrders.openLinkedInvoice', { number: getLinkedInvoice(order).invoice_number })"
                    @click="goToLinkedInvoice(getLinkedInvoice(order).id)"
                  >
                    <Receipt class="h-4 w-4" />
                  </button>

                  <!-- Confirm & Convert (Draft only) -->
                  <button
                    v-if="order.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 dark:hover:bg-emerald-950/30"
                    :title="$t('purchaseOrders.confirm')"
                    @click="confirmOrderAction(order)"
                  >
                    <CheckCircle2 class="h-4 w-4" />
                  </button>

                  <!-- Edit (Draft only) -->
                  <button
                    v-if="order.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('purchaseOrders.edit')"
                    @click="openEditModal(order)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>

                  <!-- Cancel (Draft only) -->
                  <button
                    v-if="order.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-950/30"
                    :title="$t('purchaseOrders.cancel')"
                    @click="cancelOrderAction(order)"
                  >
                    <XCircle class="h-4 w-4" />
                  </button>

                  <!-- Delete (Draft only) -->
                  <button
                    v-if="order.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30"
                    :title="$t('purchaseOrders.delete')"
                    @click="deleteOrderAction(order)"
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
      <div
        v-if="meta.total > meta.per_page"
        class="flex flex-col items-center justify-between gap-4 border-t border-[var(--border)] p-4 sm:flex-row sm:px-6"
      >
        <span class="text-xs text-[var(--muted)]">
          {{ $t('common.page') }} {{ meta.current_page }} {{ $t('common.of') }} {{ meta.last_page }} {{ $t('purchaseOrders.ordersCountParen', { count: meta.total }) }}
        </span>

        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="meta.current_page <= 1"
            class="rounded-lg border border-[var(--border)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-40"
            @click="changePage(meta.current_page - 1)"
          >
            {{ $t('common.previous') }}
          </button>
          <button
            type="button"
            :disabled="meta.current_page >= meta.last_page"
            class="rounded-lg border border-[var(--border)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-40"
            @click="changePage(meta.current_page + 1)"
          >
            {{ $t('common.next') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Create / Edit Form Modal -->
    <PurchaseOrderFormModal
      :open="formModalOpen"
      :order="selectedOrder"
      :suppliers="suppliers"
      :products="products"
      @close="formModalOpen = false"
      @saved="onOrderSaved"
    />

    <!-- Detail Drawer Modal -->
    <PurchaseOrderDetailModal
      :open="detailModalOpen"
      :order="selectedOrder"
      :action-loading="actionLoading"
      @close="detailModalOpen = false"
      @edit="openEditFromDetail"
      @confirm="confirmOrderAction"
      @cancel="cancelOrderAction"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import { confirmAction, confirmDelete, showSuccess, showError, getSwalInstance } from '../../services/alert';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import PurchaseOrderFormModal from './PurchaseOrderFormModal.vue';
import PurchaseOrderDetailModal from './PurchaseOrderDetailModal.vue';
import {
  ShoppingBag,
  Plus,
  Eye,
  Pencil,
  Trash2,
  CheckCircle2,
  XCircle,
  AlertCircle,
  Receipt,
} from 'lucide-vue-next';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const orders = ref([]);
const suppliers = ref([]);
const products = ref([]);
const loading = ref(false);
const loadError = ref('');
const actionLoading = ref(false);

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});

const filters = reactive({
  search: '',
  supplier_id: null,
  status: null,
  order_date_from: null,
  order_date_to: null,
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.supplier_id !== null) count++;
  if (filters.status !== null) count++;
  if (filters.order_date_from) count++;
  if (filters.order_date_to) count++;
  return count;
});

const hasActiveFilters = computed(() => Boolean(filters.search) || activeFiltersCount.value > 0);

// Modals state
const formModalOpen = ref(false);
const detailModalOpen = ref(false);
const selectedOrder = ref(null);

let searchTimeout = null;
function debouncedSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    meta.current_page = 1;
    loadOrders();
  }, 300);
}

function setStatusFilter(status) {
  filters.status = status;
  meta.current_page = 1;
  loadOrders();
}

function clearFilters() {
  filters.search = '';
  filters.supplier_id = null;
  filters.status = null;
  filters.order_date_from = null;
  filters.order_date_to = null;
  meta.current_page = 1;
  loadOrders();
}

function changePage(page) {
  if (page < 1 || page > meta.last_page) return;
  meta.current_page = page;
  loadOrders();
}

function formatMoney(amount) {
  const num = Number(amount) || 0;
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function itemDiscountsAmount(order) {
  if (order?.items_sum_discount_amount !== undefined && order?.items_sum_discount_amount !== null) {
    return Number(order.items_sum_discount_amount) || 0;
  }
  return (order?.items || []).reduce((sum, i) => sum + (Number(i.discount_amount) || 0), 0);
}

function totalDiscountAmount(order) {
  return itemDiscountsAmount(order) + (Number(order?.discount_amount) || 0);
}

function discountBreakdownTitle(order) {
  const itemDisc = itemDiscountsAmount(order);
  const orderDisc = Number(order?.discount_amount) || 0;
  const parts = [];
  if (itemDisc > 0) parts.push(`${t('purchaseOrders.itemDiscounts')}: ${formatMoney(itemDisc)}`);
  if (orderDisc > 0) parts.push(`${t('purchaseOrders.orderDiscount')}: ${formatMoney(orderDisc)}`);
  return parts.join(' | ');
}

function formatDateOnly(val) {
  if (!val) return '—';
  return String(val).substring(0, 10);
}

function statusLabel(status) {
  if (!status) return '—';
  return t(`purchaseOrders.statuses.${status}`) || status;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft':
      return 'bg-amber-500/10 text-amber-700 border border-amber-500/20 dark:text-amber-400';
    case 'converted':
      return 'bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 dark:text-emerald-400';
    case 'cancelled':
      return 'bg-rose-500/10 text-rose-700 border border-rose-500/20 dark:text-rose-400';
    default:
      return 'bg-gray-500/10 text-gray-700 border border-gray-500/20 dark:text-gray-400';
  }
}

async function loadOrders() {
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
    if (filters.order_date_from) params.order_date_from = filters.order_date_from;
    if (filters.order_date_to) params.order_date_to = filters.order_date_to;

    const res = await api.get('/purchase-orders', { params });
    orders.value = res.data.data || [];
    if (res.data.meta) {
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.per_page = res.data.meta.per_page;
      meta.total = res.data.meta.total;
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || t('purchaseOrders.loadError');
  } finally {
    loading.value = false;
  }
}

async function loadSuppliers() {
  try {
    const res = await api.get('/contacts', { params: { is_supplier: 1, per_page: 500 } });
    suppliers.value = res.data.data || [];
  } catch {
    // Keep empty on error
  }
}

async function loadProducts() {
  try {
    const res = await api.get('/products', { params: { is_active: 1, per_page: 1000 } });
    products.value = res.data.data || [];
  } catch {
    // Keep empty on error
  }
}

function getLinkedInvoice(order) {
  const list = order?.purchase_invoices || [];
  if (list.length === 0) return null;
  return list.find((inv) => inv.status !== 'cancelled') || list[0];
}

function goToLinkedInvoice(invoiceId) {
  router.push({ name: 'purchase-invoices', query: { open: invoiceId } });
}

function openCreateModal() {
  selectedOrder.value = null;
  formModalOpen.value = true;
}

function openEditModal(order) {
  selectedOrder.value = order;
  formModalOpen.value = true;
}

function openEditFromDetail(order) {
  detailModalOpen.value = false;
  openEditModal(order);
}

async function openDetailModal(order) {
  try {
    const res = await api.get(`/purchase-orders/${order.id}`);
    selectedOrder.value = res.data.data;
  } catch {
    selectedOrder.value = order;
  }
  detailModalOpen.value = true;
}

function onOrderSaved() {
  showSuccess({ title: t('purchaseOrders.savedSuccessTitle'), text: t('purchaseOrders.savedSuccessText') });
  loadOrders();
}

async function confirmOrderAction(order) {
  const confirmed = await confirmAction({
    title: t('purchaseOrders.confirmTitle'),
    text: t('purchaseOrders.confirmMessage'),
    confirmButtonText: t('purchaseOrders.confirmBtnYes'),
    cancelButtonText: t('common.cancel'),
    icon: 'question',
  });
  if (!confirmed) return;

  actionLoading.value = true;
  try {
    const res = await api.post(`/purchase-orders/${order.id}/confirm`);
    if (detailModalOpen.value && selectedOrder.value?.id === order.id) {
      selectedOrder.value = res.data.data;
    }
    showSuccess({ title: t('purchaseOrders.confirmSuccessTitle'), text: t('purchaseOrders.confirmSuccessText') });
    loadOrders();
  } catch (err) {
    showError({ title: t('common.errorAlertTitle'), text: err.response?.data?.message || t('purchaseOrders.confirmErrorText') });
  } finally {
    actionLoading.value = false;
  }
}

async function cancelOrderAction(order) {
  const swal = getSwalInstance();
  const resPrompt = await swal.fire({
    title: t('purchaseOrders.cancelTitle'),
    text: t('purchaseOrders.cancelMessage'),
    input: 'text',
    inputPlaceholder: t('purchaseOrders.cancelReason'),
    icon: 'warning',
    showCancelButton: true,
    confirmButtonText: t('purchaseOrders.cancelBtnYes'),
    cancelButtonText: t('purchaseOrders.backBtn'),
    customClass: {
      confirmButton: 'sarh-swal-btn sarh-swal-delete',
      cancelButton: 'sarh-swal-btn sarh-swal-cancel',
    },
  });

  if (!resPrompt.isConfirmed) return;

  const reason = resPrompt.value;

  actionLoading.value = true;
  try {
    const res = await api.post(`/purchase-orders/${order.id}/cancel`, { reason: reason || null });
    if (detailModalOpen.value && selectedOrder.value?.id === order.id) {
      selectedOrder.value = res.data.data;
    }
    showSuccess({ title: t('purchaseOrders.cancelSuccessTitle'), text: t('purchaseOrders.cancelSuccessText') });
    loadOrders();
  } catch (err) {
    showError({ title: t('common.errorAlertTitle'), text: err.response?.data?.message || t('purchaseOrders.cancelErrorText') });
  } finally {
    actionLoading.value = false;
  }
}

async function deleteOrderAction(order) {
  const confirmed = await confirmDelete({
    title: t('purchaseOrders.delete'),
    text: t('purchaseOrders.deleteConfirm'),
    confirmButtonText: t('purchaseOrders.deleteBtnYes'),
    cancelButtonText: t('common.cancel'),
  });
  if (!confirmed) return;

  try {
    await api.delete(`/purchase-orders/${order.id}`);
    if (detailModalOpen.value && selectedOrder.value?.id === order.id) {
      detailModalOpen.value = false;
    }
    showSuccess({ title: t('purchaseOrders.deleteSuccessTitle'), text: t('purchaseOrders.deleteSuccessText') });
    loadOrders();
  } catch (err) {
    showError({ title: t('common.errorAlertTitle'), text: err.response?.data?.message || t('purchaseOrders.deleteErrorText') });
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
  loadOrders();
  loadSuppliers();
  loadProducts();
  openFromQuery();
});
</script>
