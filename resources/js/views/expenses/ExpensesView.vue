<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <PageHeader :title="$t('expenses.title')" :subtitle="$t('expenses.subtitle')">
      <template #actions>
        <router-link
          to="/expense-categories"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Tags class="h-[18px] w-[18px] text-[var(--muted)]" aria-hidden="true" />
          <span>{{ $t('expenses.categoriesTitle') }}</span>
        </router-link>

        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('expenses.newExpense') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Filter Toolbar & Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('common.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedSearch"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Status Chips -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('expenses.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('common.all')"
                :active="filters.status === ''"
                @click="setStatusFilter('')"
              />
              <FilterChip
                :label="$t('expenses.statuses.draft')"
                :active="filters.status === 'draft'"
                @click="setStatusFilter('draft')"
              />
              <FilterChip
                :label="$t('expenses.statuses.posted')"
                :active="filters.status === 'posted'"
                @click="setStatusFilter('posted')"
              />
            </div>
          </div>

          <!-- Category -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('expenses.category') }}</label>
            <SearchableSelect
              v-model="filters.expense_category_id"
              :options="categoryOptions"
              :placeholder="$t('common.all')"
              :clearable="true"
              @update:modelValue="fetchExpenses(1)"
            />
          </div>

          <!-- Contact -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('expenses.contact') }}</label>
            <SearchableSelect
              v-model="filters.contact_id"
              :options="contactOptions"
              :placeholder="$t('common.all')"
              :clearable="true"
              :paginate="10"
              @update:modelValue="fetchExpenses(1)"
            />
          </div>

          <!-- Date Range -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateFrom') }}</label>
              <input
                v-model="filters.expense_date_from"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="fetchExpenses(1)"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('common.dateTo') }}</label>
              <input
                v-model="filters.expense_date_to"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                @change="fetchExpenses(1)"
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
              <th class="px-5 py-3.5 text-start">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 hover:text-[var(--text)] cursor-pointer"
                  @click="toggleSort('expense_number')"
                >
                  <span>{{ $t('expenses.expenseNumber') }}</span>
                  <ArrowUpDown class="h-3.5 w-3.5" />
                </button>
              </th>
              <th class="px-5 py-3.5 text-center">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 hover:text-[var(--text)] cursor-pointer"
                  @click="toggleSort('expense_date')"
                >
                  <span>{{ $t('expenses.expenseDate') }}</span>
                  <ArrowUpDown class="h-3.5 w-3.5" />
                </button>
              </th>
              <th class="px-5 py-3.5 text-start">{{ $t('expenses.category') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('expenses.contact') }}</th>
              <th class="px-5 py-3.5 text-center">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 hover:text-[var(--text)] cursor-pointer"
                  @click="toggleSort('status')"
                >
                  <span>{{ $t('expenses.status') }}</span>
                  <ArrowUpDown class="h-3.5 w-3.5" />
                </button>
              </th>
              <th class="px-5 py-3.5 text-center">{{ $t('expenses.itemsCount') }}</th>
              <th class="px-5 py-3.5 text-center">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 hover:text-[var(--text)] cursor-pointer"
                  @click="toggleSort('total')"
                >
                  <span>{{ $t('expenses.total') }}</span>
                  <ArrowUpDown class="h-3.5 w-3.5" />
                </button>
              </th>
              <th class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading State -->
            <tr v-if="loading && expenses.length === 0">
              <td colspan="8" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="expenses.length === 0">
              <td colspan="8" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
                    <ReceiptText class="h-7 w-7" />
                  </div>
                  <h3 class="mt-4 text-base font-extrabold text-[var(--text)]">
                    {{ $t('expenses.noExpensesFound') }}
                  </h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">
                    {{ $t('expenses.noExpensesHint') }}
                  </p>
                  <button
                    type="button"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
                    @click="openCreateModal"
                  >
                    <Plus class="h-4 w-4" />
                    <span>{{ $t('expenses.newExpense') }}</span>
                  </button>
                </div>
              </td>
            </tr>

            <!-- Rows -->
            <tr
              v-for="expense in expenses"
              :key="expense.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-5 py-4 whitespace-nowrap">
                <button
                  type="button"
                  class="font-mono text-sm font-black text-[var(--crimson)] hover:underline cursor-pointer"
                  dir="ltr"
                  @click="openDetailModal(expense)"
                >
                  {{ expense.expense_number }}
                </button>
              </td>
              <td class="px-5 py-4 text-center text-xs font-mono font-bold text-[var(--text)] whitespace-nowrap">
                {{ formatDateOnly(expense.expense_date) }}
              </td>
              <td class="px-5 py-4">
                <div class="font-bold text-[var(--text)]">{{ expense.category?.name || '—' }}</div>
              </td>
              <td class="px-5 py-4 text-[var(--muted)]">
                {{ expense.contact?.name || '—' }}
              </td>
              <td class="px-5 py-4 text-center whitespace-nowrap">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
                  :class="expense.status === 'posted'
                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                    : 'bg-amber-500/15 text-amber-700 dark:text-amber-400'"
                >
                  <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                  {{ $t(`expenses.statuses.${expense.status}`) }}
                </span>
              </td>
              <td class="px-5 py-4 text-center">
                <span class="inline-flex rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--text)] border border-[var(--border)]">
                  {{ expense.items_count ?? 0 }}
                </span>
              </td>
              <td class="px-5 py-4 text-center font-mono text-sm font-black text-[var(--text)] whitespace-nowrap">
                {{ formatAmount(expense.total) }}
                <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </td>
              <td class="px-5 py-4 text-end whitespace-nowrap">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :title="$t('expenses.view')"
                    @click="openDetailModal(expense)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>
                  <button
                    v-if="expense.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :title="$t('common.edit')"
                    @click="openEditModal(expense)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    v-if="expense.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 cursor-pointer"
                    :title="$t('common.delete')"
                    @click="handleDelete(expense)"
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
          {{ $t('common.page') }} {{ meta.current_page }} {{ $t('common.of') }} {{ meta.last_page }} ({{ meta.total }})
        </span>

        <div class="flex items-center gap-2">
          <button
            type="button"
            :disabled="meta.current_page <= 1"
            class="rounded-lg border border-[var(--border)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
            @click="fetchExpenses(meta.current_page - 1)"
          >
            {{ $t('common.previous') }}
          </button>
          <button
            type="button"
            :disabled="meta.current_page >= meta.last_page"
            class="rounded-lg border border-[var(--border)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-40 cursor-pointer disabled:cursor-not-allowed"
            @click="fetchExpenses(meta.current_page + 1)"
          >
            {{ $t('common.next') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Create / Edit Form Modal -->
    <ExpenseFormModal
      :show="showFormModal"
      :expense="editingExpense"
      :categories="categories"
      :contacts="contacts"
      @close="closeFormModal"
      @saved="onExpenseSaved"
      @preview-attachment="openAttachmentPreview"
    />

    <!-- Detail Drawer -->
    <ExpenseDetailModal
      :show="showDetailModal"
      :expense="detailExpense"
      @close="closeDetailModal"
      @edit="editFromDetail"
      @delete="deleteFromDetail"
      @preview-attachment="openAttachmentPreview"
    />

    <!-- Authenticated Private Attachment Preview Modal -->
    <div
      v-if="previewState.show"
      class="fixed inset-0 z-[60] flex items-center justify-center bg-black/75 backdrop-blur-xs p-4"
      @click.self="closeAttachmentPreview"
    >
      <div class="w-full max-w-4xl max-h-[90vh] rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 py-3.5 border-b border-[var(--border)]">
          <div class="font-bold text-sm text-[var(--text)] truncate">
            {{ previewState.name }}
          </div>
          <div class="flex items-center gap-2">
            <a
              v-if="previewState.blobUrl"
              :href="previewState.blobUrl"
              :download="previewState.name"
              class="inline-flex items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 text-xs font-semibold text-[var(--text)] hover:bg-[var(--card)]"
            >
              <Download class="w-3.5 h-3.5" />
              <span>{{ $t('expenses.downloadAttachment') }}</span>
            </a>
            <button
              type="button"
              class="p-1.5 rounded-lg text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)] cursor-pointer"
              @click="closeAttachmentPreview"
            >
              <X class="w-5 h-5" />
            </button>
          </div>
        </div>

        <div class="flex-1 overflow-auto bg-[var(--bg)] p-4 flex items-center justify-center min-h-[360px]">
          <Loader2 v-if="previewState.loading" class="w-8 h-8 animate-spin text-[var(--crimson)]" />
          <iframe
            v-else-if="previewState.blobUrl && previewState.isPdf"
            :src="previewState.blobUrl"
            class="w-full h-[72vh] rounded-xl border border-[var(--border)] bg-white"
          />
          <img
            v-else-if="previewState.blobUrl"
            :src="previewState.blobUrl"
            :alt="previewState.name"
            class="max-w-full max-h-[72vh] object-contain rounded-xl shadow"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowUpDown,
  Download,
  Eye,
  Loader2,
  Pencil,
  Plus,
  ReceiptText,
  Tags,
  Trash2,
  X,
} from 'lucide-vue-next';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import ExpenseFormModal from './ExpenseFormModal.vue';
import ExpenseDetailModal from './ExpenseDetailModal.vue';
import api from '../../services/api';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import { formatDateOnly } from '../../utils/date';

const { t } = useI18n();

const loading = ref(false);
const expenses = ref([]);
const categories = ref([]);
const contacts = ref([]);

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 15,
  total: 0,
});

const filters = reactive({
  search: '',
  expense_category_id: '',
  contact_id: '',
  status: '',
  expense_date_from: '',
  expense_date_to: '',
  sort_by: 'id',
  sort_order: 'desc',
});

let searchTimer = null;
function debouncedSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    fetchExpenses(1);
  }, 300);
}

const showFormModal = ref(false);
const editingExpense = ref(null);
const showDetailModal = ref(false);
const detailExpense = ref(null);

const previewState = reactive({
  show: false,
  loading: false,
  name: '',
  isPdf: false,
  blobUrl: '',
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.status) count++;
  if (filters.expense_category_id) count++;
  if (filters.contact_id) count++;
  if (filters.expense_date_from) count++;
  if (filters.expense_date_to) count++;
  return count;
});

const categoryOptions = computed(() =>
  categories.value.map((cat) => ({ value: cat.id, label: cat.name }))
);

const contactOptions = computed(() =>
  contacts.value.map((c) => ({
    value: c.id,
    label: c.name,
  }))
);

function formatAmount(value) {
  const num = Number(value ?? 0);
  return Number.isFinite(num) ? num.toFixed(2) : '0.00';
}

function setStatusFilter(status) {
  filters.status = status;
  fetchExpenses(1);
}

async function fetchExpenses(page = 1) {
  loading.value = true;
  try {
    const res = await api.get('/expenses', {
      params: {
        page,
        per_page: meta.per_page,
        search: filters.search || undefined,
        expense_category_id: filters.expense_category_id || undefined,
        contact_id: filters.contact_id || undefined,
        status: filters.status || undefined,
        expense_date_from: filters.expense_date_from || undefined,
        expense_date_to: filters.expense_date_to || undefined,
        sort_by: filters.sort_by,
        sort_order: filters.sort_order,
      },
    });
    expenses.value = res.data?.data ?? [];
    if (res.data?.meta) {
      Object.assign(meta, res.data.meta);
    }
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  } finally {
    loading.value = false;
  }
}

async function fetchLookups() {
  try {
    const [catRes, contactRes] = await Promise.all([
      api.get('/expense-categories', { params: { all: 1 } }),
      api.get('/contacts', { params: { per_page: 100 } }),
    ]);
    categories.value = catRes.data?.data ?? [];
    contacts.value = contactRes.data?.data ?? [];
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  }
}

function toggleSort(column) {
  if (filters.sort_by === column) {
    filters.sort_order = filters.sort_order === 'asc' ? 'desc' : 'asc';
  } else {
    filters.sort_by = column;
    filters.sort_order = 'desc';
  }
  fetchExpenses(1);
}

function resetFilters() {
  filters.search = '';
  filters.expense_category_id = '';
  filters.contact_id = '';
  filters.status = '';
  filters.expense_date_from = '';
  filters.expense_date_to = '';
  filters.sort_by = 'id';
  filters.sort_order = 'desc';
  fetchExpenses(1);
}

function openCreateModal() {
  editingExpense.value = null;
  showFormModal.value = true;
}

async function openEditModal(expense) {
  if (expense.status !== 'draft') {
    showError({ text: t('expenses.cannotEditPosted') });
    return;
  }
  try {
    const res = await api.get(`/expenses/${expense.id}`);
    editingExpense.value = res.data?.data ?? expense;
    showFormModal.value = true;
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  }
}

async function openDetailModal(expense) {
  try {
    const res = await api.get(`/expenses/${expense.id}`);
    detailExpense.value = res.data?.data ?? expense;
    showDetailModal.value = true;
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  }
}

function closeFormModal() {
  showFormModal.value = false;
  editingExpense.value = null;
}

function closeDetailModal() {
  showDetailModal.value = false;
  detailExpense.value = null;
}

async function onExpenseSaved(savedExpense) {
  const wasEdit = Boolean(editingExpense.value);
  closeFormModal();
  if (showDetailModal.value && savedExpense?.id && detailExpense.value?.id === savedExpense.id) {
    detailExpense.value = savedExpense;
  }
  await fetchExpenses(wasEdit ? meta.current_page : 1);
}

function editFromDetail(expense) {
  closeDetailModal();
  openEditModal(expense);
}

async function deleteFromDetail(expense) {
  const deleted = await handleDelete(expense);
  if (deleted) {
    closeDetailModal();
  }
}

async function handleDelete(expense) {
  if (expense.status !== 'draft') {
    showError({ text: t('expenses.cannotDeletePosted') });
    return false;
  }

  const confirmed = await confirmDelete({
    text: t('expenses.confirmDeleteExpense', { number: expense.expense_number }),
  });
  if (!confirmed) return false;

  try {
    await api.delete(`/expenses/${expense.id}`);
    showSuccess({ text: t('expenses.deleted') });
    await fetchExpenses(meta.current_page);
    return true;
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
    return false;
  }
}

async function openAttachmentPreview({ expenseId, itemId, attachment }) {
  closeAttachmentPreview();
  previewState.show = true;
  previewState.loading = true;
  previewState.name = attachment.original_name || 'attachment';
  previewState.isPdf = String(attachment.mime_type || '').toLowerCase().includes('pdf');

  try {
    const res = await api.get(
      `/expenses/${expenseId}/items/${itemId}/attachments/${attachment.id}/view`,
      { responseType: 'blob' }
    );
    const mime = attachment.mime_type || res.data?.type || 'application/octet-stream';
    const blob = new Blob([res.data], { type: mime });
    previewState.blobUrl = URL.createObjectURL(blob);
  } catch (err) {
    closeAttachmentPreview();
    showError({ text: err?.response?.data?.message || t('common.error') });
  } finally {
    previewState.loading = false;
  }
}

function closeAttachmentPreview() {
  if (previewState.blobUrl) {
    URL.revokeObjectURL(previewState.blobUrl);
  }
  previewState.show = false;
  previewState.loading = false;
  previewState.name = '';
  previewState.isPdf = false;
  previewState.blobUrl = '';
}

onMounted(() => {
  fetchLookups();
  fetchExpenses(1);
});

onBeforeUnmount(() => {
  closeAttachmentPreview();
});
</script>
