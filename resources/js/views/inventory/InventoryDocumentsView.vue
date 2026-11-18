<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <PageHeader :title="pageTitle" :subtitle="pageSubtitle">
      <template #actions>
        <!-- Direct link to edit default warehouse (accessible directly without menu) -->
        <router-link
          to="/warehouses"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Building2 class="h-[18px] w-[18px] text-[var(--muted)]" aria-hidden="true" />
          <span>{{ $t('warehouses.editWarehouse') }}</span>
        </router-link>

        <!-- Create Document Button (Only visible for specific document types, hidden in All Documents) -->
        <button
          v-if="activeType"
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ createButtonText }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Refresh / Load Error banner -->
    <div
      v-if="loadError"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadDocuments">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Main Documents Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('inventory.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedSearch"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Type Filter (Only visible when viewing all documents) -->
          <div v-if="!activeType">
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('inventory.fields.type') }}</label>
            <SearchableSelect
              v-model="filters.document_type"
              :options="typeFilterOptions"
              placeholder="كل أنواع المستندات"
              search-placeholder="بحث في أنواع المستندات..."
              allow-empty
              @change="loadDocuments"
            />
          </div>

          <!-- Status Filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.status') }}</label>
            <SearchableSelect
              v-model="filters.status"
              :options="statusFilterOptions"
              placeholder="كل الحالات"
              search-placeholder="بحث في الحالات..."
              allow-empty
              @change="loadDocuments"
            />
          </div>
        </div>
      </FilterToolbar>

      <!-- Table View -->
      <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-4 py-3.5">{{ $t('inventory.fields.documentNumber') }}</th>
              <th class="px-4 py-3.5">{{ $t('inventory.fields.documentSource') }}</th>
              <th class="px-4 py-3.5">{{ $t('inventory.fields.type') }}</th>
              <th class="px-4 py-3.5">{{ $t('inventory.fields.date') }}</th>
              <th class="hidden px-4 py-3.5 md:table-cell">{{ $t('inventory.fields.sourceWarehouse') }}</th>
              <th class="hidden px-4 py-3.5 md:table-cell">{{ $t('inventory.fields.destinationWarehouse') }}</th>
              <th class="px-4 py-3.5 text-center">{{ $t('inventory.fields.itemsCount') }}</th>
              <th class="px-4 py-3.5 text-center">{{ $t('common.status') }}</th>
              <th class="px-4 py-3.5 text-left">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading indicator -->
            <tr v-if="loading && documents.length === 0">
              <td colspan="9" class="px-4 py-12 text-center text-sm text-[var(--muted)]">
                <Loader2 class="mx-auto h-6 w-6 animate-spin text-[var(--muted)]" />
                <span class="mt-2 block">{{ $t('common.loading') }}</span>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="documents.length === 0">
              <td colspan="9" class="px-4 py-12 text-center">
                <FileText class="mx-auto h-10 w-10 text-[var(--muted)]/50" />
                <h3 class="mt-3 text-base font-bold text-[var(--text)]">{{ $t('inventory.noDocuments') }}</h3>
                <p class="mt-1 text-xs text-[var(--muted)]">{{ $t('inventory.noDocumentsDesc') }}</p>
                <button
                  v-if="activeType"
                  type="button"
                  class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white hover:bg-[var(--crimson-hover)]"
                  @click="openCreateModal"
                >
                  <Plus class="h-4 w-4" />
                  <span>{{ createButtonText }}</span>
                </button>
              </td>
            </tr>

            <!-- Rows -->
            <tr
              v-for="doc in documents"
              :key="doc.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <!-- Document Number -->
              <td class="px-4 py-3.5">
                <button
                  type="button"
                  class="font-mono text-sm font-extrabold text-[var(--text)] hover:underline cursor-pointer"
                  dir="ltr"
                  @click="openDetailModal(doc)"
                >
                  {{ doc.document_number }}
                </button>
              </td>

              <!-- Document Source -->
              <td class="px-4 py-3.5">
                <button
                  v-if="documentSourceInfo(doc)?.clickable"
                  type="button"
                  class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 text-xs font-bold text-[var(--text)] transition-colors hover:bg-[var(--bar)] cursor-pointer"
                  :title="documentSourceInfo(doc).title"
                  @click.stop="documentSourceInfo(doc).onClick()"
                >
                  <component :is="documentSourceInfo(doc).icon" class="h-3.5 w-3.5 text-[var(--muted)] shrink-0" aria-hidden="true" />
                  <span class="text-[var(--muted)] font-semibold">{{ documentSourceInfo(doc).label }}:</span>
                  <span class="font-mono" dir="ltr">{{ documentSourceInfo(doc).number }}</span>
                  <ExternalLink class="h-3 w-3 text-[var(--muted)] shrink-0" aria-hidden="true" />
                </button>
                <span
                  v-else-if="documentSourceInfo(doc)"
                  class="inline-flex items-center rounded-md bg-[var(--bg)] px-2.5 py-1 text-xs font-semibold text-[var(--muted)]"
                >
                  {{ documentSourceInfo(doc).label }} {{ documentSourceInfo(doc).number }}
                </span>
                <span
                  v-else
                  class="inline-flex rounded-md bg-[var(--bg)] px-2.5 py-1 text-xs font-semibold text-[var(--muted)]"
                >
                  {{ $t('inventory.fields.directSource') }}
                </span>
              </td>

              <!-- Document Type -->
              <td class="px-4 py-3.5">
                <span class="font-semibold text-[var(--text)]">{{ typeLabel(doc.document_type) }}</span>
                <span
                  v-if="doc.adjustment_direction"
                  class="block text-[11px] text-[var(--muted)]"
                >
                  ({{ directionLabel(doc.adjustment_direction) }})
                </span>
                <span
                  v-else-if="doc.return_direction"
                  class="block text-[11px] text-[var(--muted)]"
                >
                  ({{ directionLabel(doc.return_direction) }})
                </span>
              </td>

              <!-- Date -->
              <td class="px-4 py-3.5 text-xs text-[var(--muted)]">
                {{ formatDate(doc.document_date) }}
              </td>

              <!-- Source Warehouse -->
              <td class="hidden px-4 py-3.5 text-xs font-medium text-[var(--text)] md:table-cell">
                {{ doc.source_warehouse?.name || '—' }}
              </td>

              <!-- Destination Warehouse -->
              <td class="hidden px-4 py-3.5 text-xs font-medium text-[var(--text)] md:table-cell">
                {{ doc.destination_warehouse?.name || '—' }}
              </td>

              <!-- Items count -->
              <td class="px-4 py-3.5 text-center text-xs font-bold text-[var(--muted)]">
                {{ doc.items_count ?? (doc.items ? doc.items.length : 0) }}
              </td>

              <!-- Status Badge -->
              <td class="px-4 py-3.5 text-center">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                  :class="statusBadgeClass(doc.status)"
                >
                  {{ statusLabel(doc.status) }}
                </span>
              </td>

              <!-- Actions -->
              <td class="px-4 py-3.5 text-left">
                <div class="flex items-center justify-end gap-1.5">
                  <!-- Show Details (Always visible) -->
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('common.details')"
                    @click="openDetailModal(doc)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>

                  <!-- Edit button (Only visible in specific type view, NOT on All Documents or Purchase Invoice GRs) -->
                  <button
                    v-if="activeType && doc.referenceable_type !== 'purchase_invoice' && (doc.status === 'draft' || doc.status === 'rejected')"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('common.edit')"
                    @click="openEditModal(doc)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>

                  <!-- Delete button (Only visible in specific type view, NOT on All Documents or Purchase Invoice GRs) -->
                  <button
                    v-if="activeType && doc.referenceable_type !== 'purchase_invoice' && doc.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]"
                    :title="$t('common.delete')"
                    @click="confirmDelete(doc)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer -->
      <div
        v-if="meta.total > meta.per_page"
        class="flex flex-col items-center justify-between gap-4 border-t border-[var(--border)] p-4 sm:flex-row sm:px-6"
      >
        <span class="text-xs text-[var(--muted)]">
          {{ $t('common.page') }} {{ meta.current_page }} {{ $t('common.of') }} {{ meta.last_page }} ({{ meta.total }} {{ $t('inventory.fields.records') }})
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

    <!-- Modals -->
    <InventoryDocumentDetailModal
      :open="detailModalOpen"
      :document="selectedDocument"
      @close="detailModalOpen = false"
      @updated="onDocumentUpdated"
      @edit="openEditModalFromDetail"
      @open-pos-sale="openPosSaleModal"
      @open-pos-return="openPosReturnModal"
    />

    <InventoryDocumentFormModal
      :open="formModalOpen"
      :document="editingDocument"
      :fixed-type="activeType"
      :warehouses="warehouses"
      :products="products"
      @close="formModalOpen = false"
      @saved="onDocumentSaved"
    />

    <!-- POS Sale Detail Modal (Cross-linked from inventory document) -->
    <PosSaleDetailModal
      v-if="selectedPosSaleId"
      :sale-id="selectedPosSaleId"
      @close="selectedPosSaleId = null"
      @open-return="openPosReturnModal"
    />

    <!-- POS Return Detail Modal (Cross-linked from inventory document) -->
    <PosReturnDetailModal
      v-if="selectedPosReturnId"
      :return-id="selectedPosReturnId"
      @close="selectedPosReturnId = null"
      @open-sale="openPosSaleModal"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  Building2,
  ClipboardCheck,
  ExternalLink,
  Eye,
  FileText,
  Loader2,
  Pencil,
  Plus,
  Receipt,
  RotateCcw,
  Trash2,
} from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import InventoryDocumentDetailModal from './InventoryDocumentDetailModal.vue';
import InventoryDocumentFormModal from './InventoryDocumentFormModal.vue';
import PosSaleDetailModal from '../pos/management/PosSaleDetailModal.vue';
import PosReturnDetailModal from '../pos/management/PosReturnDetailModal.vue';
import api from '../../services/api';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();

const selectedPosSaleId = ref(null);
const selectedPosReturnId = ref(null);

function openPosSaleModal(saleId) {
  selectedPosSaleId.value = saleId;
}

function openPosReturnModal(returnId) {
  selectedPosReturnId.value = returnId;
}

function documentSourceInfo(doc) {
  if (!doc?.referenceable_type) return null;

  const refId = doc.referenceable?.id || doc.referenceable_id;
  switch (doc.referenceable_type) {
    case 'pos_sale':
      return {
        clickable: true,
        icon: Receipt,
        label: 'فاتورة بيع',
        number: doc.referenceable?.invoice_number || `#${refId}`,
        title: 'فتح تفاصيل فاتورة البيع المرتبطة',
        onClick: () => openPosSaleModal(refId),
      };
    case 'pos_return':
      return {
        clickable: true,
        icon: RotateCcw,
        label: 'مرتجع',
        number: doc.referenceable?.return_number || `#${refId}`,
        title: 'فتح تفاصيل فاتورة المرتجع المرتبطة',
        onClick: () => openPosReturnModal(refId),
      };
    case 'stocktake':
      return {
        clickable: true,
        icon: ClipboardCheck,
        label: 'جرد',
        number: doc.referenceable?.stocktake_number || `#${refId}`,
        title: 'فتح مستند الجرد المرتبط',
        onClick: () => router.push({ path: '/inventory/stocktakes', query: { open: String(refId) } }),
      };
    case 'purchase_invoice':
      return {
        clickable: true,
        icon: Receipt,
        label: 'فاتورة شراء',
        number: doc.referenceable?.invoice_number || `#${refId}`,
        title: 'فتح فاتورة الشراء المرتبطة',
        onClick: () => router.push({ path: '/purchase-invoices', query: { open: String(refId) } }),
      };
    default:
      return {
        clickable: false,
        label: doc.referenceable_type,
        number: `#${refId}`,
      };
  }
}

const slugToTypeMap = {
  'goods-receipt': 'goods_receipt',
  'goods-delivery': 'goods_delivery',
  'warehouse-transfer': 'warehouse_transfer',
  'stock-adjustment': 'stock_adjustment',
  'inventory-return': 'inventory_return',
  'stock-write-off': 'stock_write_off',
  'opening-balance': 'opening_balance',
};

const activeType = computed(() => {
  const p = route.params.type;
  if (!p) return '';
  return slugToTypeMap[p] || p;
});

const pageTitle = computed(() => {
  if (!activeType.value) return t('inventory.title');
  const titles = {
    goods_receipt: 'الاستلامات المخزنية',
    goods_delivery: 'التسليمات المخزنية',
    warehouse_transfer: 'التحويلات المخزنية',
    stock_adjustment: 'التسويات المخزنية',
    inventory_return: 'المرتجعات المخزنية',
    stock_write_off: 'الاهلاكات المخزنية',
    opening_balance: 'الأرصدة الافتتاحية للمخزون',
  };
  return titles[activeType.value] || t('inventory.title');
});

const pageSubtitle = computed(() => {
  if (!activeType.value) return 'سجل عام لاستعراض والبحث في كافة الحركات والمستندات المخزنية (للعرض والتدقيق فقط).';
  const subs = {
    goods_receipt: 'متابعة وإصدار أذونات استلام وتوريد الكتب والمنتجات إلى المخزن.',
    goods_delivery: 'توثيق وتسجيل أذونات تسليم الكتب والطلبيات للعملاء والجهات المستلمة.',
    warehouse_transfer: 'إدارة حركات نقل وتحويل الكتب بين المستودعات والفروع المختلفة.',
    stock_adjustment: 'تسجيل ومعالجة تسويات الفروق الجردية (زيادة أو عجز).',
    inventory_return: 'إدارة أذونات المرتجعات الواردة والصادرة من وإلى المخزن.',
    stock_write_off: 'توثيق استبعاد وإتلاف الكتب والمنتجات التالفة أو الهالكة.',
    opening_balance: 'تسجيل وتوثيق الأرصدة الافتتاحية لكميات الكتب عند بدء النظام.',
  };
  return subs[activeType.value] || t('inventory.subtitle');
});

const createButtonText = computed(() => {
  if (!activeType.value) return t('inventory.createDocument');
  return `+ إضافة ${typeLabel(activeType.value)}`;
});

const loading = ref(false);
const loadError = ref('');
const documents = ref([]);
const warehouses = ref([]);
const products = ref([]);

const stats = reactive({
  total: 0,
  draft: 0,
  pending_approval: 0,
  posted: 0,
});

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});

const filters = reactive({
  search: '',
  document_type: '',
  status: '',
  page: 1,
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (!activeType.value && filters.document_type) count++;
  if (filters.status) count++;
  return count;
});

const hasActiveFilters = computed(() => Boolean(filters.search) || activeFiltersCount.value > 0);

const typeFilterOptions = computed(() => {
  const options = [
    { value: '', label: 'كل أنواع المستندات' },
    { value: 'warehouse_transfer', label: 'التحويلات' },
    { value: 'goods_receipt', label: 'الاستلامات' },
    { value: 'goods_delivery', label: 'التسليمات' },
    { value: 'stock_adjustment', label: 'التسويات' },
    { value: 'stock_write_off', label: 'الاهلاكات' },
    { value: 'opening_balance', label: 'رصيد افتتاحي' },
  ];
  return options;
});

const statusFilterOptions = [
  { value: '', label: 'كل الحالات' },
  { value: 'draft', label: 'مسودة' },
  { value: 'pending_approval', label: 'قيد الاعتماد' },
  { value: 'approved', label: 'معتمد' },
  { value: 'posted', label: 'مرحّل' },
  { value: 'rejected', label: 'مرفوض' },
  { value: 'cancelled', label: 'ملغي' },
  { value: 'reversed', label: 'معكوس' },
];

// Modals state
const detailModalOpen = ref(false);
const formModalOpen = ref(false);
const selectedDocument = ref(null);
const editingDocument = ref(null);

let debounceTimer = null;
function debouncedSearch() {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    filters.page = 1;
    loadDocuments();
  }, 350);
}

function resetFilters() {
  filters.search = '';
  filters.document_type = '';
  filters.status = '';
  filters.page = 1;
  loadDocuments();
}

function changePage(page) {
  filters.page = page;
  loadDocuments();
}

async function loadDocuments() {
  loading.value = true;
  loadError.value = '';

  try {
    const params = {
      page: filters.page,
      per_page: meta.per_page,
    };
    if (filters.search) params.search = filters.search;
    const docType = activeType.value || filters.document_type;
    if (docType) params.document_type = docType;
    if (filters.status) params.status = filters.status;

    const res = await api.get('/inventory-documents', { params });
    documents.value = res.data?.data || [];

    if (res.data?.meta) {
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.total = res.data.meta.total;
    }

    if (res.data?.stats) {
      stats.total = res.data.stats.total || 0;
      stats.draft = res.data.stats.draft || 0;
      stats.pending_approval = res.data.stats.pending_approval || 0;
      stats.posted = res.data.stats.posted || 0;
    }
  } catch (err) {
    loadError.value = err?.response?.data?.message || 'تعذر تحميل المستندات المخزنية.';
  } finally {
    loading.value = false;
  }
}

async function loadLookups() {
  try {
    const [whRes, prodRes] = await Promise.all([
      api.get('/warehouses'),
      api.get('/products', { params: { per_page: 500 } }),
    ]);

    warehouses.value = whRes.data?.data || [];
    products.value = prodRes.data?.data || [];
  } catch (e) {
    // Non-fatal lookup load
  }
}

function openCreateModal() {
  editingDocument.value = null;
  formModalOpen.value = true;
}

function openEditModal(doc) {
  editingDocument.value = doc;
  formModalOpen.value = true;
}

function openEditModalFromDetail(doc) {
  detailModalOpen.value = false;
  openEditModal(doc);
}

async function openDetailModal(doc) {
  try {
    const res = await api.get(`/inventory-documents/${doc.id}`);
    selectedDocument.value = res.data?.data || doc;
  } catch (e) {
    selectedDocument.value = doc;
  }
  detailModalOpen.value = true;
}

function onDocumentSaved(savedDoc) {
  formModalOpen.value = false;
  loadDocuments();
}

function onDocumentUpdated(updatedDoc) {
  selectedDocument.value = updatedDoc;
  loadDocuments();
}

async function confirmDelete(doc) {
  if (!confirm(`هل أنت متأكد من رغبتك في حذف المسودة ${doc.document_number}؟`)) return;

  try {
    await api.delete(`/inventory-documents/${doc.id}`);
    loadDocuments();
  } catch (err) {
    alert(err?.response?.data?.message || 'تعذر حذف المسودة');
  }
}

function typeLabel(type) {
  if (!type) return '';
  const clean = String(type).replace(/-/g, '_');
  const labels = {
    goods_receipt: 'إذن استلام',
    goods_delivery: 'إذن تسليم',
    warehouse_transfer: 'إذن تحويل',
    stock_adjustment: 'إذن تسوية',
    inventory_return: 'إذن مرتجع',
    stock_write_off: 'إذن إهلاك',
    opening_balance: 'رصيد افتتاحي',
  };
  return labels[clean] || clean;
}

function statusLabel(status) {
  const labels = {
    draft: 'مسودة',
    pending_approval: 'قيد الاعتماد',
    approved: 'معتمد',
    posted: 'مرحّل',
    rejected: 'مرفوض',
    cancelled: 'ملغي',
    reversed: 'معكوس',
  };
  return labels[status] || status;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft':
      return 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border border-zinc-500/30';
    case 'pending_approval':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/30';
    case 'approved':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30';
    case 'posted':
      return 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/30';
    case 'rejected':
      return 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/30';
    case 'cancelled':
      return 'bg-zinc-500/10 text-zinc-400 border border-zinc-500/20';
    case 'reversed':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/30';
    default:
      return 'bg-zinc-500/10 text-zinc-500';
  }
}

function directionLabel(dir) {
  const labels = {
    increase: 'زيادة',
    decrease: 'عجز',
    inbound: 'وارد',
    outbound: 'صادر',
  };
  return labels[dir] || dir;
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  return dateStr.substring(0, 10);
}

function applyRouteQuery() {
  if (typeof route.query.search === 'string') {
    filters.search = route.query.search;
  }
  if (route.query.open) {
    const docId = Number(route.query.open);
    if (Number.isFinite(docId) && docId > 0) {
      openDetailModal({ id: docId });
    }
  }
}

watch(
  () => route.params.type,
  () => {
    filters.page = 1;
    filters.search = typeof route.query.search === 'string' ? route.query.search : '';
    filters.status = '';
    filters.document_type = '';
    loadDocuments();
    applyRouteQuery();
  }
);

watch(
  () => route.query.open,
  (openId) => {
    if (openId) {
      applyRouteQuery();
    }
  }
);

onMounted(() => {
  applyRouteQuery();
  loadDocuments();
  loadLookups();
});
</script>
