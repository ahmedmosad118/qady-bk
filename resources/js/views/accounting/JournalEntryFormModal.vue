<template>
  <Teleport to="body">
    <div
      v-if="show"
      :dir="locale === 'ar' ? 'rtl' : 'ltr'"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 p-3 backdrop-blur-xs sm:p-5"
      @click.self="handleClose"
    >
      <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl">
        <!-- Modal Header -->
        <div class="flex shrink-0 items-center justify-between border-b border-[var(--border)] px-6 py-4">
          <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
              <BookOpenCheck class="h-5 w-5" aria-hidden="true" />
            </div>
            <div>
              <h2 class="text-base font-black text-[var(--text)] sm:text-lg">
                {{ isEdit ? $t('journalEntries.editTitle') : $t('journalEntries.createTitle') }}
              </h2>
              <p class="text-xs text-[var(--muted)]">
                {{ isEdit ? $t('journalEntries.editSubtitle') : $t('journalEntries.createSubtitle') }}
              </p>
            </div>
          </div>

          <button
            type="button"
            class="inline-flex h-9 w-9 items-center justify-center rounded-xl text-[var(--muted)] transition hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            :aria-label="$t('common.cancel')"
            @click="handleClose"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <!-- Modal Body -->
        <div class="flex-1 overflow-y-auto p-5 sm:p-6">
          <!-- Loading State -->
          <div v-if="initialLoading" class="flex items-center justify-center py-20">
            <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
              <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
              <span>{{ $t('common.loading') }}</span>
            </div>
          </div>

          <!-- Load Error -->
          <div v-else-if="loadError" class="flex flex-col items-center justify-center gap-3 py-14 text-center" role="alert">
            <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
            <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
            <button
              type="button"
              class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
              @click="loadInitialData"
            >
              {{ $t('common.retry') }}
            </button>
          </div>

          <!-- Posted Immutable Guard -->
          <div v-else-if="isPosted" class="flex flex-col items-center justify-center gap-3 rounded-2xl border border-amber-500/30 bg-amber-500/10 px-6 py-12 text-center" role="alert">
            <Lock class="h-8 w-8 text-amber-600 dark:text-amber-400" />
            <p class="text-sm font-bold text-[var(--text)]">{{ $t('journalEntries.postedImmutableNotice') }}</p>
          </div>

          <!-- Form Content -->
          <form v-else id="journal-entry-modal-form" class="space-y-5" @submit.prevent="handleSubmit">
            <!-- Header Fields -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg)]/40 p-4 sm:p-5">
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <!-- Journal -->
                <div>
                  <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                    {{ $t('journalEntries.journal') }} <span class="text-[var(--crimson)]">*</span>
                  </label>
                  <select
                    v-model="form.journal_id"
                    required
                    class="h-10 w-full rounded-xl border bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                    :class="fieldError('journal_id') ? 'border-rose-500' : 'border-[var(--border)]'"
                  >
                    <option value="">{{ $t('journalEntries.selectJournal') }}</option>
                    <option v-for="j in journals" :key="j.id" :value="String(j.id)">
                      {{ j.name }}
                    </option>
                  </select>
                  <p v-if="fieldError('journal_id')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('journal_id') }}
                  </p>
                </div>

                <!-- Fiscal Year -->
                <div>
                  <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                    {{ $t('journalEntries.fiscalYear') }} <span class="text-[var(--crimson)]">*</span>
                  </label>
                  <select
                    v-model="form.fiscal_year_id"
                    required
                    class="h-10 w-full rounded-xl border bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                    :class="fieldError('fiscal_year_id') ? 'border-rose-500' : 'border-[var(--border)]'"
                    @change="onFiscalYearChange"
                  >
                    <option value="">{{ $t('journalEntries.selectFiscalYear') }}</option>
                    <option v-for="fy in fiscalYears" :key="fy.id" :value="String(fy.id)">
                      {{ fy.name }} ({{ fy.status }})
                    </option>
                  </select>
                  <p v-if="fieldError('fiscal_year_id')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('fiscal_year_id') }}
                  </p>
                </div>

                <!-- Accounting Period -->
                <div>
                  <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                    {{ $t('journalEntries.accountingPeriod') }} <span class="text-[var(--crimson)]">*</span>
                  </label>
                  <select
                    v-model="form.accounting_period_id"
                    required
                    :disabled="!form.fiscal_year_id || periodsLoading"
                    class="h-10 w-full rounded-xl border bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] disabled:opacity-50"
                    :class="fieldError('accounting_period_id') ? 'border-rose-500' : 'border-[var(--border)]'"
                  >
                    <option value="">{{ $t('journalEntries.selectPeriod') }}</option>
                    <option v-for="p in periods" :key="p.id" :value="String(p.id)">
                      {{ p.name }} ({{ p.status }})
                    </option>
                  </select>
                  <p v-if="fieldError('accounting_period_id')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('accounting_period_id') }}
                  </p>
                </div>

                <!-- Entry Date -->
                <div>
                  <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                    {{ $t('journalEntries.entryDate') }} <span class="text-[var(--crimson)]">*</span>
                  </label>
                  <input
                    v-model="form.entry_date"
                    type="date"
                    required
                    class="h-10 w-full rounded-xl border bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                    :class="fieldError('entry_date') ? 'border-rose-500' : 'border-[var(--border)]'"
                  />
                  <p v-if="fieldError('entry_date')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('entry_date') }}
                  </p>
                </div>

                <!-- Reference -->
                <div>
                  <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                    {{ $t('journalEntries.reference') }}
                  </label>
                  <input
                    v-model="form.reference"
                    type="text"
                    maxlength="100"
                    :placeholder="$t('journalEntries.referencePlaceholder')"
                    class="h-10 w-full rounded-xl border bg-[var(--card)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                    :class="fieldError('reference') ? 'border-rose-500' : 'border-[var(--border)]'"
                  />
                  <p v-if="fieldError('reference')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('reference') }}
                  </p>
                </div>

                <!-- Description -->
                <div class="md:col-span-2 lg:col-span-1">
                  <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                    {{ $t('journalEntries.description') }}
                  </label>
                  <input
                    v-model="form.description"
                    type="text"
                    :placeholder="$t('journalEntries.descriptionPlaceholder')"
                    class="h-10 w-full rounded-xl border bg-[var(--card)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                    :class="fieldError('description') ? 'border-rose-500' : 'border-[var(--border)]'"
                  />
                  <p v-if="fieldError('description')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('description') }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Lines Table -->
            <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
              <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3.5 sm:px-5">
                <div>
                  <h3 class="text-sm font-extrabold text-[var(--text)]">{{ $t('journalEntries.linesTitle') }}</h3>
                  <p v-if="fieldError('lines')" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                    {{ fieldError('lines') }}
                  </p>
                </div>

                <button
                  type="button"
                  class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-xs font-bold text-[var(--text)] transition hover:border-[var(--crimson)] hover:text-[var(--crimson)] cursor-pointer"
                  @click="addLine"
                >
                  <Plus class="h-4 w-4" aria-hidden="true" />
                  <span>{{ $t('journalEntries.addLine') }}</span>
                </button>
              </div>

              <div class="overflow-x-auto">
                <table class="w-full text-start text-sm">
                  <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
                    <tr>
                      <th class="w-12 px-3 py-3 text-center">{{ $t('journalEntries.lineNumber') }}</th>
                      <th class="min-w-[260px] px-3 py-3 text-start">{{ $t('journalEntries.account') }}</th>
                      <th class="min-w-[180px] px-3 py-3 text-start">{{ $t('journalEntries.description') }}</th>
                      <th class="w-36 px-3 py-3 text-end">{{ $t('journalEntries.debit') }}</th>
                      <th class="w-36 px-3 py-3 text-end">{{ $t('journalEntries.credit') }}</th>
                      <th class="w-12 px-3 py-3 text-center">{{ $t('common.actions') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-[var(--border)]">
                    <tr v-for="(line, index) in form.lines" :key="line.uid" class="align-top">
                      <td class="px-3 py-3 pt-5 text-center font-mono text-xs font-bold text-[var(--muted)]">
                        {{ index + 1 }}
                      </td>
                      <td class="px-3 py-3">
                        <SearchableSelect
                          v-model="line.account_id"
                          :options="line.initialOptions"
                          :fetch-options="fetchActiveAccountOptions"
                          :paginate="15"
                          :placeholder="$t('journalEntries.selectAccount')"
                        />
                        <p v-if="fieldError(`lines.${index}.account_id`)" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                          {{ fieldError(`lines.${index}.account_id`) }}
                        </p>
                        <p v-if="fieldError(`lines.${index}`)" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                          {{ fieldError(`lines.${index}`) }}
                        </p>
                      </td>
                      <td class="px-3 py-3">
                        <input
                          v-model="line.label"
                          type="text"
                          maxlength="255"
                          :placeholder="$t('journalEntries.lineLabelPlaceholder')"
                          class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                        />
                      </td>
                      <td class="px-3 py-3">
                        <input
                          v-model="line.debit"
                          type="number"
                          step="0.01"
                          min="0"
                          class="h-10 w-full rounded-xl border bg-[var(--bg)] px-3 text-end font-mono text-sm font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                          :class="(fieldError(`lines.${index}.debit`) || lineValidation(line).debitError) ? 'border-rose-500' : 'border-[var(--border)]'"
                        />
                        <p v-if="fieldError(`lines.${index}.debit`) || lineValidation(line).debitError" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                          {{ fieldError(`lines.${index}.debit`) || lineValidation(line).debitError }}
                        </p>
                      </td>
                      <td class="px-3 py-3">
                        <input
                          v-model="line.credit"
                          type="number"
                          step="0.01"
                          min="0"
                          class="h-10 w-full rounded-xl border bg-[var(--bg)] px-3 text-end font-mono text-sm font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                          :class="(fieldError(`lines.${index}.credit`) || lineValidation(line).creditError) ? 'border-rose-500' : 'border-[var(--border)]'"
                        />
                        <p v-if="fieldError(`lines.${index}.credit`) || lineValidation(line).creditError" class="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-400">
                          {{ fieldError(`lines.${index}.credit`) || lineValidation(line).creditError }}
                        </p>
                      </td>
                      <td class="px-3 py-3 pt-4 text-center">
                        <button
                          type="button"
                          :disabled="form.lines.length <= 2"
                          class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 disabled:cursor-not-allowed disabled:opacity-35 cursor-pointer"
                          :title="$t('journalEntries.removeLine')"
                          @click="removeLine(index)"
                        >
                          <Trash2 class="h-4 w-4" />
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </form>
        </div>

        <!-- Modal Footer (Live Totals & Actions) -->
        <div
          v-if="!initialLoading && !loadError && !isPosted"
          class="flex shrink-0 flex-col gap-4 border-t border-[var(--border)] bg-[var(--bg)]/70 px-6 py-4 lg:flex-row lg:items-center lg:justify-between"
        >
          <div class="flex flex-wrap items-center gap-6">
            <div>
              <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.totalDebit') }}</span>
              <span class="font-mono text-base font-black text-[var(--text)]">
                {{ formatMoney(totals.totalDebit) }}
                <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </span>
            </div>
            <div>
              <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.totalCredit') }}</span>
              <span class="font-mono text-base font-black text-[var(--text)]">
                {{ formatMoney(totals.totalCredit) }}
                <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </span>
            </div>
            <div>
              <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.difference') }}</span>
              <span
                class="font-mono text-base font-black"
                :class="totals.isBalanced ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'"
              >
                {{ formatMoney(totals.difference) }}
                <span class="text-xs font-normal">{{ $t('common.currency') }}</span>
              </span>
            </div>
            <div>
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                :class="totals.isBalanced
                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                  : 'bg-rose-500/15 text-rose-700 dark:text-rose-400'"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ totals.isBalanced ? $t('journalEntries.balanced') : $t('journalEntries.unbalanced') }}
              </span>
            </div>
          </div>

          <div class="flex items-center justify-end gap-3">
            <button
              type="button"
              class="inline-flex h-11 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-5 text-sm font-bold text-[var(--text)] transition hover:bg-[var(--bg)] cursor-pointer"
              @click="handleClose"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              form="journal-entry-modal-form"
              :disabled="submitting || !canSubmit"
              class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-6 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition hover:bg-[var(--crimson-hover)] disabled:cursor-not-allowed disabled:opacity-50 cursor-pointer"
            >
              <span v-if="submitting" class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ isEdit ? $t('journalEntries.updateDraft') : $t('journalEntries.saveDraft') }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, BookOpenCheck, Lock, Plus, Trash2, X } from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import {
  createJournalEntry,
  fetchAccountingPeriods,
  fetchFiscalYears,
  fetchJournalEntry,
  fetchJournals,
  updateJournalEntry,
} from '../../services/accountingApi';
import { createPaginatedFetcher } from '../../utils/selectFetchers';
import { calculateJournalEntryTotals, toCents } from '../../utils/journalEntryCalculator';
import { formatMoney } from '../../utils/format';
import { extractApiError } from '../../utils/apiError';
import { useNotificationStore } from '../../stores/notification';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  entryId: {
    type: [Number, String, null],
    default: null,
  },
});

const emit = defineEmits(['close', 'saved']);

const { locale, t } = useI18n();
const notify = useNotificationStore();

const isEdit = computed(() => Boolean(props.entryId));

const initialLoading = ref(false);
const loadError = ref('');
const isPosted = ref(false);
const submitting = ref(false);
const periodsLoading = ref(false);
const serverErrors = ref({});

const journals = ref([]);
const fiscalYears = ref([]);
const periods = ref([]);

let lineUidSeq = 1;
const makeEmptyLine = () => ({
  uid: lineUidSeq++,
  account_id: '',
  initialOptions: [],
  label: '',
  debit: '0',
  credit: '0',
});

const form = reactive({
  journal_id: '',
  fiscal_year_id: '',
  accounting_period_id: '',
  entry_date: new Date().toISOString().slice(0, 10),
  reference: '',
  description: '',
  lines: [makeEmptyLine(), makeEmptyLine()],
});

const fetchActiveAccountOptions = createPaginatedFetcher(
  '/accounting/accounts',
  () => ({ is_active: 1 }),
  (acc) => ({
    value: acc.id,
    label: `${acc.code} — ${acc.name}`,
    raw: acc,
  })
);

const totals = computed(() => calculateJournalEntryTotals(form.lines));

function lineValidation(line) {
  const debitCents = toCents(line.debit);
  const creditCents = toCents(line.credit);
  let debitError = '';
  let creditError = '';

  if (Number.isNaN(debitCents) || debitCents < 0) {
    debitError = t('journalEntries.negativeAmountError');
  }
  if (Number.isNaN(creditCents) || creditCents < 0) {
    creditError = t('journalEntries.negativeAmountError');
  }

  if (!debitError && !creditError) {
    if (debitCents > 0 && creditCents > 0) {
      debitError = t('journalEntries.bothSidesPositiveError');
      creditError = t('journalEntries.bothSidesPositiveError');
    } else if (debitCents === 0 && creditCents === 0 && totals.value.hasPositiveTotal) {
      debitError = t('journalEntries.bothSidesZeroError');
      creditError = t('journalEntries.bothSidesZeroError');
    }
  }

  return { debitError, creditError };
}

const hasInvalidLines = computed(() => {
  if (form.lines.length < 2) return true;
  return form.lines.some((line) => {
    const debitCents = toCents(line.debit);
    const creditCents = toCents(line.credit);
    if (Number.isNaN(debitCents) || Number.isNaN(creditCents)) return true;
    if (debitCents < 0 || creditCents < 0) return true;
    if (debitCents > 0 && creditCents > 0) return true;
    if (debitCents === 0 && creditCents === 0) return true;
    return false;
  });
});

const canSubmit = computed(() => totals.value.isBalanced && !hasInvalidLines.value);

function fieldError(key) {
  const err = serverErrors.value?.[key];
  if (Array.isArray(err)) return err[0] || '';
  return typeof err === 'string' ? err : '';
}

function resetForm() {
  form.journal_id = '';
  form.fiscal_year_id = '';
  form.accounting_period_id = '';
  form.entry_date = new Date().toISOString().slice(0, 10);
  form.reference = '';
  form.description = '';
  form.lines = [makeEmptyLine(), makeEmptyLine()];
  periods.value = [];
  serverErrors.value = {};
  isPosted.value = false;
  loadError.value = '';
}

function addLine() {
  form.lines.push(makeEmptyLine());
}

function removeLine(index) {
  if (form.lines.length <= 2) return;
  form.lines.splice(index, 1);
}

async function loadPeriodsForYear(fyId) {
  if (!fyId) {
    periods.value = [];
    return;
  }
  periodsLoading.value = true;
  try {
    const res = await fetchAccountingPeriods({ fiscal_year_id: fyId, per_page: 100 });
    periods.value = res.data?.data || [];
  } catch {
    periods.value = [];
  } finally {
    periodsLoading.value = false;
  }
}

async function onFiscalYearChange() {
  form.accounting_period_id = '';
  await loadPeriodsForYear(form.fiscal_year_id);
}

async function loadInitialData() {
  resetForm();
  initialLoading.value = true;
  try {
    const [jRes, fyRes] = await Promise.all([
      fetchJournals({ is_active: 1, per_page: 100 }),
      fetchFiscalYears({ per_page: 100 }),
    ]);
    journals.value = jRes.data?.data || [];
    fiscalYears.value = fyRes.data?.data || [];

    if (isEdit.value) {
      const res = await fetchJournalEntry(props.entryId);
      const entry = res.data?.data;
      if (!entry) {
        loadError.value = t('journalEntries.loadOneError');
        return;
      }

      if (entry.status !== 'draft') {
        isPosted.value = true;
        return;
      }

      form.journal_id = String(entry.journal_id || '');
      form.fiscal_year_id = String(entry.fiscal_year_id || '');
      await loadPeriodsForYear(form.fiscal_year_id);
      form.accounting_period_id = String(entry.accounting_period_id || '');
      form.entry_date = entry.entry_date ? String(entry.entry_date).slice(0, 10) : '';
      form.reference = entry.reference || '';
      form.description = entry.description || '';

      const loadedLines = Array.isArray(entry.lines) ? entry.lines : [];
      form.lines = loadedLines.length >= 2
        ? loadedLines.map((l) => ({
            uid: lineUidSeq++,
            account_id: l.account_id,
            initialOptions: l.account
              ? [{ value: l.account.id, label: `${l.account.code} — ${l.account.name}` }]
              : [],
            label: l.label || '',
            debit: String(l.debit ?? '0'),
            credit: String(l.credit ?? '0'),
          }))
        : [makeEmptyLine(), makeEmptyLine()];
    }
  } catch (err) {
    loadError.value = extractApiError(err, t('journalEntries.loadOneError'));
  } finally {
    initialLoading.value = false;
  }
}

function handleClose() {
  if (submitting.value) return;
  emit('close');
}

async function handleSubmit() {
  if (submitting.value) return;

  if (!totals.value.isBalanced) {
    notify.error(t('journalEntries.unbalancedError'));
    return;
  }

  serverErrors.value = {};
  submitting.value = true;

  const payload = {
    journal_id: Number(form.journal_id),
    fiscal_year_id: Number(form.fiscal_year_id),
    accounting_period_id: Number(form.accounting_period_id),
    entry_date: form.entry_date,
    reference: form.reference || null,
    description: form.description || null,
    lines: form.lines.map((line, index) => ({
      account_id: line.account_id ? Number(line.account_id) : null,
      line_number: index + 1,
      label: line.label || null,
      debit: line.debit === '' || line.debit === null ? 0 : line.debit,
      credit: line.credit === '' || line.credit === null ? 0 : line.credit,
    })),
  };

  try {
    const res = isEdit.value
      ? await updateJournalEntry(props.entryId, payload)
      : await createJournalEntry(payload);

    notify.success(t(isEdit.value ? 'journalEntries.updatedSuccess' : 'journalEntries.createdSuccess'));
    emit('saved', res.data?.data);
  } catch (err) {
    if (err?.response?.status === 422 && err?.response?.data?.errors) {
      serverErrors.value = err.response.data.errors;
    }
    notify.error(extractApiError(err));
  } finally {
    submitting.value = false;
  }
}

watch(
  () => [props.show, props.entryId],
  ([visible]) => {
    if (visible) {
      loadInitialData();
    }
  },
  { immediate: true }
);
</script>
