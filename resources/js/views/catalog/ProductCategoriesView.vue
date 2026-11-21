<template>
  <div class="space-y-6">
    <!-- Page header -->
    <PageHeader :title="$t('productCategories.title')" :subtitle="$t('productCategories.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('productCategories.addCategory') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Refresh error (stale data still shown) -->
    <div
      v-if="loadError && tree.length"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadCategories">
        {{ $t('productCategories.retry') }}
      </button>
    </div>

    <!-- Categories card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="search"
        :search-placeholder="$t('productCategories.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Status -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('common.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('productCategories.statusAll')"
                :active="statusFilter === 'all'"
                @click="statusFilter = 'all'"
              />
              <FilterChip
                :label="$t('productCategories.statusActive')"
                :active="statusFilter === 'active'"
                @click="statusFilter = 'active'"
              />
              <FilterChip
                :label="$t('productCategories.statusInactive')"
                :active="statusFilter === 'inactive'"
                @click="statusFilter = 'inactive'"
              />
            </div>
          </div>

          <!-- Scope -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('productCategories.nameCol') }}</label>
            <div class="relative">
              <select
                v-model="scopeFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('productCategories.nameCol')"
              >
                <option value="all">{{ $t('productCategories.filterAll') }}</option>
                <option value="top">{{ $t('productCategories.filterTopLevel') }}</option>
                <option value="sub">{{ $t('productCategories.filterSub') }}</option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Bulk actions -->
      <div
        v-if="selectedIds.length"
        class="flex flex-col gap-3 border-b border-[var(--border)] bg-[var(--crimson-soft)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
      >
        <p class="text-sm font-bold text-[var(--text)]">
          {{ $t('productCategories.selectedCount', { count: selectedIds.length }) }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[var(--crimson)] px-3.5 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
            @click="deleteSelected"
          >
            <Trash2 class="h-4 w-4" aria-hidden="true" />
            {{ $t('productCategories.bulkDelete') }}
          </button>
          <button
            type="button"
            class="inline-flex h-9 items-center rounded-lg border border-[var(--border)] bg-[var(--card)] px-3.5 text-sm font-semibold text-[var(--muted)] transition-colors hover:text-[var(--text)]"
            @click="selectedIds = []"
          >
            {{ $t('productCategories.clearSelection') }}
          </button>
        </div>
      </div>

      <!-- Table -->
      <div v-if="loading || filteredRows.length" class="overflow-x-auto">
        <table class="w-full min-w-[680px] table-auto text-start">
          <thead class="bg-[var(--bg)]">
            <tr>
              <th scope="col" class="w-12 px-4 py-3.5">
                <AppCheckbox
                  :checked="allFilteredSelected"
                  :indeterminate="someFilteredSelected && !allFilteredSelected"
                  :aria-label="$t('productCategories.selectAll')"
                  @change="toggleAll"
                />
              </th>
              <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('productCategories.nameCol') }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] sm:table-cell">
                {{ $t('productCategories.parentCol') }}
              </th>
              <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('productCategories.statusCol') }}
              </th>
              <th scope="col" class="w-24 px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('common.actions') }}
              </th>
            </tr>
          </thead>

          <tbody v-if="loading" aria-busy="true">
            <tr v-for="n in 5" :key="n" class="border-t border-[var(--border)]">
              <td colspan="5" class="px-4 py-4">
                <div class="h-4 w-48 animate-pulse rounded bg-[var(--bg)]"></div>
              </td>
            </tr>
          </tbody>

          <tbody v-else>
            <tr
              v-for="category in filteredRows"
              :key="category.id"
              class="border-t border-[var(--border)] transition-colors"
              :class="isSelected(category) ? 'bg-[var(--crimson-soft)]' : 'hover:bg-[var(--bg)]'"
            >
              <td class="px-4 py-3.5">
                <AppCheckbox
                  :checked="isSelected(category)"
                  :aria-label="$t('productCategories.selectRow', { name: category.name })"
                  @change="toggleRow(category)"
                />
              </td>

              <td class="px-4 py-3.5">
                <div class="flex items-center gap-2" :class="category.level === 1 ? 'ps-6' : ''">
                  <span
                    v-if="category.level === 1"
                    class="h-4 w-4 shrink-0 border-b border-s border-[var(--border)]"
                    aria-hidden="true"
                  ></span>
                  <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl"
                    :class="
                      category.level === 0
                        ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
                        : 'bg-[var(--bg)] text-[var(--muted)]'
                    "
                    aria-hidden="true"
                  >
                    <FolderTree v-if="category.level === 0" class="h-4 w-4" />
                    <CornerDownLeft v-else class="h-4 w-4 rtl:rotate-180" />
                  </span>
                  <span class="truncate text-sm font-bold text-[var(--text)]">{{ category.name }}</span>
                </div>
              </td>

              <td class="hidden px-4 py-3.5 sm:table-cell">
                <span v-if="category.parent_name" class="text-sm text-[var(--muted)]">{{ category.parent_name }}</span>
                <span v-else class="text-sm text-[var(--muted)]">{{ $t('productCategories.noParent') }}</span>
              </td>

              <td class="px-4 py-3.5">
                <StatusBadge :active="!!category.is_active" />
              </td>

              <td class="px-4 py-3.5">
                <div class="flex items-center gap-1.5">
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :aria-label="$t('productCategories.editCategory', { name: category.name })"
                    :title="$t('common.edit')"
                    @click="openEdit(category)"
                  >
                    <Pencil class="h-4 w-4" aria-hidden="true" />
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]"
                    :aria-label="$t('productCategories.deleteCategory', { name: category.name })"
                    :title="$t('common.delete')"
                    @click="deleteCategory(category)"
                  >
                    <Trash2 class="h-4 w-4" aria-hidden="true" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Error state -->
      <div v-else-if="loadError" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <AlertCircle class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">{{ $t('productCategories.loadErrorTitle') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">{{ loadError }}</p>
        <button
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="loadCategories"
        >
          <RotateCw class="h-4 w-4" aria-hidden="true" />
          {{ $t('productCategories.retry') }}
        </button>
      </div>

      <!-- Empty state -->
      <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg)] text-[var(--muted)]">
          <FolderTree v-if="!hasFilters" class="h-6 w-6" aria-hidden="true" />
          <SearchX v-else class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">
          {{ hasFilters ? $t('productCategories.noResultsTitle') : $t('productCategories.emptyTitle') }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ hasFilters ? $t('productCategories.noResultsDesc') : $t('productCategories.emptyDesc') }}
        </p>
        <button
          v-if="hasFilters"
          type="button"
          class="mt-5 inline-flex h-10 items-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40"
          @click="resetFilters"
        >
          {{ $t('productCategories.resetFilters') }}
        </button>
        <button
          v-else
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          {{ $t('productCategories.addCategory') }}
        </button>
      </div>

      <!-- Footer count -->
      <div
        v-if="!loading && filteredRows.length"
        class="border-t border-[var(--border)] px-4 py-4 text-sm text-[var(--muted)] sm:px-5"
      >
        {{ $t('productCategories.showingCount', { count: filteredRows.length }) }}
      </div>
    </div>

    <!-- Create / edit modal -->
    <CrudModal
      :show="showModal"
      :title="modalTitle"
      :mode="modalMode"
      :loading="modalLoading"
      size="lg"
      @close="closeModal"
      @save="saveRecord"
    >
      <CrudForm
        :schema="schema"
        :form-data="formData"
        :errors="formErrors"
        :related-options="{ parent_id: parentOptions }"
      >
        <template #extra-fields>
          <p v-if="parentLocked" class="rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 py-2.5 text-sm font-medium text-[var(--muted)]">
            {{ $t('productCategories.parentLockedHint') }}
          </p>
        </template>
      </CrudForm>
    </CrudModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ChevronDown,
  CornerDownLeft,
  FolderTree,
  Pencil,
  Plus,
  RotateCw,
  SearchX,
  Trash2,
} from 'lucide-vue-next';
import api from '../../services/api';
import alertService from '../../services/alert';
import { useNotificationStore } from '../../stores/notification';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import CrudModal from '../../components/crud/CrudModal.vue';
import CrudForm from '../../components/crud/CrudForm.vue';

const { t } = useI18n();
const notificationStore = useNotificationStore();

const tree = ref([]);
const loading = ref(true);
const loadError = ref('');

const search = ref('');
const statusFilter = ref('all');
const scopeFilter = ref('all');
const selectedIds = ref([]);

const showModal = ref(false);
const modalMode = ref('create');
const modalLoading = ref(false);
const activeRecordId = ref(null);
const formData = ref({});
const formErrors = ref({});

const allRows = computed(() =>
  tree.value.flatMap((parent) => [
    { ...parent, level: 0, parent_name: null },
    ...(parent.children || []).map((child) => ({ ...child, level: 1, parent_name: parent.name })),
  ])
);

const stats = computed(() => {
  const total = allRows.value.length;
  const topLevel = tree.value.length;

  return {
    total,
    topLevel,
    subCategories: total - topLevel,
    active: allRows.value.filter((row) => !!row.is_active).length,
    inactive: allRows.value.filter((row) => !row.is_active).length,
  };
});

const filteredRows = computed(() => {
  const term = search.value.trim().toLowerCase();

  return allRows.value.filter((row) => {
    if (term && !row.name.toLowerCase().includes(term) && !(row.parent_name || '').toLowerCase().includes(term)) {
      return false;
    }

    if (statusFilter.value === 'active' && !row.is_active) return false;
    if (statusFilter.value === 'inactive' && row.is_active) return false;

    if (scopeFilter.value === 'top' && row.level !== 0) return false;
    if (scopeFilter.value === 'sub' && row.level !== 1) return false;

    return true;
  });
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (statusFilter.value !== 'all') count++;
  if (scopeFilter.value !== 'all') count++;
  return count;
});

const hasFilters = computed(
  () => search.value.trim() !== '' || activeFiltersCount.value > 0
);

const parentLocked = computed(() => {
  const row = editingRow.value;

  if (modalMode.value !== 'edit' || !row) return false;

  return row.level === 1 || (row.children || []).length > 0;
});

const schema = computed(() => ({
  fields: [
    { name: 'name', label: 'productCategories.fields.name', type: 'text', required: true, col: 12 },
    {
      name: 'parent_id',
      label: 'productCategories.fields.parent',
      type: 'select',
      col: 12,
      placeholder: 'productCategories.noParent',
      readonly: parentLocked.value,
      help_text: parentLocked.value ? 'productCategories.topLevelOnly' : null,
    },
    { name: 'is_active', label: 'productCategories.fields.isActive', type: 'boolean', col: 12, default: true },
  ],
}));

const parentOptions = computed(() =>
  tree.value
    .filter((category) => category.id !== activeRecordId.value)
    .map((category) => ({ value: category.id, label: category.name }))
);

const editingRow = computed(() => allRows.value.find((row) => row.id === activeRecordId.value) || null);

const modalTitle = computed(
  () =>
    modalMode.value === 'create'
      ? t('productCategories.addCategory')
      : `${t('common.edit')} ${t('productCategories.nameCol')}`
);

const isSelected = (category) => selectedIds.value.includes(category.id);

const allFilteredSelected = computed(
  () => filteredRows.value.length > 0 && filteredRows.value.every((row) => selectedIds.value.includes(row.id))
);
const someFilteredSelected = computed(() => filteredRows.value.some((row) => selectedIds.value.includes(row.id)));

const loadCategories = async () => {
  loading.value = true;
  loadError.value = '';

  try {
    const response = await api.get('/product-categories');
    tree.value = response.data?.data || [];
    selectedIds.value = selectedIds.value.filter((id) => allRows.value.some((row) => row.id === id));
  } catch {
    loadError.value = t('productCategories.loadError');
  } finally {
    loading.value = false;
  }
};

const openCreate = () => {
  modalMode.value = 'create';
  activeRecordId.value = null;
  formErrors.value = {};
  formData.value = { name: '', parent_id: null, is_active: true };
  showModal.value = true;
};

const openEdit = (category) => {
  modalMode.value = 'edit';
  activeRecordId.value = category.id;
  formErrors.value = {};
  formData.value = {
    name: category.name ?? '',
    parent_id: category.parent_id ?? null,
    is_active: !!category.is_active,
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  formErrors.value = {};
};

const saveRecord = async () => {
  modalLoading.value = true;
  formErrors.value = {};

  const payload = { ...formData.value };

  // The backend prohibits changing the parent of a subcategory, and a
  // top-level category that already has children must stay top-level.
  if (parentLocked.value) {
    delete payload.parent_id;
  }

  try {
    const response =
      modalMode.value === 'create'
        ? await api.post('/product-categories', payload)
        : await api.put(`/product-categories/${activeRecordId.value}`, payload);

    notificationStore.success(response.data.message || t('common.successSave'), t('common.successTitle'));
    closeModal();
    await loadCategories();
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      formErrors.value = err.response.data.errors;
      notificationStore.error(t('common.validationError'), t('common.errorTitle'));
    } else {
      notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
    }
  } finally {
    modalLoading.value = false;
  }
};

const toggleAll = () => {
  const ids = filteredRows.value.map((row) => row.id);

  if (allFilteredSelected.value) {
    selectedIds.value = selectedIds.value.filter((id) => !ids.includes(id));
    return;
  }

  selectedIds.value = Array.from(new Set([...selectedIds.value, ...ids]));
};

const toggleRow = (category) => {
  if (selectedIds.value.includes(category.id)) {
    selectedIds.value = selectedIds.value.filter((id) => id !== category.id);
    return;
  }

  selectedIds.value = [...selectedIds.value, category.id];
};

const deleteCategory = async (category) => {
  const confirmed = await alertService.confirmDelete({
    title: t('productCategories.deleteTitle'),
    text: t('productCategories.deleteConfirm', { name: category.name }),
    confirmButtonText: t('common.delete'),
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    const response = await api.delete(`/product-categories/${category.id}`);
    selectedIds.value = selectedIds.value.filter((id) => id !== category.id);
    notificationStore.success(response.data.message || t('common.successDelete'), t('common.successTitle'));
    await loadCategories();
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }
};

const deleteSelected = async () => {
  if (!selectedIds.value.length) return;

  const confirmed = await alertService.confirmDelete({
    title: t('productCategories.bulkDeleteTitle'),
    text: t('productCategories.bulkDeleteConfirm', { count: selectedIds.value.length }),
    confirmButtonText: `${t('common.delete')} (${selectedIds.value.length})`,
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    await api.delete(`/product-categories/${selectedIds.value.join(',')}`);
    notificationStore.success(
      t('productCategories.bulkDeleted', { count: selectedIds.value.length }),
      t('common.successTitle')
    );
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }

  selectedIds.value = [];
  await loadCategories();
};

const resetFilters = () => {
  search.value = '';
  statusFilter.value = 'all';
  scopeFilter.value = 'all';
};

onMounted(loadCategories);
</script>
