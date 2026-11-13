<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-[var(--crimson)]">{{ $t('nav.inventoryGroup') }}</p>
        <h1 class="mt-1 text-2xl font-extrabold leading-tight text-[var(--text)] sm:text-[28px]">
          {{ pageTitle }}
        </h1>
        <p class="mt-1.5 text-sm leading-6 text-[var(--muted)]">{{ pageSubtitle }}</p>
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <!-- Direct link to edit default warehouse (accessible directly without menu) -->
        <router-link
          to="/warehouses"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Building2 class="h-[18px] w-[18px] text-[var(--crimson)]" aria-hidden="true" />
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
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
      <StatCard
        :label="$t('inventory.stats.total')"
        :value="stats.total"
        :loading="loading"
      />
      <StatCard
        :label="$t('inventory.stats.draft')"
        :value="stats.draft"
        :loading="loading"
      />
      <StatCard
        :label="$t('inventory.stats.pendingApproval')"
        :value="stats.pending_approval"
        :loading="loading"
      />
      <StatCard
        :label="$t('inventory.stats.posted')"
        :value="stats.posted"
        :loading="loading"
      />
    </div>

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
      <!-- Toolbar & Filters -->
      <div class="border-b border-[var(--border)] p-4 sm:p-5">
        <div class="flex flex-col gap-4">
          <!-- Search & Selects row -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Search by Number -->
            <div class="relative">
              <Search
                class="pointer-events-none absolute right-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
              <input
                v-model="filters.search"
                type="search"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] pl-4 pr-11 text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/70 focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :placeholder="$t('inventory.searchPlaceholder')"
                @input="debouncedSearch"
              />
            </div>

            <!-- Type Filter (Only visible when viewing all documents) -->
            <div v-if="!activeType">
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
              <SearchableSelect
                v-model="filters.status"
                :options="statusFilterOptions"
                placeholder="كل الحالات"
                search-placeholder="بحث في الحالات..."
                allow-empty
                @change="loadDocuments"
              />
            </div>

            <!-- Reset Filters -->
            <div class="flex items-center gap-2">
              <button
                v-if="hasActiveFilters"
                type="button"
                class="inline-flex h-11 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] px-4 text-xs font-bold text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                @click="resetFilters"
              >
                <RotateCcw class="h-4 w-4" />
                <span>{{ $t('inventory.resetFilters') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Table View -->
      <div class="overflow-x-auto">
        <table class="w-full text-right text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-4 py-3.5">{{ $t('inventory.fields.documentNumber') }}</th>
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
              <td colspan="8" class="px-4 py-12 text-center text-sm text-[var(--muted)]">
                <Loader2 class="mx-auto h-6 w-6 animate-spin text-[var(--crimson)]" />
                <span class="mt-2 block">{{ $t('common.loading') }}</span>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="documents.length === 0">
              <td colspan="8" class="px-4 py-12 text-center">
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
                  class="font-mono text-sm font-extrabold text-[var(--crimson)] hover:underline"
                  @click="openDetailModal(doc)"
                >
                  {{ doc.document_number }}
                </button>
                <span v-if="doc.referenceable_type" class="block text-[11px] text-[var(--muted)]">
                  {{ doc.referenceable_type }} #{{ doc.referenceable_id }}
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

                  <!-- Edit button (Only visible in specific type view, NOT on All Documents) -->
                  <button
                    v-if="activeType && (doc.status === 'draft' || doc.status === 'rejected')"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :title="$t('common.edit')"
                    @click="openEditModal(doc)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>

                  <!-- Delete button (Only visible in specific type view, NOT on All Documents) -->
                  <button
                    v-if="activeType && doc.status === 'draft'"
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
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  Building2,
  Eye,
  FileText,
  Loader2,
  Pencil,
  Plus,
  RotateCcw,
  Search,
  Trash2,
} from 'lucide-vue-next';
import StatCard from '../../components/ui/StatCard.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import InventoryDocumentDetailModal from './InventoryDocumentDetailModal.vue';
import InventoryDocumentFormModal from './InventoryDocumentFormModal.vue';
import api from '../../services/api';

const route = useRoute();
const { t } = useI18n();

const slugToTypeMap = {
  'goods-receipt': 'goods_receipt',
  'goods-delivery': 'goods_delivery',
  'stock-issue': 'stock_issue',
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
    goods_receipt: 'أذونات الاستلام المخزني',
    goods_delivery: 'أذونات التسليم المخزني',
    stock_issue: 'أذونات الصرف الداخلي',
    warehouse_transfer: 'أذونات التحويل المخزني',
    stock_adjustment: 'أذونات التسوية الجردية',
    inventory_return: 'أذونات المرتجع المخزني',
    stock_write_off: 'أذونات الإتلاف / الهالك',
    opening_balance: 'الأرصدة الافتتاحية للمخزون',
  };
  return titles[activeType.value] || t('inventory.title');
});

const pageSubtitle = computed(() => {
  if (!activeType.value) return 'سجل عام لاستعراض والبحث في كافة الحركات والمستندات المخزنية (للعرض والتدقيق فقط).';
  const subs = {
    goods_receipt: 'متابعة وإصدار أذونات استلام وتوريد الكتب والمنتجات إلى المخزن.',
    goods_delivery: 'توثيق وتسجيل أذونات تسليم الكتب والطلبيات للعملاء والجهات المستلمة.',
    stock_issue: 'إدارة أذونات الصرف الداخلي للأقسام والجهات الطالبة.',
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

const hasActiveFilters = computed(() => {
  return !!filters.search || !!filters.document_type || !!filters.status;
});

const typeFilterOptions = computed(() => {
  const options = [
    { value: '', label: 'كل أنواع المستندات' },
    { value: 'goods_receipt', label: 'إذن استلام مخزني' },
    { value: 'goods_delivery', label: 'إذن تسليم مخزني' },
    { value: 'stock_issue', label: 'إذن صرف داخلي' },
  ];
  if (warehouses.value.length > 1) {
    options.push({ value: 'warehouse_transfer', label: 'إذن تحويل مخزني' });
  }
  options.push(
    { value: 'stock_adjustment', label: 'إذن تسوية جردية' },
    { value: 'inventory_return', label: 'إذن مرتجع مخزني' },
    { value: 'stock_write_off', label: 'إذن إتلاف / هالك' },
    { value: 'opening_balance', label: 'رصيد افتتاحي' },
  );
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
      api.get('/warehouses', { params: { active_only: 1 } }),
      api.get('/products', { params: { is_active: 1, per_page: 500 } }),
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
  const labels = {
    goods_receipt: 'إذن استلام',
    goods_delivery: 'إذن تسليم',
    stock_issue: 'إذن صرف داخلي',
    warehouse_transfer: 'إذن تحويل',
    stock_adjustment: 'إذن تسوية',
    inventory_return: 'إذن مرتجع',
    stock_write_off: 'إذن إتلاف',
    opening_balance: 'رصيد افتتاحي',
  };
  return labels[type] || type;
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

watch(
  () => route.params.type,
  () => {
    filters.page = 1;
    filters.search = '';
    filters.status = '';
    filters.document_type = '';
    loadDocuments();
  }
);

onMounted(() => {
  loadDocuments();
  loadLookups();
});
</script>
