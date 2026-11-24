<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('expenses.categoriesTitle')" :subtitle="$t('expenses.categoriesSubtitle')">
      <template #actions>
        <router-link
          to="/expenses"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <ReceiptText class="h-[18px] w-[18px] text-[var(--muted)]" aria-hidden="true" />
          <span>{{ $t('expenses.backToExpenses') }}</span>
        </router-link>

        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('expenses.newCategory') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Toolbar -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('common.searchPlaceholder')"
        :active-filters-count="0"
        @search="debouncedSearch"
        @reset="resetFilters"
      />

      <!-- Bulk Actions -->
      <BulkActionsBar
        :count="selectedIds.length"
        :loading="bulkDeleting"
        @delete="deleteSelectedCategories"
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
              <th class="px-5 py-3.5 text-start">
                <button
                  type="button"
                  class="inline-flex items-center gap-1 hover:text-[var(--text)] cursor-pointer"
                  @click="toggleSort('name')"
                >
                  <span>{{ $t('expenses.categoryName') }}</span>
                  <ArrowUpDown class="h-3.5 w-3.5" />
                </button>
              </th>
              <th class="px-5 py-3.5 text-start">{{ $t('expenses.description') }}</th>
              <th class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <!-- Loading State -->
            <tr v-if="loading && categories.length === 0">
              <td colspan="4" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <!-- Empty State -->
            <tr v-else-if="categories.length === 0">
              <td colspan="4" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
                    <Tags class="h-7 w-7" />
                  </div>
                  <h3 class="mt-4 text-base font-extrabold text-[var(--text)]">
                    {{ $t('expenses.noCategoriesFound') }}
                  </h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">
                    {{ $t('expenses.noCategoriesHint') }}
                  </p>
                  <button
                    type="button"
                    class="mt-4 inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
                    @click="openCreateModal"
                  >
                    <Plus class="h-4 w-4" />
                    <span>{{ $t('expenses.newCategory') }}</span>
                  </button>
                </div>
              </td>
            </tr>

            <!-- Rows -->
            <tr
              v-for="cat in categories"
              :key="cat.id"
              class="transition-colors"
              :class="isSelected(cat) ? 'bg-[var(--crimson-soft)]' : 'hover:bg-[var(--bg)]/50'"
            >
              <td class="px-4 py-4">
                <AppCheckbox
                  :checked="isSelected(cat)"
                  :disabled="(cat.expenses_count || 0) > 0"
                  :aria-label="$t('common.selectRow')"
                  @change="toggleRow(cat)"
                />
              </td>
              <td class="px-5 py-4">
                <button
                  type="button"
                  class="font-bold text-[var(--text)] hover:text-[var(--crimson)] hover:underline text-start cursor-pointer"
                  @click="openDetailModal(cat)"
                >
                  {{ cat.name }}
                </button>
              </td>
              <td class="px-5 py-4 text-[var(--muted)] max-w-md truncate">
                {{ cat.description || '—' }}
              </td>
              <td class="px-5 py-4 text-end">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :title="$t('expenses.view')"
                    @click="openDetailModal(cat)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :title="$t('common.edit')"
                    @click="openEditModal(cat)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    :disabled="(cat.expenses_count || 0) > 0"
                    :title="(cat.expenses_count || 0) > 0
                      ? $t('expenses.cannotDeleteUsedCategory')
                      : $t('common.delete')"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition-colors"
                    :class="(cat.expenses_count || 0) > 0
                      ? 'text-[var(--muted)]/40 cursor-not-allowed'
                      : 'text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 cursor-pointer'"
                    @click="handleDelete(cat)"
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
        :items-count="categories.length"
        :per-page="meta.per_page"
        :loading="loading"
        @update:per-page="meta.per_page = $event"
        @change-per-page="fetchCategories(1)"
        @change-page="fetchCategories"
      />
    </div>

    <!-- Create / Edit Modal -->
    <div
      v-if="showFormModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4"
      @click.self="closeFormModal"
    >
      <div class="w-full max-w-lg rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xl overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--border)]">
          <h2 class="text-lg font-extrabold text-[var(--text)]">
            {{ editingCategory ? $t('expenses.editCategory') : $t('expenses.newCategory') }}
          </h2>
          <button
            type="button"
            class="p-1.5 rounded-lg text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)] cursor-pointer"
            @click="closeFormModal"
          >
            <X class="w-5 h-5" />
          </button>
        </div>

        <form class="p-6 space-y-4" @submit.prevent="saveCategory">
          <div>
            <label class="block text-sm font-bold text-[var(--text)] mb-1.5">
              {{ $t('expenses.categoryName') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <input
              v-model="form.name"
              type="text"
              required
              maxlength="150"
              :placeholder="$t('expenses.categoryNamePlaceholder')"
              class="h-11 w-full rounded-xl border bg-[var(--bg)] px-3.5 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              :class="formErrors.name ? 'border-rose-500' : 'border-[var(--border)]'"
            />
            <p v-if="formErrors.name" class="text-xs text-rose-500 mt-1">
              {{ formErrors.name[0] }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-bold text-[var(--text)] mb-1.5">
              {{ $t('expenses.description') }}
            </label>
            <textarea
              v-model="form.description"
              rows="3"
              maxlength="1000"
              :placeholder="$t('expenses.categoryDescriptionPlaceholder')"
              class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 py-2.5 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            />
            <p v-if="formErrors.description" class="text-xs text-rose-500 mt-1">
              {{ formErrors.description[0] }}
            </p>
          </div>

          <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-[var(--border)]">
            <button
              type="button"
              class="h-10 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-4 text-sm font-semibold text-[var(--text)] hover:bg-[var(--card)] cursor-pointer"
              @click="closeFormModal"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              :disabled="saving"
              class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
            >
              <Loader2 v-if="saving" class="w-4 h-4 animate-spin" />
              <span>{{ editingCategory ? $t('common.save') : $t('common.create') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Category Detail Drawer -->
    <AppDrawer
      :open="Boolean(detailCategory)"
      width-class="max-w-lg sm:max-w-xl"
      side="end"
      @close="detailCategory = null"
    >
      <template #header>
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-start gap-3">
            <div
              class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
            >
              <Tags class="h-6 w-6" />
            </div>

            <div>
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl">
                {{ detailCategory?.name || '—' }}
              </h2>
              <p class="mt-1 text-xs text-[var(--muted)]">
                {{ $t('expenses.categoryDetails') }}
              </p>
            </div>
          </div>

          <button
            type="button"
            class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            :title="$t('common.close')"
            @click="detailCategory = null"
          >
            <X class="h-5 w-5" />
          </button>
        </div>
      </template>

      <template #actions>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <span class="text-xs font-bold text-[var(--muted)]">
            {{ $t('common.actions') }}:
          </span>

          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)] cursor-pointer"
              @click="() => { const cat = detailCategory; detailCategory = null; openEditModal(cat); }"
            >
              <Pencil class="h-3.5 w-3.5 text-[var(--muted)]" />
              <span>{{ $t('common.edit') }}</span>
            </button>

            <button
              v-if="detailCategory && (detailCategory.expenses_count || 0) === 0"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-50 px-3.5 py-1.5 text-xs font-bold text-rose-700 transition-colors hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400 cursor-pointer"
              @click="() => { const cat = detailCategory; detailCategory = null; handleDelete(cat); }"
            >
              <Trash2 class="h-3.5 w-3.5" />
              <span>{{ $t('common.delete') }}</span>
            </button>
          </div>
        </div>
      </template>

      <div v-if="detailCategory" class="space-y-4 text-sm text-[var(--text)]">
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="text-xs font-bold text-[var(--muted)] mb-1.5">{{ $t('expenses.categoryName') }}</div>
          <p class="text-base font-extrabold text-[var(--text)]">
            {{ detailCategory.name }}
          </p>
        </div>

        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="text-xs font-bold text-[var(--muted)] mb-1.5">{{ $t('expenses.description') }}</div>
          <p class="text-sm text-[var(--text)] whitespace-pre-line">
            {{ detailCategory.description || '—' }}
          </p>
        </div>
      </div>
    </AppDrawer>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowUpDown,
  Eye,
  Loader2,
  Pencil,
  Plus,
  ReceiptText,
  Tags,
  Trash2,
  X,
} from 'lucide-vue-next';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import BulkActionsBar from '../../components/ui/BulkActionsBar.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import { deleteRecordsSequentially, useBulkSelection } from '../../composables/useBulkSelection';
import api from '../../services/api';
import { confirmDelete, showError, showSuccess } from '../../services/alert';

const { t } = useI18n();

const loading = ref(false);
const saving = ref(false);
const categories = ref([]);
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const filters = reactive({
  search: '',
  sort_by: 'id',
  sort_order: 'desc',
});

let searchTimer = null;
function debouncedSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    fetchCategories(1);
  }, 300);
}

const showFormModal = ref(false);
const editingCategory = ref(null);
const detailCategory = ref(null);
const form = reactive({
  name: '',
  description: '',
});
const formErrors = ref({});

async function fetchCategories(page = 1) {
  clearSelection();
  loading.value = true;
  try {
    const res = await api.get('/expense-categories', {
      params: {
        page,
        per_page: meta.per_page,
        search: filters.search || undefined,
        sort_by: filters.sort_by,
        sort_order: filters.sort_order,
      },
    });
    categories.value = res.data?.data ?? [];
    if (res.data?.meta) {
      Object.assign(meta, res.data.meta);
    }
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  } finally {
    loading.value = false;
  }
}

function toggleSort(column) {
  if (filters.sort_by === column) {
    filters.sort_order = filters.sort_order === 'asc' ? 'desc' : 'asc';
  } else {
    filters.sort_by = column;
    filters.sort_order = column === 'name' ? 'asc' : 'desc';
  }
  fetchCategories(1);
}

function resetFilters() {
  filters.search = '';
  filters.sort_by = 'id';
  filters.sort_order = 'desc';
  fetchCategories(1);
}

function openCreateModal() {
  editingCategory.value = null;
  form.name = '';
  form.description = '';
  formErrors.value = {};
  showFormModal.value = true;
}

function openEditModal(category) {
  editingCategory.value = category;
  form.name = category.name || '';
  form.description = category.description || '';
  formErrors.value = {};
  showFormModal.value = true;
}

async function openDetailModal(category) {
  try {
    const res = await api.get(`/expense-categories/${category.id}`);
    detailCategory.value = res.data?.data ?? category;
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  }
}

function closeFormModal() {
  showFormModal.value = false;
  editingCategory.value = null;
  formErrors.value = {};
}

async function saveCategory() {
  saving.value = true;
  formErrors.value = {};
  try {
    const payload = {
      name: form.name,
      description: form.description || null,
    };

    if (editingCategory.value) {
      await api.put(`/expense-categories/${editingCategory.value.id}`, payload);
      showSuccess({ text: t('expenses.categoryUpdated') });
    } else {
      await api.post('/expense-categories', payload);
      showSuccess({ text: t('expenses.categoryCreated') });
    }

    closeFormModal();
    await fetchCategories(editingCategory.value ? meta.current_page : 1);
  } catch (err) {
    if (err?.response?.status === 422 && err.response?.data?.errors) {
      formErrors.value = err.response.data.errors;
    } else {
      showError({ text: err?.response?.data?.message || t('common.error') });
    }
  } finally {
    saving.value = false;
  }
}

async function handleDelete(category) {
  if ((category.expenses_count || 0) > 0) {
    showError({ text: t('expenses.cannotDeleteUsedCategory') });
    return;
  }

  const confirmed = await confirmDelete({
    text: t('expenses.confirmDeleteCategory', { name: category.name }),
  });
  if (!confirmed) return;

  try {
    await api.delete(`/expense-categories/${category.id}`);
    removeId(category.id);
    showSuccess({ text: t('expenses.categoryDeleted') });
    await fetchCategories(meta.current_page);
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
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
} = useBulkSelection(categories, { canSelect: (cat) => (cat.expenses_count || 0) === 0 });

async function deleteSelectedCategories() {
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
    (id) => api.delete(`/expense-categories/${id}`)
  );
  bulkDeleting.value = false;

  clearSelection();
  await fetchCategories(meta.current_page);

  if (deletedCount > 0 && failedCount === 0) {
    showSuccess({ text: t('common.bulkDeleteSuccess', { count: deletedCount }) });
  } else {
    showError({ text: firstErrorMessage || t('common.bulkDeletePartialError', { count: failedCount }) });
  }
}

onMounted(() => {
  fetchCategories(1);
});
</script>
