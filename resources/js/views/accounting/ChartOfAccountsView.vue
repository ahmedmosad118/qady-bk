<template>
  <div class="space-y-6">
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
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateRoot"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('chartOfAccounts.addRootAccount') }}</span>
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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadTree">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Main Tree Card -->
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
        </div>

        <template #actions>
          <button
            type="button"
            class="inline-flex h-11 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 cursor-pointer"
            @click="expandAll"
          >
            <ChevronsUpDown class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('chartOfAccounts.expandAll') }}</span>
          </button>
          <button
            type="button"
            class="inline-flex h-11 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 cursor-pointer"
            @click="collapseAll"
          >
            <ChevronsDownUp class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('chartOfAccounts.collapseAll') }}</span>
          </button>
        </template>
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

      <!-- Loading -->
      <div v-if="loading && tree.length === 0" class="p-12 text-center">
        <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('common.loading') }}</span>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else-if="tree.length === 0" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <FolderTree class="h-7 w-7" />
        </span>
        <h2 class="mt-4 text-base font-extrabold text-[var(--text)]">{{ $t('chartOfAccounts.emptyTitle') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm text-[var(--muted)]">{{ $t('chartOfAccounts.emptyDesc') }}</p>
      </div>

      <!-- Hierarchical Tree -->
      <div v-else class="divide-y divide-[var(--border)]/60">
        <ChartOfAccountTreeNode
          v-for="root in tree"
          :key="root.id"
          :node="root"
          :depth="0"
          :expanded-ids="expandedIds"
          :force-expand="hasActiveFilters"
          @toggle="toggleNode"
          @add-child="openCreateChild"
          @edit="openEdit"
          @delete="handleDelete"
        />
      </div>
    </div>

    <!-- Form Drawer -->
    <ChartOfAccountFormModal
      :open="modalOpen"
      :editing-account="editingAccount"
      :preset-parent="presetParent"
      :flat-accounts="allFlatAccounts"
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
  ChevronsDownUp,
  ChevronsUpDown,
  FolderTree,
  Plus,
  Settings2,
} from 'lucide-vue-next';
import api from '../../services/api';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import ChartOfAccountTreeNode from './ChartOfAccountTreeNode.vue';
import ChartOfAccountFormModal from './ChartOfAccountFormModal.vue';

const { t } = useI18n();

const tree = ref([]);
const fullTree = ref([]);
const loading = ref(false);
const loadError = ref('');

const filters = reactive({
  search: '',
  account_type: '',
});

const expandedIds = ref(new Set());
const modalOpen = ref(false);
const editingAccount = ref(null);
const presetParent = ref(null);

const accountTypeOptions = computed(() => [
  { value: 'asset', label: t('chartOfAccounts.types.asset') },
  { value: 'liability', label: t('chartOfAccounts.types.liability') },
  { value: 'equity', label: t('chartOfAccounts.types.equity') },
  { value: 'revenue', label: t('chartOfAccounts.types.revenue') },
  { value: 'expense', label: t('chartOfAccounts.types.expense') },
]);

const activeFiltersCount = computed(() => (filters.account_type ? 1 : 0));
const hasActiveFilters = computed(() => Boolean(filters.search.trim() || filters.account_type));

const flattenTree = (nodes, out = []) => {
  for (const node of nodes) {
    out.push(node);
    if (Array.isArray(node.children) && node.children.length) {
      flattenTree(node.children, out);
    }
  }
  return out;
};

const allFlatAccounts = computed(() => flattenTree(fullTree.value));

let searchTimer = null;

async function loadTree() {
  loading.value = true;
  loadError.value = '';
  try {
    const params = {};
    if (filters.search.trim()) params.search = filters.search.trim();
    if (filters.account_type) params.account_type = filters.account_type;

    const res = await api.get('/accounting/accounts/tree', { params });
    tree.value = res.data.data || [];

    if (!hasActiveFilters.value) {
      fullTree.value = tree.value;
    } else if (fullTree.value.length === 0) {
      const fullRes = await api.get('/accounting/accounts/tree');
      fullTree.value = fullRes.data.data || [];
    }

    // Expand top-level roots by default on initial load
    if (expandedIds.value.size === 0 && tree.value.length > 0) {
      const next = new Set();
      tree.value.forEach((root) => next.add(root.id));
      expandedIds.value = next;
    }
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
    searchTimer = setTimeout(loadTree, 300);
  }
);

watch(
  () => filters.account_type,
  () => {
    loadTree();
  }
);

function resetFilters() {
  filters.search = '';
  filters.account_type = '';
}

function toggleNode(id) {
  const next = new Set(expandedIds.value);
  if (next.has(id)) {
    next.delete(id);
  } else {
    next.add(id);
  }
  expandedIds.value = next;
}

function expandAll() {
  const next = new Set();
  flattenTree(tree.value).forEach((node) => {
    if (node.children?.length) next.add(node.id);
  });
  expandedIds.value = next;
}

function collapseAll() {
  expandedIds.value = new Set();
}

function openCreateRoot() {
  editingAccount.value = null;
  presetParent.value = null;
  modalOpen.value = true;
}

function openCreateChild(parentNode) {
  editingAccount.value = null;
  presetParent.value = parentNode;
  const next = new Set(expandedIds.value);
  next.add(parentNode.id);
  expandedIds.value = next;
  modalOpen.value = true;
}

function openEdit(node) {
  editingAccount.value = node;
  presetParent.value = null;
  modalOpen.value = true;
}

async function handleSaved() {
  fullTree.value = [];
  await loadTree();
}

async function handleDelete(node) {
  const confirmed = await confirmDelete({
    title: t('chartOfAccounts.confirmDeleteTitle'),
    text: t('chartOfAccounts.confirmDeleteText', { name: node.name, code: node.code }),
  });

  if (!confirmed) return;

  try {
    await api.delete(`/accounting/accounts/${node.id}`);
    showSuccess({ title: t('common.successTitle'), text: t('chartOfAccounts.deletedSuccess') });
    fullTree.value = [];
    await loadTree();
  } catch (err) {
    const msg = err.response?.data?.errors?.account?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: msg });
  }
}

onMounted(() => {
  loadTree();
});
</script>
