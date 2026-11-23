<template>
  <div class="space-y-4">
    <!-- Breadcrumbs -->
    <nav class="flex items-center gap-1.5 text-xs font-bold text-[var(--muted)]" aria-label="Breadcrumb">
      <span>{{ $t('nav.accountingGroup') }}</span>
      <ChevronLeft class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
      <router-link to="/accounting/fiscal-years" class="hover:text-[var(--crimson)]">
        {{ $t('fiscalYears.title') }}
      </router-link>
      <ChevronLeft class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
      <span class="text-[var(--text)]">{{ $t('accountingPeriods.title') }}</span>
    </nav>

    <!-- Page Header -->
    <PageHeader :title="$t('accountingPeriods.title')" :subtitle="$t('accountingPeriods.subtitle')">
      <template #actions>
        <button
          v-if="selectedFiscalYear && selectedFiscalYear.status !== 'closed'"
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('accountingPeriods.addPeriod') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Fiscal Year Selector Card -->
    <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-12 md:items-end">
        <div class="md:col-span-6">
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('accountingPeriods.selectFiscalYear') }}
          </label>
          <select
            v-model="selectedFiscalYearId"
            :disabled="fyLoading"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
            @change="handleFiscalYearChange"
          >
            <option value="">{{ $t('accountingPeriods.selectFiscalYearPlaceholder') }}</option>
            <option v-for="fy in fiscalYears" :key="fy.id" :value="String(fy.id)">
              {{ fy.code }} — {{ fy.name }} ({{ formatDate(fy.start_date) }} → {{ formatDate(fy.end_date) }})
            </option>
          </select>
        </div>

        <div v-if="selectedFiscalYear" class="flex flex-wrap items-center gap-3 md:col-span-6 md:justify-end">
          <span class="rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-xs font-bold text-[var(--text)]">
            {{ formatDate(selectedFiscalYear.start_date) }} — {{ formatDate(selectedFiscalYear.end_date) }}
          </span>
          <span class="rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 py-2 text-xs font-bold text-[var(--muted)]">
            {{ $t('accountingPeriods.periodsCount', { count: meta.total }) }}
          </span>
        </div>
      </div>
    </div>

    <!-- Periods Table Card -->
    <div v-if="selectedFiscalYear" class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <div v-if="loadError" class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center" role="alert">
        <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
        <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadPeriods(meta.current_page)"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingPeriods.periodNumber') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingPeriods.name') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingPeriods.fiscalYear') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingPeriods.startDate') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingPeriods.endDate') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('common.status') }}</th>
              <th class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <tr v-if="loading">
              <td colspan="7" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <tr v-else-if="periods.length === 0">
              <td colspan="7" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <CalendarDays class="h-10 w-10 text-[var(--muted)]/50" />
                  <h3 class="mt-3 text-sm font-extrabold text-[var(--text)]">{{ $t('accountingPeriods.emptyTitle') }}</h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">{{ $t('accountingPeriods.emptyDesc') }}</p>
                </div>
              </td>
            </tr>

            <tr
              v-for="period in periods"
              :key="period.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-5 py-3.5 font-mono text-xs font-extrabold text-[var(--text)]">#{{ period.period_number }}</td>
              <td class="px-5 py-3.5 font-bold text-[var(--text)]">{{ period.name }}</td>
              <td class="px-5 py-3.5 text-xs font-semibold text-[var(--muted)]">
                {{ period.fiscal_year?.name || selectedFiscalYear.name }}
              </td>
              <td class="px-5 py-3.5 text-xs font-semibold text-[var(--muted)]">{{ formatDate(period.start_date) }}</td>
              <td class="px-5 py-3.5 text-xs font-semibold text-[var(--muted)]">{{ formatDate(period.end_date) }}</td>
              <td class="px-5 py-3.5">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                  :class="period.status === 'open'
                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    : 'bg-rose-500/10 text-rose-700 dark:text-rose-300'"
                >
                  {{ $t(`accountingPeriods.statuses.${period.status}`) }}
                </span>
              </td>
              <td class="px-5 py-3.5 text-end">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    :title="period.status === 'closed' ? $t('accountingPeriods.closedImmutableHint') : $t('common.edit')"
                    :disabled="selectedFiscalYear.status === 'closed' || period.status === 'closed'"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] disabled:opacity-40 cursor-pointer"
                    @click="openEdit(period)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    :title="period.status === 'closed' ? $t('accountingPeriods.closedImmutableHint') : $t('common.delete')"
                    :disabled="selectedFiscalYear.status === 'closed' || period.status === 'closed'"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] disabled:opacity-40 cursor-pointer"
                    @click="handleDelete(period)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar
        v-if="meta.last_page > 1"
        :current-page="meta.current_page"
        :last-page="meta.last_page"
        :total="meta.total"
        :per-page="meta.per_page"
        @page-change="loadPeriods"
      />
    </div>

    <!-- Prompt to select a fiscal year -->
    <div v-else class="rounded-2xl border border-[var(--border)] bg-[var(--card)] py-16 text-center">
      <CalendarDays class="mx-auto h-10 w-10 text-[var(--muted)]/50" />
      <p class="mt-3 text-sm font-bold text-[var(--muted)]">{{ $t('accountingPeriods.selectFiscalYearPrompt') }}</p>
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
            {{ editingPeriod ? $t('accountingPeriods.editPeriod') : $t('accountingPeriods.addPeriod') }}
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
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('accountingPeriods.fiscalYear') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <select
              v-model="form.fiscal_year_id"
              required
              :disabled="saving || Boolean(editingPeriod)"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] disabled:opacity-60"
            >
              <option v-for="fy in fiscalYears" :key="fy.id" :value="fy.id">
                {{ fy.code }} — {{ fy.name }}
              </option>
            </select>
            <p v-if="errors.fiscal_year_id" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.fiscal_year_id[0] }}</p>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('accountingPeriods.periodNumber') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model.number="form.period_number"
                type="number"
                min="1"
                required
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-mono font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              />
              <p v-if="errors.period_number" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.period_number[0] }}</p>
            </div>

            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('common.status') }}
              </label>
              <div class="flex h-10 items-center rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-bold text-emerald-700 dark:text-emerald-300">
                {{ $t('accountingPeriods.statuses.open') }}
              </div>
            </div>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('accountingPeriods.name') }} <span class="text-[var(--crimson)]">*</span>
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
                {{ $t('accountingPeriods.startDate') }} <span class="text-[var(--crimson)]">*</span>
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
                {{ $t('accountingPeriods.endDate') }} <span class="text-[var(--crimson)]">*</span>
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
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { AlertCircle, CalendarDays, ChevronLeft, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import {
  createAccountingPeriod,
  deleteAccountingPeriod,
  fetchAccountingPeriods,
  fetchFiscalYears,
  updateAccountingPeriod,
} from '../../services/accountingApi';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import { formatDate } from '../../utils/date';
import PageHeader from '../../components/ui/PageHeader.vue';
import PaginationBar from '../../components/ui/PaginationBar.vue';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const fiscalYears = ref([]);
const fyLoading = ref(false);
const selectedFiscalYearId = ref('');

const periods = ref([]);
const loading = ref(false);
const loadError = ref('');
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 50,
  total: 0,
});

const modalOpen = ref(false);
const saving = ref(false);
const errors = ref({});
const editingPeriod = ref(null);
const form = reactive({
  fiscal_year_id: '',
  name: '',
  period_number: 1,
  start_date: '',
  end_date: '',
  status: 'open',
});

const selectedFiscalYear = computed(() =>
  fiscalYears.value.find((fy) => String(fy.id) === String(selectedFiscalYearId.value)) || null
);

async function loadFiscalYears() {
  fyLoading.value = true;
  try {
    const res = await fetchFiscalYears({ per_page: 100 });
    fiscalYears.value = res.data?.data || [];

    const queryFyId = route.query.fiscal_year_id ? String(route.query.fiscal_year_id) : '';
    if (queryFyId && fiscalYears.value.some((fy) => String(fy.id) === queryFyId)) {
      selectedFiscalYearId.value = queryFyId;
      await loadPeriods(1);
    }
  } catch (err) {
    showError({ title: t('common.error'), text: err.response?.data?.message || t('fiscalYears.loadError') });
  } finally {
    fyLoading.value = false;
  }
}

async function loadPeriods(page = 1) {
  if (!selectedFiscalYearId.value) {
    periods.value = [];
    meta.total = 0;
    return;
  }

  loading.value = true;
  loadError.value = '';
  try {
    const res = await fetchAccountingPeriods({
      fiscal_year_id: Number(selectedFiscalYearId.value),
      page,
      per_page: meta.per_page,
    });
    periods.value = res.data?.data || [];
    const resMeta = res.data?.meta || {};
    meta.current_page = resMeta.current_page || 1;
    meta.last_page = resMeta.last_page || 1;
    meta.per_page = resMeta.per_page || 50;
    meta.total = resMeta.total || 0;
  } catch (err) {
    loadError.value = err.response?.data?.message || t('accountingPeriods.loadError');
  } finally {
    loading.value = false;
  }
}

function handleFiscalYearChange() {
  router.replace({
    query: selectedFiscalYearId.value ? { fiscal_year_id: selectedFiscalYearId.value } : {},
  });
  loadPeriods(1);
}

function openCreate() {
  if (!selectedFiscalYear.value) return;
  editingPeriod.value = null;
  errors.value = {};
  form.fiscal_year_id = selectedFiscalYear.value.id;
  form.name = '';
  form.period_number = (periods.value[periods.value.length - 1]?.period_number || 0) + 1;
  form.start_date = String(selectedFiscalYear.value.start_date || '').slice(0, 10);
  form.end_date = String(selectedFiscalYear.value.end_date || '').slice(0, 10);
  form.status = 'open';
  modalOpen.value = true;
}

function openEdit(period) {
  editingPeriod.value = period;
  errors.value = {};
  form.fiscal_year_id = period.fiscal_year_id;
  form.name = period.name ?? '';
  form.period_number = period.period_number ?? 1;
  form.start_date = String(period.start_date || '').slice(0, 10);
  form.end_date = String(period.end_date || '').slice(0, 10);
  form.status = period.status ?? 'open';
  modalOpen.value = true;
}

async function submitForm() {
  if (saving.value) return;
  errors.value = {};

  if (form.start_date && form.end_date && form.start_date > form.end_date) {
    errors.value = { end_date: [t('accountingPeriods.invalidDateRange')] };
    showError({ title: t('common.error'), text: t('accountingPeriods.invalidDateRange') });
    return;
  }

  saving.value = true;
  const payload = {
    fiscal_year_id: Number(form.fiscal_year_id),
    name: form.name,
    period_number: Number(form.period_number),
    start_date: form.start_date,
    end_date: form.end_date,
    status: form.status,
  };

  try {
    if (editingPeriod.value) {
      await updateAccountingPeriod(editingPeriod.value.id, payload);
      showSuccess({ title: t('common.successTitle'), text: t('accountingPeriods.updatedSuccess') });
    } else {
      await createAccountingPeriod(payload);
      showSuccess({ title: t('common.successTitle'), text: t('accountingPeriods.createdSuccess') });
    }
    modalOpen.value = false;
    await loadPeriods(meta.current_page);
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

async function handleDelete(period) {
  const confirmed = await confirmDelete({
    title: t('accountingPeriods.confirmDeleteTitle'),
    text: t('accountingPeriods.confirmDeleteText', { name: period.name }),
  });

  if (!confirmed) return;

  try {
    await deleteAccountingPeriod(period.id);
    showSuccess({ title: t('common.successTitle'), text: t('accountingPeriods.deletedSuccess') });
    await loadPeriods(meta.current_page);
  } catch (err) {
    const firstErr = Object.values(err.response?.data?.errors || {})[0]?.[0];
    showError({ title: t('common.error'), text: firstErr || err.response?.data?.message || t('common.errorTitle') });
  }
}

onMounted(() => {
  loadFiscalYears();
});
</script>
