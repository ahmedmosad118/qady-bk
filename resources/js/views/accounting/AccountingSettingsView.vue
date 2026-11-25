<template>
  <div class="space-y-4">
    <!-- Breadcrumbs -->
    <nav class="flex items-center gap-1.5 text-xs font-bold text-[var(--muted)]" aria-label="Breadcrumb">
      <span>{{ $t('nav.accountingGroup') }}</span>
      <ChevronLeft class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
      <span class="text-[var(--text)]">{{ $t('accountingSettings.title') }}</span>
    </nav>

    <!-- Page Header -->
    <PageHeader :title="$t('accountingSettings.title')" :subtitle="$t('accountingSettings.subtitle')">
      <template #actions>
        <router-link
          to="/accounting/accounts"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Landmark class="h-[18px] w-[18px] text-[var(--muted)]" aria-hidden="true" />
          <span>{{ $t('nav.chartOfAccounts') }}</span>
        </router-link>
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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadSettings">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Accounting Settings Card -->
    <form
      class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]"
      @submit.prevent="saveSettings"
    >
      <div class="border-b border-[var(--border)] px-6 py-4">
        <h2 class="text-base font-extrabold text-[var(--text)]">
          {{ $t('accountingSettings.mappingTitle') }}
        </h2>
        <p class="mt-1 text-xs text-[var(--muted)]">
          {{ $t('accountingSettings.mappingHelp') }}
        </p>
      </div>

      <div v-if="loading" class="p-10 text-center">
        <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('common.loading') }}</span>
        </div>
      </div>

      <div v-else class="divide-y divide-[var(--border)]">
        <!-- Purchase Additional Expenses Treatment -->
        <div class="grid grid-cols-1 gap-4 px-6 py-4 lg:grid-cols-12 lg:items-center">
          <div class="space-y-1 lg:col-span-6">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-extrabold text-[var(--text)]">
                {{ $t('accountingSettings.expensesTreatmentLabel') }}
              </span>
              <span class="inline-flex items-center rounded-full bg-[var(--crimson-soft)] px-2 py-0.5 text-[11px] font-bold text-[var(--crimson)]">
                {{ $t('accountingSettings.required') }}
              </span>
            </div>
            <p class="text-xs leading-5 text-[var(--muted)]">
              {{ $t('accountingSettings.expensesTreatmentDesc') }}
            </p>
          </div>

          <div class="lg:col-span-6">
            <select
              v-model="expensesTreatment"
              :disabled="saving || !canUpdate"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:opacity-60"
            >
              <option value="LANDED_COST">{{ $t('accountingSettings.treatments.LANDED_COST') }}</option>
              <option value="SEPARATE_EXPENSE">{{ $t('accountingSettings.treatments.SEPARATE_EXPENSE') }}</option>
            </select>
            <p
              v-if="fieldError('expenses_treatment')"
              class="mt-1 text-xs font-semibold text-[var(--crimson)]"
            >
              {{ fieldError('expenses_treatment') }}
            </p>
          </div>
        </div>

        <!-- Default Purchase Journal -->
        <div class="grid grid-cols-1 gap-4 px-6 py-4 lg:grid-cols-12 lg:items-center">
          <div class="space-y-1 lg:col-span-6">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-extrabold text-[var(--text)]">
                {{ $t('accountingSettings.defaultPurchaseJournalLabel') }}
              </span>
              <span class="inline-flex items-center rounded-full bg-[var(--bg)] px-2 py-0.5 text-[11px] font-bold text-[var(--muted)]">
                {{ $t('accountingSettings.optional') }}
              </span>
            </div>
            <p class="text-xs leading-5 text-[var(--muted)]">
              {{ $t('accountingSettings.defaultPurchaseJournalDesc') }}
            </p>
          </div>

          <div class="lg:col-span-6">
            <SearchableSelect
              v-model="defaultPurchaseJournalId"
              :options="purchaseJournalOptions"
              :placeholder="$t('accountingSettings.selectDefaultJournalPlaceholder')"
              :clearable="true"
              :disabled="saving || !canUpdate"
            />
            <p
              v-if="fieldError('default_purchase_journal_id')"
              class="mt-1 text-xs font-semibold text-[var(--crimson)]"
            >
              {{ fieldError('default_purchase_journal_id') }}
            </p>
          </div>
        </div>

        <div
          v-for="item in items"
          :key="item.key"
          class="grid grid-cols-1 gap-4 px-6 py-4 lg:grid-cols-12 lg:items-center"
        >
          <!-- Function info -->
          <div class="space-y-1 lg:col-span-6">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-extrabold text-[var(--text)]">{{ item.label }}</span>
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold"
                :class="isItemRequired(item)
                  ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
                  : 'bg-[var(--bg)] text-[var(--muted)]'"
              >
                {{ isItemRequired(item) ? $t('accountingSettings.required') : $t('accountingSettings.optional') }}
              </span>
              <span class="inline-flex items-center rounded-full bg-blue-500/10 px-2 py-0.5 text-[11px] font-bold text-blue-700 dark:text-blue-300">
                {{ item.account_type_label }}
              </span>
            </div>
            <p class="text-xs leading-5 text-[var(--muted)]">
              {{ item.description }}
            </p>
            <p v-if="item.account" class="text-xs text-[var(--muted)]">
              {{ $t('accountingSettings.currentAccount') }}:
              <span class="font-mono font-semibold text-[var(--text)]">{{ item.account.code }}</span>
              — {{ item.account.name }}
            </p>
          </div>

          <!-- Account selector -->
          <div class="lg:col-span-6">
            <SearchableSelect
              v-model="form[item.key]"
              :options="optionsForKey(item.key)"
              :placeholder="$t('accountingSettings.selectAccountPlaceholder')"
              :clearable="!isItemRequired(item)"
              :disabled="saving || !canUpdate"
            />
            <p
              v-if="fieldError(item.key)"
              class="mt-1 text-xs font-semibold text-[var(--crimson)]"
            >
              {{ fieldError(item.key) }}
            </p>
          </div>
        </div>
      </div>

      <div v-if="canUpdate" class="flex items-center justify-end border-t border-[var(--border)] bg-[var(--bg)] px-6 py-4">
        <button
          type="submit"
          class="inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2.5 text-xs font-bold text-white shadow-sm shadow-[var(--crimson)]/20 hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
          :disabled="loading || saving"
        >
          <span v-if="saving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
          <Save v-else class="h-4 w-4" />
          <span>{{ $t('accountingSettings.save') }}</span>
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, ChevronLeft, Landmark, Save } from 'lucide-vue-next';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';

const { t } = useI18n();
const authStore = useAuthStore();

const canUpdate = computed(() => authStore.hasPermission('accounting.settings.update'));

const items = ref([]);
const compatibleAccounts = ref({});
const expensesTreatment = ref('LANDED_COST');
const defaultPurchaseJournalId = ref(null);
const purchaseJournals = ref([]);
const form = reactive({});
const errors = ref({});
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');

function optionsForKey(key) {
  const list = compatibleAccounts.value[key] || [];
  const usedElsewhere = new Set();
  for (const item of items.value) {
    if (item.key !== key && form[item.key]) {
      usedElsewhere.add(Number(form[item.key]));
    }
  }

  return list
    .filter((acc) => !usedElsewhere.has(Number(acc.id)))
    .map((acc) => ({
      value: acc.id,
      label: `${acc.code} — ${acc.name}`,
    }));
}

const purchaseJournalOptions = computed(() =>
  purchaseJournals.value.map((journal) => ({
    value: journal.id,
    label: `${journal.code} — ${journal.name}`,
  }))
);

function isItemRequired(item) {
  if (item.key === 'additional_expenses_account_id') {
    return expensesTreatment.value === 'SEPARATE_EXPENSE';
  }
  return !!item.required;
}

function fieldError(key) {
  return errors.value[`settings.${key}`]?.[0] || '';
}

function applyResponsePayload(data) {
  items.value = data?.items || [];
  compatibleAccounts.value = data?.compatible_accounts || {};
  expensesTreatment.value = data?.expenses_treatment || data?.data?.expenses_treatment || 'LANDED_COST';
  defaultPurchaseJournalId.value = data?.default_purchase_journal_id ?? data?.data?.default_purchase_journal_id ?? null;
  purchaseJournals.value = data?.purchase_journals || [];
  for (const item of items.value) {
    form[item.key] = item.account_id ?? null;
  }
}

async function loadSettings() {
  loading.value = true;
  loadError.value = '';
  errors.value = {};
  try {
    const res = await api.get('/settings/accounting');
    applyResponsePayload(res.data.data);
  } catch (err) {
    loadError.value = err.response?.data?.message || t('accountingSettings.loadError');
  } finally {
    loading.value = false;
  }
}

async function saveSettings() {
  if (saving.value) return;
  saving.value = true;
  errors.value = {};

  const payload = {
    expenses_treatment: expensesTreatment.value,
    default_purchase_journal_id: defaultPurchaseJournalId.value ? Number(defaultPurchaseJournalId.value) : null,
  };
  for (const item of items.value) {
    payload[item.key] = form[item.key] ? Number(form[item.key]) : null;
  }

  try {
    const res = await api.put('/settings/accounting', { settings: payload });
    applyResponsePayload(res.data.data);
    showSuccess({
      title: t('common.successTitle'),
      text: t('accountingSettings.savedSuccess'),
    });
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
    }
    const firstMsg = Object.values(errors.value)[0]?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({
      title: t('common.error'),
      text: firstMsg,
    });
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadSettings();
});
</script>
