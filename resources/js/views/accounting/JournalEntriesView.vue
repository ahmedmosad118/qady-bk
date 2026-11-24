<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('journalEntries.title')" :subtitle="$t('journalEntries.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('journalEntries.newEntry') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Filter Toolbar & Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('journalEntries.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedFetch"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Status Chips -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('common.all')"
                :active="filters.status === ''"
                @click="setStatusFilter('')"
              />
              <FilterChip
                :label="$t('journalEntries.statuses.draft')"
                :active="filters.status === 'draft'"
                @click="setStatusFilter('draft')"
              />
              <FilterChip
                :label="$t('journalEntries.statuses.posted')"
                :active="filters.status === 'posted'"
                @click="setStatusFilter('posted')"
              />
            </div>
          </div>

          <!-- Entry Number & Reference -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.entryNumber') }}</label>
              <input
                v-model="filters.entry_number"
                type="text"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @input="debouncedFetch"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.reference') }}</label>
              <input
                v-model="filters.reference"
                type="text"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @input="debouncedFetch"
              />
            </div>
          </div>

          <!-- Label & Entry Date -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.label') }}</label>
              <input
                v-model="filters.label"
                type="text"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @input="debouncedFetch"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.entryDate') }}</label>
              <input
                v-model="filters.entry_date"
                type="date"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @change="loadEntries(1)"
              />
            </div>
          </div>

          <!-- Journal -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.journal') }}</label>
            <select
              v-model="filters.journal_id"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              @change="loadEntries(1)"
            >
              <option value="">{{ $t('common.all') }}</option>
              <option v-for="j in journals" :key="j.id" :value="String(j.id)">
                {{ j.name }}
              </option>
            </select>
          </div>

          <!-- Fiscal Year & Accounting Period -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.fiscalYear') }}</label>
              <select
                v-model="filters.fiscal_year_id"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @change="onFiscalYearFilterChange"
              >
                <option value="">{{ $t('common.all') }}</option>
                <option v-for="fy in fiscalYears" :key="fy.id" :value="String(fy.id)">
                  {{ fy.name }}
                </option>
              </select>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.accountingPeriod') }}</label>
              <select
                v-model="filters.accounting_period_id"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @change="loadEntries(1)"
              >
                <option value="">{{ $t('common.all') }}</option>
                <option v-for="p in periods" :key="p.id" :value="String(p.id)">
                  {{ p.name }}
                </option>
              </select>
            </div>
          </div>

          <!-- Sorting -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.sortBy') }}</label>
              <select
                v-model="filters.sort_by"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @change="loadEntries(1)"
              >
                <option value="entry_date">{{ $t('journalEntries.entryDate') }}</option>
                <option value="entry_number">{{ $t('journalEntries.entryNumber') }}</option>
                <option value="status">{{ $t('journalEntries.status') }}</option>
                <option value="created_at">{{ $t('journalEntries.createdAt') }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.sortOrder') }}</label>
              <select
                v-model="filters.sort_order"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @change="loadEntries(1)"
              >
                <option value="desc">{{ $t('journalEntries.sortDesc') }}</option>
                <option value="asc">{{ $t('journalEntries.sortAsc') }}</option>
              </select>
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Error State -->
      <div v-if="loadError" class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center" role="alert">
        <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
        <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadEntries(meta.current_page)"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <!-- Table Section -->
      <div v-else class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.entryNumber') }}</th>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.entryDate') }}</th>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.journal') }}</th>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.label') }}</th>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.reference') }}</th>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.fiscalYear') }}</th>
              <th class="px-4 py-3.5 text-start">{{ $t('journalEntries.accountingPeriod') }}</th>
              <th class="px-4 py-3.5 text-end">{{ $t('journalEntries.totalDebit') }}</th>
              <th class="px-4 py-3.5 text-end">{{ $t('journalEntries.totalCredit') }}</th>
              <th class="px-4 py-3.5 text-center">{{ $t('journalEntries.status') }}</th>
              <th class="px-4 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <tr v-if="loading">
              <td colspan="11" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <tr v-else-if="entries.length === 0">
              <td colspan="11" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <BookOpenCheck class="h-10 w-10 text-[var(--muted)]/50" />
                  <h3 class="mt-3 text-sm font-extrabold text-[var(--text)]">{{ $t('journalEntries.emptyTitle') }}</h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">{{ $t('journalEntries.emptySubtitle') }}</p>
                </div>
              </td>
            </tr>

            <tr
              v-for="entry in entries"
              :key="entry.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-4 py-3.5 whitespace-nowrap">
                <button
                  type="button"
                  class="font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                  @click="openDetailModal(entry)"
                >
                  {{ entry.entry_number }}
                </button>
              </td>
              <td class="px-4 py-3.5 text-xs font-mono font-bold text-[var(--text)] whitespace-nowrap">
                {{ formatDate(entry.entry_date) }}
              </td>
              <td class="px-4 py-3.5 font-semibold text-[var(--text)] whitespace-nowrap">
                {{ entry.journal?.name || '—' }}
              </td>
              <td class="max-w-[200px] truncate px-4 py-3.5 text-[var(--text)]" :title="entry.description || ''">
                {{ entry.description || '—' }}
              </td>
              <td class="px-4 py-3.5 text-xs font-semibold text-[var(--muted)] whitespace-nowrap">
                {{ entry.reference || '—' }}
              </td>
              <td class="px-4 py-3.5 text-xs font-semibold text-[var(--muted)] whitespace-nowrap">
                {{ entry.fiscal_year?.name || '—' }}
              </td>
              <td class="px-4 py-3.5 text-xs font-semibold text-[var(--muted)] whitespace-nowrap">
                {{ entry.accounting_period?.name || '—' }}
              </td>
              <td class="px-4 py-3.5 text-end font-mono text-sm font-extrabold text-[var(--text)] whitespace-nowrap">
                {{ formatMoney(entry.total_debit) }}
                <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </td>
              <td class="px-4 py-3.5 text-end font-mono text-sm font-extrabold text-[var(--text)] whitespace-nowrap">
                {{ formatMoney(entry.total_credit) }}
                <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </td>
              <td class="px-4 py-3.5 text-center whitespace-nowrap">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
                  :class="entry.status === 'posted'
                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                    : 'bg-amber-500/15 text-amber-700 dark:text-amber-400'"
                >
                  <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                  {{ $t(`journalEntries.statuses.${entry.status}`) }}
                </span>
              </td>
              <td class="px-4 py-3.5 text-end whitespace-nowrap">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :title="$t('common.view')"
                    @click="openDetailModal(entry)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>
                  <button
                    v-if="entry.status === 'draft'"
                    type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :title="$t('common.edit')"
                    @click="openEditModal(entry)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    v-if="entry.status === 'draft'"
                    type="button"
                    :disabled="actionEntryId === entry.id"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/30 cursor-pointer disabled:opacity-50"
                    :title="$t('journalEntries.postEntry')"
                    @click="handlePost(entry)"
                  >
                    <CheckCircle2 class="h-4 w-4" />
                  </button>
                  <button
                    v-if="entry.status === 'draft'"
                    type="button"
                    :disabled="actionEntryId === entry.id"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 cursor-pointer disabled:opacity-50"
                    :title="$t('common.delete')"
                    @click="handleDelete(entry)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination & Page Size -->
      <div
        v-if="!loadError && meta.total > 0"
        class="flex flex-col items-center justify-between gap-4 border-t border-[var(--border)] px-4 py-3.5 text-xs text-[var(--muted)] sm:flex-row sm:px-6"
      >
        <div class="flex flex-wrap items-center gap-3">
          <span>
            {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} / {{ meta.total }}
            ({{ $t('common.page') }} {{ meta.current_page }} {{ $t('common.of') }} {{ meta.last_page }})
          </span>
          <label class="inline-flex items-center gap-2">
            <span class="font-semibold">{{ $t('journalEntries.perPage') }}</span>
            <select
              v-model.number="perPage"
              class="h-8 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2 text-xs font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
              @change="loadEntries(1)"
            >
              <option :value="10">10</option>
              <option :value="15">15</option>
              <option :value="25">25</option>
              <option :value="50">50</option>
              <option :value="100">100</option>
            </select>
          </label>
        </div>

        <div v-if="meta.last_page > 1" class="flex items-center gap-1.5">
          <button
            type="button"
            :disabled="meta.current_page <= 1 || loading"
            class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 font-semibold text-[var(--text)] transition hover:bg-[var(--bg)] disabled:cursor-not-allowed disabled:opacity-45 cursor-pointer"
            @click="loadEntries(meta.current_page - 1)"
          >
            {{ $t('common.previous') }}
          </button>
          <button
            type="button"
            :disabled="meta.current_page >= meta.last_page || loading"
            class="rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 font-semibold text-[var(--text)] transition hover:bg-[var(--bg)] disabled:cursor-not-allowed disabled:opacity-45 cursor-pointer"
            @click="loadEntries(meta.current_page + 1)"
          >
            {{ $t('common.next') }}
          </button>
        </div>
      </div>
    </div>

    <JournalEntryFormModal
      :show="showFormModal"
      :entry-id="editingEntryId"
      @close="closeFormModal"
      @saved="handleFormSaved"
    />

    <JournalEntryDetailModal
      :show="showDetailModal"
      :entry-id="detailEntryId"
      @close="closeDetailModal"
      @edit="handleDetailEdit"
      @posted="loadEntries(meta.current_page)"
      @deleted="handleDetailDeleted"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertCircle, BookOpenCheck, CheckCircle2, Eye, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import JournalEntryFormModal from './JournalEntryFormModal.vue';
import JournalEntryDetailModal from './JournalEntryDetailModal.vue';
import {
  deleteJournalEntry,
  fetchAccountingPeriods,
  fetchFiscalYears,
  fetchJournalEntries,
  fetchJournals,
  postJournalEntry,
} from '../../services/accountingApi';
import { confirmAction, confirmDelete } from '../../services/alert';
import { useNotificationStore } from '../../stores/notification';
import { formatDate } from '../../utils/date';
import { formatMoney } from '../../utils/format';
import { extractApiError } from '../../utils/apiError';

const route = useRoute();
const { t } = useI18n();
const notify = useNotificationStore();

const entries = ref([]);
const loading = ref(false);
const loadError = ref('');
const actionEntryId = ref(null);
const perPage = ref(15);
const showFormModal = ref(false);
const editingEntryId = ref(null);
const showDetailModal = ref(false);
const detailEntryId = ref(null);

const journals = ref([]);
const fiscalYears = ref([]);
const periods = ref([]);

const meta = reactive({
  current_page: 1,
  per_page: 15,
  total: 0,
  last_page: 1,
  from: 0,
  to: 0,
});

const filters = reactive({
  search: '',
  entry_number: '',
  label: '',
  reference: '',
  entry_date: '',
  status: '',
  journal_id: '',
  fiscal_year_id: '',
  accounting_period_id: '',
  sort_by: 'entry_date',
  sort_order: 'desc',
});

const activeFiltersCount = computed(() => {
  return [
    filters.status,
    filters.entry_number,
    filters.label,
    filters.reference,
    filters.entry_date,
    filters.journal_id,
    filters.fiscal_year_id,
    filters.accounting_period_id,
  ].filter(Boolean).length;
});

let searchTimer = null;
const debouncedFetch = () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadEntries(1), 300);
};

async function loadFilterOptions() {
  try {
    const [jRes, fyRes, pRes] = await Promise.all([
      fetchJournals({ per_page: 100 }),
      fetchFiscalYears({ per_page: 100 }),
      fetchAccountingPeriods({ per_page: 100 }),
    ]);
    journals.value = jRes.data?.data || [];
    fiscalYears.value = fyRes.data?.data || [];
    periods.value = pRes.data?.data || [];
  } catch {
    // Non-blocking: filters stay usable with available options
  }
}

async function onFiscalYearFilterChange() {
  filters.accounting_period_id = '';
  try {
    const params = { per_page: 100 };
    if (filters.fiscal_year_id) {
      params.fiscal_year_id = filters.fiscal_year_id;
    }
    const res = await fetchAccountingPeriods(params);
    periods.value = res.data?.data || [];
  } catch {
    periods.value = [];
  }
  loadEntries(1);
}

async function loadEntries(page = 1) {
  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      page,
      per_page: perPage.value,
      sort_by: filters.sort_by,
      sort_order: filters.sort_order,
    };

    for (const key of ['search', 'entry_number', 'label', 'reference', 'entry_date', 'status', 'journal_id', 'fiscal_year_id', 'accounting_period_id']) {
      if (filters[key]) {
        params[key] = filters[key];
      }
    }

    const res = await fetchJournalEntries(params);
    entries.value = res.data?.data || [];
    const m = res.data?.meta || {};
    meta.current_page = m.current_page ?? page;
    meta.per_page = m.per_page ?? perPage.value;
    meta.total = m.total ?? entries.value.length;
    meta.last_page = m.last_page ?? 1;
    meta.from = m.from ?? (entries.value.length ? 1 : 0);
    meta.to = m.to ?? entries.value.length;
  } catch (err) {
    loadError.value = extractApiError(err, t('journalEntries.loadError'));
  } finally {
    loading.value = false;
  }
}

function setStatusFilter(status) {
  filters.status = status;
  loadEntries(1);
}

async function resetFilters() {
  filters.search = '';
  filters.entry_number = '';
  filters.label = '';
  filters.reference = '';
  filters.entry_date = '';
  filters.status = '';
  filters.journal_id = '';
  filters.fiscal_year_id = '';
  filters.accounting_period_id = '';
  filters.sort_by = 'entry_date';
  filters.sort_order = 'desc';
  await loadFilterOptions();
  loadEntries(1);
}

async function handlePost(entry) {
  const confirmed = await confirmAction({
    title: t('journalEntries.postConfirmTitle'),
    text: t('journalEntries.postConfirmText'),
    icon: 'question',
    confirmButtonText: t('journalEntries.postConfirmBtn'),
  });
  if (!confirmed) return;

  actionEntryId.value = entry.id;
  try {
    await postJournalEntry(entry.id);
    notify.success(t('journalEntries.postedSuccess'));
    await loadEntries(meta.current_page);
  } catch (err) {
    notify.error(extractApiError(err));
  } finally {
    actionEntryId.value = null;
  }
}

async function handleDelete(entry) {
  const confirmed = await confirmDelete({
    title: t('journalEntries.deleteConfirmTitle'),
    text: t('journalEntries.deleteConfirmText'),
    confirmButtonText: t('journalEntries.deleteConfirmBtn'),
  });
  if (!confirmed) return;

  actionEntryId.value = entry.id;
  try {
    await deleteJournalEntry(entry.id);
    notify.success(t('journalEntries.deletedSuccess'));
    const nextPage = entries.value.length === 1 && meta.current_page > 1 ? meta.current_page - 1 : meta.current_page;
    await loadEntries(nextPage);
  } catch (err) {
    notify.error(extractApiError(err));
  } finally {
    actionEntryId.value = null;
  }
}

function openCreateModal() {
  editingEntryId.value = null;
  showFormModal.value = true;
}

function openEditModal(entry) {
  editingEntryId.value = entry.id;
  showFormModal.value = true;
}

function closeFormModal() {
  showFormModal.value = false;
  editingEntryId.value = null;
}

async function handleFormSaved() {
  closeFormModal();
  await loadEntries(meta.current_page);
}

function openDetailModal(entry) {
  detailEntryId.value = entry.id;
  showDetailModal.value = true;
}

function closeDetailModal() {
  showDetailModal.value = false;
  detailEntryId.value = null;
}

function handleDetailEdit(entry) {
  closeDetailModal();
  openEditModal(entry);
}

async function handleDetailDeleted() {
  const nextPage = entries.value.length === 1 && meta.current_page > 1 ? meta.current_page - 1 : meta.current_page;
  await loadEntries(nextPage);
}

onMounted(async () => {
  await Promise.all([loadFilterOptions(), loadEntries(1)]);
  if (route.query.showId) {
    detailEntryId.value = route.query.showId;
    showDetailModal.value = true;
  }
});
</script>
