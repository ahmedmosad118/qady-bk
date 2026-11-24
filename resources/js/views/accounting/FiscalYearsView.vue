<template>
  <div class="space-y-4">
    <!-- Breadcrumbs -->
    <nav class="flex items-center gap-1.5 text-xs font-bold text-[var(--muted)]" aria-label="Breadcrumb">
      <span>{{ $t('nav.accountingGroup') }}</span>
      <ChevronLeft class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
      <span class="text-[var(--text)]">{{ $t('fiscalYears.title') }}</span>
    </nav>

    <!-- Page Header -->
    <PageHeader :title="$t('fiscalYears.title')" :subtitle="$t('fiscalYears.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('fiscalYears.addFiscalYear') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('fiscalYears.searchPlaceholder')"
        :active-filters-count="filters.status ? 1 : 0"
        @search="debouncedLoad"
        @reset="resetFilters"
      >
        <select
          v-model="filters.status"
          class="h-10 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          @change="loadFiscalYears(1)"
        >
          <option value="">{{ $t('fiscalYears.allStatuses') }}</option>
          <option v-for="st in statuses" :key="st" :value="st">
            {{ $t(`fiscalYears.statuses.${st}`) }}
          </option>
        </select>
      </FilterToolbar>

      <div v-if="loadError" class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center" role="alert">
        <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
        <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadFiscalYears(meta.current_page)"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-5 py-3.5 text-start">{{ $t('fiscalYears.code') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('fiscalYears.name') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('fiscalYears.startDate') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('fiscalYears.endDate') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('common.status') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('fiscalYears.periodsCount') }}</th>
              <th class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <tr v-if="loading && fiscalYears.length === 0">
              <td colspan="7" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <tr v-else-if="fiscalYears.length === 0">
              <td colspan="7" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <CalendarRange class="h-10 w-10 text-[var(--muted)]/50" />
                  <h3 class="mt-3 text-sm font-extrabold text-[var(--text)]">{{ $t('fiscalYears.emptyTitle') }}</h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">{{ $t('fiscalYears.emptyDesc') }}</p>
                </div>
              </td>
            </tr>

            <tr
              v-for="fy in fiscalYears"
              :key="fy.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-5 py-3.5 font-mono text-xs font-bold text-[var(--text)]">{{ fy.code }}</td>
              <td class="px-5 py-3.5">
                <button
                  type="button"
                  class="font-bold text-[var(--text)] hover:text-[var(--crimson)] hover:underline text-start cursor-pointer"
                  @click="openDetails(fy)"
                >
                  {{ fy.name }}
                </button>
              </td>
              <td class="px-5 py-3.5 text-xs font-semibold text-[var(--muted)]">{{ formatDate(fy.start_date) }}</td>
              <td class="px-5 py-3.5 text-xs font-semibold text-[var(--muted)]">{{ formatDate(fy.end_date) }}</td>
              <td class="px-5 py-3.5">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold" :class="statusBadgeClass(fy.status)">
                  {{ $t(`fiscalYears.statuses.${fy.status}`) }}
                </span>
              </td>
              <td class="px-5 py-3.5 text-xs font-bold text-[var(--text)]">
                {{ fy.accounting_periods_count ?? 0 }}
              </td>
              <td class="px-5 py-3.5 text-end">
                <div class="flex items-center justify-end gap-1">
                  <router-link
                    :to="{ name: 'accounting.periods', query: { fiscal_year_id: fy.id } }"
                    class="inline-flex h-8 items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2.5 text-[11px] font-bold text-[var(--text)] hover:border-[var(--crimson)]/40"
                  >
                    <span>{{ $t('fiscalYears.viewPeriods') }}</span>
                  </router-link>
                  <button
                    type="button"
                    :title="$t('common.view')"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    @click="openDetails(fy)"
                  >
                    <Eye class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    :title="fy.status === 'closed' ? $t('fiscalYears.closedImmutableHint') : $t('common.edit')"
                    :disabled="fy.status === 'closed'"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] disabled:opacity-40 cursor-pointer"
                    @click="openEdit(fy)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    :title="$t('common.delete')"
                    :disabled="fy.status === 'closed' || (fy.accounting_periods_count ?? 0) > 0"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] disabled:opacity-40 cursor-pointer"
                    @click="handleDelete(fy)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <TablePagination
        :meta="meta"
        :items-count="fiscalYears.length"
        :per-page="meta.per_page"
        :loading="loading"
        @update:per-page="meta.per_page = $event"
        @change-per-page="loadFiscalYears(1)"
        @change-page="loadFiscalYears"
      />
    </div>

    <!-- Details Modal -->
    <div
      v-if="detailsFy"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4"
      @click.self="detailsFy = null"
    >
      <div class="w-full max-w-lg rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xl">
        <div class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4">
          <h2 class="text-lg font-extrabold text-[var(--text)]">{{ detailsFy.name }}</h2>
          <button
            type="button"
            class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            @click="detailsFy = null"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <div class="space-y-4 p-6">
          <div class="grid grid-cols-2 gap-3 text-xs">
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3">
              <div class="text-[var(--muted)]">{{ $t('fiscalYears.code') }}</div>
              <div class="mt-1 font-mono font-bold text-[var(--text)]">{{ detailsFy.code }}</div>
            </div>
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3">
              <div class="text-[var(--muted)]">{{ $t('common.status') }}</div>
              <div class="mt-1 font-bold text-[var(--text)]">{{ $t(`fiscalYears.statuses.${detailsFy.status}`) }}</div>
            </div>
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3">
              <div class="text-[var(--muted)]">{{ $t('fiscalYears.startDate') }}</div>
              <div class="mt-1 font-bold text-[var(--text)]">{{ formatDate(detailsFy.start_date) }}</div>
            </div>
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3">
              <div class="text-[var(--muted)]">{{ $t('fiscalYears.endDate') }}</div>
              <div class="mt-1 font-bold text-[var(--text)]">{{ formatDate(detailsFy.end_date) }}</div>
            </div>
          </div>

          <div class="flex items-center justify-between rounded-xl border border-[var(--border)] bg-[var(--bg)] px-4 py-3">
            <span class="text-xs font-bold text-[var(--muted)]">{{ $t('fiscalYears.periodsCount') }}</span>
            <span class="text-sm font-extrabold text-[var(--text)]">{{ detailsFy.accounting_periods_count ?? 0 }}</span>
          </div>

          <div v-if="detailsLoading" class="py-4 text-center text-xs text-[var(--muted)]">
            {{ $t('common.loading') }}
          </div>
          <div v-else-if="(detailsFy.accounting_periods || []).length > 0" class="max-h-48 overflow-y-auto rounded-xl border border-[var(--border)] divide-y divide-[var(--border)]">
            <div
              v-for="p in detailsFy.accounting_periods"
              :key="p.id"
              class="flex items-center justify-between px-3.5 py-2 text-xs"
            >
              <div>
                <span class="font-mono font-bold text-[var(--text)]">#{{ p.period_number }}</span>
                <span class="ms-2 font-bold text-[var(--text)]">{{ p.name }}</span>
                <span class="ms-2 text-[var(--muted)]">({{ formatDate(p.start_date) }} — {{ formatDate(p.end_date) }})</span>
              </div>
              <span class="font-bold" :class="p.status === 'open' ? 'text-emerald-600' : 'text-rose-600'">
                {{ $t(`accountingPeriods.statuses.${p.status}`) }}
              </span>
            </div>
          </div>
          <p v-else class="text-center text-xs text-[var(--muted)]">
            {{ $t('fiscalYears.noPeriodsInYear') }}
          </p>

          <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] pt-4">
            <router-link
              :to="{ name: 'accounting.periods', query: { fiscal_year_id: detailsFy.id } }"
              class="inline-flex h-10 items-center justify-center rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white hover:bg-[var(--crimson-hover)]"
              @click="detailsFy = null"
            >
              {{ $t('fiscalYears.viewPeriods') }}
            </router-link>
          </div>
        </div>
      </div>
    </div>

    <!-- Add / Edit Modal -->
    <div
      v-if="modalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4"
      @click.self="modalOpen = false"
    >
      <div class="w-full max-w-lg rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xl">
        <div class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4">
          <h2 class="text-lg font-extrabold text-[var(--text)]">
            {{ editingFy ? $t('fiscalYears.editFiscalYear') : $t('fiscalYears.addFiscalYear') }}
          </h2>
          <button
            type="button"
            class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            @click="modalOpen = false"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form class="space-y-4 p-6" @submit.prevent="submitForm">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('fiscalYears.code') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.code"
                type="text"
                required
                maxlength="30"
                dir="ltr"
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-mono font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              />
              <p v-if="errors.code" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.code[0] }}</p>
            </div>

            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('common.status') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <select
                v-model="form.status"
                required
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              >
                <option v-for="st in statuses" :key="st" :value="st">
                  {{ $t(`fiscalYears.statuses.${st}`) }}
                </option>
              </select>
              <p v-if="errors.status" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.status[0] }}</p>
            </div>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('fiscalYears.name') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <input
              v-model="form.name"
              type="text"
              required
              maxlength="100"
              :disabled="saving"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
            />
            <p v-if="errors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.name[0] }}</p>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('fiscalYears.startDate') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.start_date"
                type="date"
                required
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              />
              <p v-if="errors.start_date" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.start_date[0] }}</p>
            </div>

            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('fiscalYears.endDate') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.end_date"
                type="date"
                required
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              />
              <p v-if="errors.end_date" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.end_date[0] }}</p>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] pt-4">
            <button
              type="button"
              class="inline-flex h-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--muted)] hover:text-[var(--text)] cursor-pointer"
              :disabled="saving"
              @click="modalOpen = false"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
              :disabled="saving"
            >
              <span v-if="saving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ $t('common.save') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, CalendarRange, ChevronLeft, Eye, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { createFiscalYear, deleteFiscalYear, fetchFiscalYear, fetchFiscalYears, updateFiscalYear } from '../../services/accountingApi';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import { formatDate } from '../../utils/date';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import TablePagination from '../../components/ui/TablePagination.vue';

const { t } = useI18n();
const statuses = ['draft', 'open', 'closed'];

const fiscalYears = ref([]);
const loading = ref(false);
const loadError = ref('');
const detailsFy = ref(null);
const detailsLoading = ref(false);
const filters = reactive({
  search: '',
  status: '',
});
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const modalOpen = ref(false);
const saving = ref(false);
const errors = ref({});
const editingFy = ref(null);
const form = reactive({
  code: '',
  name: '',
  start_date: '',
  end_date: '',
  status: 'draft',
});

let searchTimer = null;

function statusBadgeClass(status) {
  if (status === 'open') return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300';
  if (status === 'closed') return 'bg-rose-500/10 text-rose-700 dark:text-rose-300';
  return 'bg-amber-500/10 text-amber-700 dark:text-amber-300';
}

async function loadFiscalYears(page = 1) {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await fetchFiscalYears({
      search: filters.search.trim() || undefined,
      status: filters.status || undefined,
      page,
      per_page: meta.per_page,
    });
    fiscalYears.value = res.data?.data || [];
    const resMeta = res.data?.meta || {};
    meta.current_page = resMeta.current_page || 1;
    meta.last_page = resMeta.last_page || 1;
    meta.per_page = resMeta.per_page || 15;
    meta.total = resMeta.total || 0;
  } catch (err) {
    loadError.value = err.response?.data?.message || t('fiscalYears.loadError');
  } finally {
    loading.value = false;
  }
}

function debouncedLoad() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadFiscalYears(1), 300);
}

function resetFilters() {
  filters.search = '';
  filters.status = '';
  loadFiscalYears(1);
}

async function openDetails(fy) {
  detailsFy.value = fy;
  detailsLoading.value = true;
  try {
    const res = await fetchFiscalYear(fy.id);
    detailsFy.value = res.data?.data || fy;
  } catch (err) {
    showError({ title: t('common.error'), text: err.response?.data?.message || t('fiscalYears.loadError') });
  } finally {
    detailsLoading.value = false;
  }
}

async function handleDelete(fy) {
  const confirmed = await confirmDelete({
    title: t('fiscalYears.confirmDeleteTitle'),
    text: t('fiscalYears.confirmDeleteText', { name: fy.name, code: fy.code }),
  });

  if (!confirmed) return;

  try {
    await deleteFiscalYear(fy.id);
    showSuccess({ title: t('common.successTitle'), text: t('fiscalYears.deletedSuccess') });
    await loadFiscalYears(meta.current_page);
  } catch (err) {
    const firstErr = Object.values(err.response?.data?.errors || {})[0]?.[0];
    showError({ title: t('common.error'), text: firstErr || err.response?.data?.message || t('common.errorTitle') });
  }
}

function openCreate() {
  editingFy.value = null;
  errors.value = {};
  form.code = '';
  form.name = '';
  form.start_date = '';
  form.end_date = '';
  form.status = 'draft';
  modalOpen.value = true;
}

function openEdit(fy) {
  if (fy.status === 'closed') {
    showError({ title: t('common.error'), text: t('fiscalYears.closedImmutableHint') });
    return;
  }
  editingFy.value = fy;
  errors.value = {};
  form.code = fy.code ?? '';
  form.name = fy.name ?? '';
  form.start_date = String(fy.start_date || '').slice(0, 10);
  form.end_date = String(fy.end_date || '').slice(0, 10);
  form.status = fy.status ?? 'draft';
  modalOpen.value = true;
}

async function submitForm() {
  if (saving.value) return;
  errors.value = {};

  if (form.start_date && form.end_date && form.start_date > form.end_date) {
    errors.value = { end_date: [t('fiscalYears.invalidDateRange')] };
    showError({ title: t('common.error'), text: t('fiscalYears.invalidDateRange') });
    return;
  }

  saving.value = true;
  const payload = {
    code: form.code,
    name: form.name,
    start_date: form.start_date,
    end_date: form.end_date,
    status: form.status,
  };

  try {
    if (editingFy.value) {
      await updateFiscalYear(editingFy.value.id, payload);
      showSuccess({ title: t('common.successTitle'), text: t('fiscalYears.updatedSuccess') });
    } else {
      await createFiscalYear(payload);
      showSuccess({ title: t('common.successTitle'), text: t('fiscalYears.createdSuccess') });
    }
    modalOpen.value = false;
    await loadFiscalYears(meta.current_page);
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
    }
    const firstMsg = Object.values(errors.value)[0]?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: firstMsg });
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadFiscalYears();
});
</script>
