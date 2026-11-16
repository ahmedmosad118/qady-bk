<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <PageHeader :title="$t('purchaseSettings.title')" :subtitle="$t('purchaseSettings.subtitle')" />

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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadSettings">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Purchase Settings Card -->
    <form
      class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
      @submit.prevent="saveSettings"
    >
      <div class="border-b border-[var(--border)] px-6 py-4">
        <h2 class="text-base font-extrabold text-[var(--text)]">
          {{ $t('purchaseSettings.expensesTreatment') }}
        </h2>
        <p class="mt-1 text-xs text-[var(--muted)]">
          {{ $t('purchaseSettings.expensesTreatmentHelp') }}
        </p>
      </div>

      <div v-if="loading" class="p-8 text-center">
        <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('common.loading') }}</span>
        </div>
      </div>

      <div v-else class="p-6 space-y-4">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <!-- Landed Cost Option -->
          <label
            class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition-all"
            :class="expensesTreatment === 'landed_cost'
              ? 'border-[var(--crimson)] bg-[var(--crimson-soft)]/50 ring-2 ring-[var(--crimson-soft)]'
              : 'border-[var(--border)] bg-[var(--bg)]/40 hover:border-[var(--crimson)]/40'"
          >
            <input
              v-model="expensesTreatment"
              type="radio"
              value="landed_cost"
              name="expenses_treatment"
              class="mt-1 h-4 w-4 accent-[var(--crimson)]"
            />
            <div class="space-y-1">
              <div class="text-sm font-extrabold text-[var(--text)]">
                {{ $t('purchaseSettings.landedCostTitle') }}
              </div>
              <p class="text-xs leading-5 text-[var(--muted)]">
                {{ $t('purchaseSettings.landedCostDesc') }}
              </p>
            </div>
          </label>

          <!-- Separate Expense Option -->
          <label
            class="flex cursor-pointer items-start gap-3 rounded-2xl border p-4 transition-all"
            :class="expensesTreatment === 'separate_expense'
              ? 'border-[var(--crimson)] bg-[var(--crimson-soft)]/50 ring-2 ring-[var(--crimson-soft)]'
              : 'border-[var(--border)] bg-[var(--bg)]/40 hover:border-[var(--crimson)]/40'"
          >
            <input
              v-model="expensesTreatment"
              type="radio"
              value="separate_expense"
              name="expenses_treatment"
              class="mt-1 h-4 w-4 accent-[var(--crimson)]"
            />
            <div class="space-y-1">
              <div class="text-sm font-extrabold text-[var(--text)]">
                {{ $t('purchaseSettings.separateExpenseTitle') }}
              </div>
              <p class="text-xs leading-5 text-[var(--muted)]">
                {{ $t('purchaseSettings.separateExpenseDesc') }}
              </p>
            </div>
          </label>
        </div>

        <p v-if="validationError" class="text-xs font-semibold text-[var(--crimson)]">
          {{ validationError }}
        </p>
      </div>

      <div class="flex items-center justify-end border-t border-[var(--border)] bg-[var(--bg)] px-6 py-4">
        <button
          type="submit"
          class="inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2.5 text-xs font-bold text-white shadow-sm shadow-[var(--crimson)]/20 hover:bg-[var(--crimson-hover)] disabled:opacity-50"
          :disabled="loading || saving"
        >
          <span v-if="saving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
          <Save v-else class="h-4 w-4" />
          <span>{{ $t('purchaseSettings.save') }}</span>
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import { showSuccess, showError } from '../../services/alert';
import { AlertCircle, Save } from 'lucide-vue-next';
import PageHeader from '../../components/ui/PageHeader.vue';

const { t } = useI18n();

const expensesTreatment = ref('landed_cost');
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const validationError = ref('');

async function loadSettings() {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await api.get('/settings/purchase');
    expensesTreatment.value = res.data.data?.expenses_treatment || 'landed_cost';
  } catch (err) {
    loadError.value = err.response?.data?.message || t('purchaseSettings.loadError');
  } finally {
    loading.value = false;
  }
}

async function saveSettings() {
  saving.value = true;
  validationError.value = '';
  try {
    const res = await api.put('/settings/purchase', {
      expenses_treatment: expensesTreatment.value,
    });
    expensesTreatment.value = res.data.data?.expenses_treatment || expensesTreatment.value;
    showSuccess({
      title: t('common.successTitle'),
      text: t('purchaseSettings.savedSuccess'),
    });
  } catch (err) {
    const msg = err.response?.data?.errors?.expenses_treatment?.[0] || err.response?.data?.message || t('common.errorTitle');
    validationError.value = msg;
    showError({
      title: t('common.error'),
      text: msg,
    });
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadSettings();
});
</script>
