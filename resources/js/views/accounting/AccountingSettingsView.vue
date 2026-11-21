<template>
  <div class="space-y-6">
    <!-- Page Header -->
    <PageHeader :title="$t('accountingSettings.title')" :subtitle="$t('accountingSettings.subtitle')">
      <template #actions>
        <router-link
          to="/accounting/accounts"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <FolderTree class="h-[18px] w-[18px] text-[var(--muted)]" aria-hidden="true" />
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

    <!-- Settings Card -->
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
                :class="item.required
                  ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
                  : 'bg-[var(--bg)] text-[var(--muted)]'"
              >
                {{ item.required ? $t('accountingSettings.required') : $t('accountingSettings.optional') }}
              </span>
              <span class="inline-flex items-center rounded-full bg-blue-500/10 px-2 py-0.5 text-[11px] font-bold text-blue-700 dark:text-blue-300">
                {{ item.account_type_label }}
              </span>
              <span class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:text-emerald-300">
                {{ item.is_control_account ? $t('accountingSettings.controlAccount') : $t('accountingSettings.detailAccount') }}
              </span>
            </div>
            <p class="text-xs leading-5 text-[var(--muted)]">
              {{ item.description }}
            </p>
          </div>

          <!-- Account selector -->
          <div class="lg:col-span-6">
            <SearchableSelect
              v-model="form[item.key]"
              :options="optionsForKey(item.key)"
              :placeholder="$t('accountingSettings.selectAccountPlaceholder')"
              :clearable="!item.required"
              :disabled="saving"
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

      <div class="flex items-center justify-end border-t border-[var(--border)] bg-[var(--bg)] px-6 py-4">
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
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, FolderTree, Save } from 'lucide-vue-next';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';
import PageHeader from '../../components/ui/PageHeader.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';

const { t } = useI18n();

const items = ref([]);
const compatibleAccounts = ref({});
const form = reactive({});
const errors = ref({});
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');

function optionsForKey(key) {
  const list = compatibleAccounts.value[key] || [];
  return list.map((acc) => ({
    value: acc.id,
    label: `${acc.code} — ${acc.name}`,
  }));
}

function fieldError(key) {
  return errors.value[`settings.${key}`]?.[0] || '';
}

function applyResponsePayload(data) {
  items.value = data?.items || [];
  compatibleAccounts.value = data?.compatible_accounts || {};
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
  saving.value = true;
  errors.value = {};

  const payload = {};
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
