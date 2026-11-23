<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('chartOfAccounts.title')" :subtitle="$t('chartOfAccounts.subtitle')">
      <template #actions>
        <router-link
          to="/accounting/settings"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Settings2 class="h-[18px] w-[18px] text-[var(--muted)]" aria-hidden="true" />
          <span>{{ $t('nav.accountingSettings') }}</span>
        </router-link>

        <button
          v-if="canCreate"
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('chartOfAccounts.addAccount') }}</span>
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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadAccounts(meta.current_page)">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('chartOfAccounts.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">
              {{ $t('chartOfAccounts.accountType') }}
            </label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('common.all')"
                :active="filters.account_type === ''"
                @click="filters.account_type = ''"
              />
              <FilterChip
                v-for="typeOpt in accountTypeOptions"
                :key="typeOpt.value"
                :label="typeOpt.label"
                :active="filters.account_type === typeOpt.value"
                @click="filters.account_type = typeOpt.value"
              />
            </div>
          </div>

          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">
              {{ $t('chartOfAccounts.accountPurpose') }}
            </label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('common.all')"
                :active="filters.account_purpose === ''"
                @click="filters.account_purpose = ''"
              />
              <FilterChip
                v-for="purposeOpt in accountPurposeOptions"
                :key="purposeOpt.value"
                :label="purposeOpt.label"
                :active="filters.account_purpose === purposeOpt.value"
                @click="filters.account_purpose = purposeOpt.value"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Account Type Quick Bar -->
      <div class="flex flex-wrap items-center gap-2 border-b border-[var(--border)] bg-[var(--bg)]/50 px-4 py-2.5 sm:px-5">
        <FilterChip
          :label="$t('common.all')"
          :active="filters.account_type === ''"
          @click="filters.account_type = ''"
        />
        <FilterChip
          v-for="typeOpt in accountTypeOptions"
          :key="`bar-${typeOpt.value}`"
          :label="typeOpt.label"
          :active="filters.account_type === typeOpt.value"
          @click="filters.account_type = typeOpt.value"
        />
      </div>

      <!-- Bulk Actions -->
      <BulkActionsBar
        v-if="canDelete"
        :count="selectedIds.length"
        :loading="bulkDeleting"
        @delete="deleteSelectedAccounts"
        @clear="clearSelection"
      />

      <!-- Loading -->
      <div v-if="loading && accounts.length === 0" class="p-12 text-center">
        <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('common.loading') }}</span>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else-if="accounts.length === 0" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <Landmark class="h-7 w-7" />
        </span>
        <h2 class="mt-4 text-base font-extrabold text-[var(--text)]">{{ $t('chartOfAccounts.emptyTitle') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm text-[var(--muted)]">{{ $t('chartOfAccounts.emptyDesc') }}</p>
      </div>

      <!-- Five Account-Type Sections -->
      <div v-else class="divide-y divide-[var(--border)]">
        <section
          v-for="group in visibleGroups"
          :key="group.type"
          class="bg-[var(--card)]"
        >
          <!-- Group Header -->
          <div class="flex items-center justify-between gap-3 border-b border-[var(--border)]/70 bg-[var(--bg)]/70 px-4 py-3 sm:px-5">
            <div class="flex items-center gap-2.5">
              <AppCheckbox
                v-if="canDelete"
                :checked="isGroupAllSelected(group.items)"
                :indeterminate="isGroupSomeSelected(group.items) && !isGroupAllSelected(group.items)"
                :disabled="!group.items.some((acc) => acc.can_delete)"
                :aria-label="$t('common.selectAll')"
                @change="toggleGroupSelection(group.items)"
              />
              <span
                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                :class="typeBadgeClass(group.type)"
              >
                {{ group.label }}
              </span>
              <span class="text-xs font-bold text-[var(--muted)]">
                {{ $t('chartOfAccounts.sectionCount', { count: group.items.length }) }}
              </span>
            </div>
          </div>

          <!-- Empty Group Notice (when a specific type filter is selected and empty, or all 5 shown) -->
          <div v-if="group.items.length === 0" class="px-5 py-4 text-xs text-[var(--muted)]">
            {{ $t('chartOfAccounts.noAccountsInSection') }}
          </div>

          <!-- Accounts in Group -->
          <div v-else class="divide-y divide-[var(--border)]/60">
            <div
              v-for="account in group.items"
              :key="account.id"
              class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 transition-colors sm:px-5"
              :class="isSelected(account) ? 'bg-[var(--crimson-soft)]' : 'hover:bg-[var(--bg)]/60'"
            >
              <!-- Start: Checkbox, Icon, Code, Name, Description -->
              <div class="flex min-w-0 flex-1 items-center gap-3">
                <AppCheckbox
                  v-if="canDelete"
                  :checked="isSelected(account)"
                  :disabled="!account.can_delete"
                  :aria-label="$t('common.selectRow')"
                  @change="toggleRow(account)"
                />
                <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-[var(--bg)] text-[var(--muted)]" aria-hidden="true">
                  <FileText class="h-4 w-4" />
                </span>

                <div class="min-w-0 flex-1">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-md border border-[var(--border)] bg-[var(--bg)] px-2 py-0.5 font-mono text-xs font-bold text-[var(--text)]">
                      {{ account.code }}
                    </span>
                    <span class="truncate text-sm font-bold text-[var(--text)]">
                      {{ account.name }}
                    </span>
                  </div>
                  <p v-if="account.description" class="mt-0.5 truncate text-xs text-[var(--muted)]">
                    {{ account.description }}
                  </p>
                </div>
              </div>

              <!-- End: Purpose Badge, Linked Flags, Actions -->
              <div class="flex flex-wrap items-center gap-2">
                <span
                  v-if="account.account_purpose && account.account_purpose !== 'general'"
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold"
                  :class="purposeBadgeClass(account.account_purpose)"
                >
                  {{ account.account_purpose_label }}
                </span>

                <span
                  v-if="account.is_linked_to_settings"
                  class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:text-sky-300"
                  :title="$t('chartOfAccounts.linkedToSettings')"
                >
                  <Settings2 class="h-3 w-3" />
                  <span>{{ $t('chartOfAccounts.settingBadge') }}</span>
                </span>

                <span
                  v-if="account.is_linked_to_payment_method"
                  class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:text-amber-300"
                  :title="$t('chartOfAccounts.linkedToPaymentMethod')"
                >
                  <CreditCard class="h-3 w-3" />
                  <span>{{ $t('chartOfAccounts.paymentMethodBadge') }}</span>
                </span>

                <!-- Actions -->
                <div class="ms-1 flex items-center gap-1">
                  <button
                    v-if="canUpdate"
                    type="button"
                    :title="$t('common.edit')"
                    class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    @click="openEdit(account)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>

                  <button
                    v-if="canDelete"
                    type="button"
                    :disabled="!account.can_delete"
                    :title="deleteDisabledReason(account) || $t('common.delete')"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition-colors"
                    :class="!account.can_delete
                      ? 'cursor-not-allowed text-[var(--muted)]/35'
                      : 'cursor-pointer text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]'"
                    @click="handleDelete(account)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- Pagination -->
      <PaginationBar :meta="meta" @change-page="loadAccounts" />
    </div>

    <!-- Form Drawer -->
    <ChartOfAccountFormModal
      :open="modalOpen"
      :editing-account="editingAccount"
      @close="modalOpen = false"
      @saved="handleSaved"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  CreditCard,
  FileText,
  Landmark,
  Pencil,
  Plus,
  Settings2,
  Trash2,
} from 'lucide-vue-next';
import api from '../../services/api';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import BulkActionsBar from '../../components/ui/BulkActionsBar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import PaginationBar from '../../components/ui/PaginationBar.vue';
import ChartOfAccountFormModal from './ChartOfAccountFormModal.vue';
import { deleteRecordsSequentially, useBulkSelection } from '../../composables/useBulkSelection';

const { t } = useI18n();
const authStore = useAuthStore();

const canCreate = computed(() => authStore.hasPermission('accounting.accounts.create'));
const canUpdate = computed(() => authStore.hasPermission('accounting.accounts.update'));
const canDelete = computed(() => authStore.hasPermission('accounting.accounts.delete'));

const accounts = ref([]);
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 50,
  total: 0,
});
const loading = ref(false);
const loadError = ref('');

const filters = reactive({
  search: '',
  account_type: '',
  account_purpose: '',
});

const modalOpen = ref(false);
const editingAccount = ref(null);

const accountTypeOptions = computed(() => [
  { value: 'asset', label: t('chartOfAccounts.types.asset') },
  { value: 'liability', label: t('chartOfAccounts.types.liability') },
  { value: 'equity', label: t('chartOfAccounts.types.equity') },
  { value: 'revenue', label: t('chartOfAccounts.types.revenue') },
  { value: 'expense', label: t('chartOfAccounts.types.expense') },
]);

const accountPurposeOptions = computed(() => [
  { value: 'general', label: t('chartOfAccounts.purposes.general') },
  { value: 'cash', label: t('chartOfAccounts.purposes.cash') },
  { value: 'bank', label: t('chartOfAccounts.purposes.bank') },
]);

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.account_type) count++;
  if (filters.account_purpose) count++;
  return count;
});

const visibleGroups = computed(() => {
  const byType = new Map();
  accountTypeOptions.value.forEach((opt) => {
    byType.set(opt.value, []);
  });

  accounts.value.forEach((acc) => {
    if (!byType.has(acc.account_type)) {
      byType.set(acc.account_type, []);
    }
    byType.get(acc.account_type).push(acc);
  });

  return accountTypeOptions.value
    .filter((opt) => !filters.account_type || filters.account_type === opt.value)
    .map((opt) => ({
      type: opt.value,
      label: opt.label,
      items: byType.get(opt.value) || [],
    }));
});

let searchTimer = null;

async function loadAccounts(page = 1) {
  clearSelection();
  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      page,
      per_page: meta.per_page,
    };
    if (filters.search.trim()) params.search = filters.search.trim();
    if (filters.account_type) params.account_type = filters.account_type;
    if (filters.account_purpose) params.account_purpose = filters.account_purpose;

    const res = await api.get('/accounting/accounts', { params });
    accounts.value = res.data.data || [];
    Object.assign(meta, {
      current_page: res.data.meta?.current_page ?? 1,
      last_page: res.data.meta?.last_page ?? 1,
      per_page: res.data.meta?.per_page ?? 50,
      total: res.data.meta?.total ?? accounts.value.length,
    });
  } catch (err) {
    loadError.value = err.response?.data?.message || t('chartOfAccounts.loadError');
  } finally {
    loading.value = false;
  }
}

watch(
  () => filters.search,
  () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadAccounts(1), 300);
  }
);

watch(
  () => [filters.account_type, filters.account_purpose],
  () => {
    loadAccounts(1);
  }
);

function resetFilters() {
  filters.search = '';
  filters.account_type = '';
  filters.account_purpose = '';
}

function typeBadgeClass(type) {
  switch (type) {
    case 'asset':
      return 'bg-blue-500/10 text-blue-700 dark:text-blue-300';
    case 'liability':
      return 'bg-orange-500/10 text-orange-700 dark:text-orange-300';
    case 'equity':
      return 'bg-purple-500/10 text-purple-700 dark:text-purple-300';
    case 'revenue':
      return 'bg-teal-500/10 text-teal-700 dark:text-teal-300';
    case 'expense':
      return 'bg-rose-500/10 text-rose-700 dark:text-rose-300';
    default:
      return 'bg-[var(--bg)] text-[var(--muted)]';
  }
}

function purposeBadgeClass(purpose) {
  if (purpose === 'cash') return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
  if (purpose === 'bank') return 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300';
  return 'bg-[var(--bg)] text-[var(--muted)]';
}

function deleteDisabledReason(account) {
  if (account.is_linked_to_settings) return t('chartOfAccounts.cannotDeleteSetting');
  if (account.is_linked_to_payment_method) return t('chartOfAccounts.cannotDeletePaymentMethod');
  return '';
}

function openCreate() {
  editingAccount.value = null;
  modalOpen.value = true;
}

function openEdit(account) {
  editingAccount.value = account;
  modalOpen.value = true;
}

async function handleSaved() {
  await loadAccounts(meta.current_page);
}

async function handleDelete(account) {
  const confirmed = await confirmDelete({
    title: t('chartOfAccounts.confirmDeleteTitle'),
    text: t('chartOfAccounts.confirmDeleteText', { name: account.name, code: account.code }),
  });

  if (!confirmed) return;

  try {
    await api.delete(`/accounting/accounts/${account.id}`);
    removeId(account.id);
    showSuccess({ title: t('common.successTitle'), text: t('chartOfAccounts.deletedSuccess') });
    await loadAccounts(meta.current_page);
  } catch (err) {
    const msg = err.response?.data?.errors?.account?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: msg });
  }
}

const bulkDeleting = ref(false);
const {
  selectedIds,
  isSelected,
  toggleRow,
  clearSelection,
  removeId,
} = useBulkSelection(accounts, { canSelect: (account) => Boolean(account.can_delete) });

function isGroupAllSelected(items) {
  const deletable = (items || []).filter((acc) => acc.can_delete);
  return deletable.length > 0 && deletable.every((acc) => selectedIds.value.includes(acc.id));
}

function isGroupSomeSelected(items) {
  const deletable = (items || []).filter((acc) => acc.can_delete);
  return deletable.some((acc) => selectedIds.value.includes(acc.id));
}

function toggleGroupSelection(items) {
  const deletableIds = (items || []).filter((acc) => acc.can_delete).map((acc) => acc.id);
  if (deletableIds.length === 0) return;

  if (isGroupAllSelected(items)) {
    selectedIds.value = selectedIds.value.filter((id) => !deletableIds.includes(id));
    return;
  }

  selectedIds.value = Array.from(new Set([...selectedIds.value, ...deletableIds]));
}

async function deleteSelectedAccounts() {
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
    (id) => api.delete(`/accounting/accounts/${id}`)
  );
  bulkDeleting.value = false;

  clearSelection();
  await loadAccounts(meta.current_page);

  if (deletedCount > 0 && failedCount === 0) {
    showSuccess({ title: t('common.successTitle'), text: t('common.bulkDeleteSuccess', { count: deletedCount }) });
  } else {
    showError({
      title: t('common.error'),
      text: firstErrorMessage || t('common.bulkDeletePartialError', { count: failedCount }),
    });
  }
}

onMounted(() => {
  loadAccounts(1);
});
</script>
