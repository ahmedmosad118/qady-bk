<template>
  <div class="space-y-6">
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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadAll">
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
              :clearable="!item.required"
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

    <!-- Payment Methods & Account Linking Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-6 py-4">
        <div>
          <h2 class="text-base font-extrabold text-[var(--text)]">
            {{ $t('accountingSettings.paymentMethodsTitle') }}
          </h2>
          <p class="mt-1 text-xs text-[var(--muted)]">
            {{ $t('accountingSettings.paymentMethodsHelp') }}
          </p>
        </div>

        <button
          v-if="canUpdate"
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white shadow-sm transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreatePaymentMethod"
        >
          <Plus class="h-4 w-4" />
          <span>{{ $t('accountingSettings.addPaymentMethod') }}</span>
        </button>
      </div>

      <div v-if="pmLoading" class="p-10 text-center">
        <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('common.loading') }}</span>
        </div>
      </div>

      <div v-else-if="paymentMethods.length === 0" class="p-8 text-center text-sm text-[var(--muted)]">
        {{ $t('accountingSettings.noPaymentMethods') }}
      </div>

      <div v-else class="divide-y divide-[var(--border)]">
        <div
          v-for="pm in paymentMethods"
          :key="pm.id"
          class="grid grid-cols-1 gap-4 px-6 py-4 lg:grid-cols-12 lg:items-center"
        >
          <div class="space-y-1 lg:col-span-5">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-sm font-extrabold text-[var(--text)]">{{ pm.name }}</span>
              <span class="inline-flex items-center rounded-full bg-blue-500/10 px-2 py-0.5 text-[11px] font-bold text-blue-700 dark:text-blue-300">
                {{ paymentMethodTypeLabel(pm.type) }}
              </span>
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold"
                :class="pm.chart_of_account
                  ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                  : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'"
              >
                {{ pm.chart_of_account ? $t('accountingSettings.linkedStatus') : $t('accountingSettings.unlinkedStatus') }}
              </span>
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold"
                :class="pm.is_active
                  ? 'bg-sky-500/10 text-sky-700 dark:text-sky-300'
                  : 'bg-[var(--bg)] text-[var(--muted)]'"
              >
                {{ pm.is_active ? $t('common.active') : $t('common.inactive') }}
              </span>
            </div>
            <p v-if="pm.chart_of_account" class="text-xs text-[var(--muted)]">
              <span class="font-mono font-semibold text-[var(--text)]">{{ pm.chart_of_account.code }}</span>
              — {{ pm.chart_of_account.name }}
            </p>
            <p v-else class="text-xs font-semibold text-amber-600 dark:text-amber-400">
              {{ $t('accountingSettings.unlinkedAccountWarning') }}
            </p>
          </div>

          <div class="lg:col-span-5">
            <SearchableSelect
              v-model="pmAccountSelections[pm.id]"
              :options="paymentMethodAccountOptions(pm.type)"
              :placeholder="$t('accountingSettings.selectAccountPlaceholder')"
              :clearable="false"
              :disabled="!canUpdate || pmSavingId === pm.id"
            />
            <p
              v-if="pmRowErrors[pm.id]"
              class="mt-1 text-xs font-semibold text-[var(--crimson)]"
            >
              {{ pmRowErrors[pm.id] }}
            </p>
          </div>

          <div v-if="canUpdate" class="flex items-center justify-end gap-2 lg:col-span-2">
            <button
              type="button"
              class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:opacity-50 cursor-pointer"
              :disabled="pmSavingId === pm.id || !pmAccountSelections[pm.id]"
              @click="savePaymentMethodAccount(pm)"
            >
              <span v-if="pmSavingId === pm.id" class="h-3 w-3 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
              <Save v-else class="h-3.5 w-3.5" />
              <span>{{ $t('common.save') }}</span>
            </button>

            <button
              type="button"
              :title="$t('common.edit')"
              class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] transition-colors hover:text-[var(--text)] cursor-pointer"
              @click="openEditPaymentMethod(pm)"
            >
              <Pencil class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Payment Method Add / Edit Drawer -->
    <AppDrawer
      :open="pmModalOpen"
      :title="editingPm ? $t('accountingSettings.editPaymentMethod') : $t('accountingSettings.addPaymentMethod')"
      :subtitle="$t('accountingSettings.paymentMethodModalSubtitle')"
      size="md"
      @close="pmModalOpen = false"
    >
      <form id="payment-method-form" class="space-y-4" @submit.prevent="submitPaymentMethodModal">
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('accountingSettings.paymentMethodName') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="pmForm.name"
            type="text"
            required
            :disabled="pmModalSaving"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          />
          <p v-if="pmModalErrors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ pmModalErrors.name[0] }}
          </p>
        </div>

        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('accountingSettings.paymentMethodType') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <select
            v-model="pmForm.type"
            :disabled="pmModalSaving"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          >
            <option v-for="typeOpt in paymentMethodTypeOptions" :key="typeOpt.value" :value="typeOpt.value">
              {{ typeOpt.label }}
            </option>
          </select>
          <p v-if="pmModalErrors.type" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ pmModalErrors.type[0] }}
          </p>
        </div>

        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('accountingSettings.linkedAccount') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <SearchableSelect
            v-model="pmForm.chart_of_account_id"
            :options="paymentMethodAccountOptions(pmForm.type)"
            :placeholder="$t('accountingSettings.selectAccountPlaceholder')"
            :clearable="false"
            :disabled="pmModalSaving"
          />
          <p v-if="pmModalErrors.chart_of_account_id" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ pmModalErrors.chart_of_account_id[0] }}
          </p>
        </div>

        <label class="flex items-center gap-2 text-sm font-semibold text-[var(--text)] cursor-pointer">
          <input v-model="pmForm.is_active" type="checkbox" class="h-4 w-4 accent-[var(--crimson)]" />
          <span>{{ $t('common.active') }}</span>
        </label>
      </form>

      <template #footer>
        <div class="flex items-center justify-end gap-3">
          <button
            type="button"
            class="inline-flex h-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--muted)] transition-colors hover:text-[var(--text)] cursor-pointer"
            :disabled="pmModalSaving"
            @click="pmModalOpen = false"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="submit"
            form="payment-method-form"
            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
            :disabled="pmModalSaving"
          >
            <span v-if="pmModalSaving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
            <span>{{ $t('common.save') }}</span>
          </button>
        </div>
      </template>
    </AppDrawer>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, Landmark, Pencil, Plus, Save } from 'lucide-vue-next';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';

const { t, te } = useI18n();
const authStore = useAuthStore();

const canUpdate = computed(() => authStore.hasPermission('accounting.settings.update'));

const items = ref([]);
const compatibleAccounts = ref({});
const form = reactive({});
const errors = ref({});
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');

const paymentMethods = ref([]);
const compatiblePaymentAccounts = ref({});
const pmAccountSelections = reactive({});
const pmRowErrors = reactive({});
const pmLoading = ref(false);
const pmSavingId = ref(null);

const pmModalOpen = ref(false);
const pmModalSaving = ref(false);
const pmModalErrors = ref({});
const editingPm = ref(null);
const pmForm = reactive({
  name: '',
  type: 'cash',
  chart_of_account_id: null,
  is_active: true,
});

const paymentMethodTypeOptions = computed(() => [
  { value: 'cash', label: t('pos.paymentMethods.cash') },
  { value: 'visa', label: t('pos.paymentMethods.visa') },
  { value: 'bank_transfer', label: t('pos.paymentMethods.bank_transfer') },
]);

function paymentMethodTypeLabel(type) {
  if (type && te(`pos.paymentMethods.${type}`)) {
    return t(`pos.paymentMethods.${type}`);
  }
  return type;
}

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

function paymentMethodAccountOptions(type) {
  const list = compatiblePaymentAccounts.value[type] || [];
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

function applyPaymentMethodsPayload(resData) {
  paymentMethods.value = resData?.data || [];
  compatiblePaymentAccounts.value = resData?.compatible_accounts || {};
  for (const pm of paymentMethods.value) {
    pmAccountSelections[pm.id] = pm.chart_of_account_id ?? null;
    pmRowErrors[pm.id] = '';
  }
}

async function loadSettings() {
  loading.value = true;
  try {
    const res = await api.get('/settings/accounting');
    applyResponsePayload(res.data.data);
  } catch (err) {
    loadError.value = err.response?.data?.message || t('accountingSettings.loadError');
  } finally {
    loading.value = false;
  }
}

async function loadPaymentMethods() {
  pmLoading.value = true;
  try {
    const res = await api.get('/payment-methods');
    applyPaymentMethodsPayload(res.data);
  } catch (err) {
    if (!loadError.value) {
      loadError.value = err.response?.data?.message || t('accountingSettings.loadError');
    }
  } finally {
    pmLoading.value = false;
  }
}

async function loadAll() {
  loadError.value = '';
  errors.value = {};
  await Promise.all([loadSettings(), loadPaymentMethods()]);
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

async function savePaymentMethodAccount(pm) {
  pmSavingId.value = pm.id;
  pmRowErrors[pm.id] = '';
  try {
    await api.put(`/payment-methods/${pm.id}`, {
      chart_of_account_id: Number(pmAccountSelections[pm.id]),
    });
    showSuccess({
      title: t('common.successTitle'),
      text: t('accountingSettings.paymentMethodSavedSuccess'),
    });
    await loadPaymentMethods();
  } catch (err) {
    const msg = err.response?.data?.errors?.chart_of_account_id?.[0]
      || err.response?.data?.message
      || t('common.errorTitle');
    pmRowErrors[pm.id] = msg;
    showError({ title: t('common.error'), text: msg });
  } finally {
    pmSavingId.value = null;
  }
}

function openCreatePaymentMethod() {
  editingPm.value = null;
  pmModalErrors.value = {};
  pmForm.name = '';
  pmForm.type = 'cash';
  pmForm.chart_of_account_id = null;
  pmForm.is_active = true;
  pmModalOpen.value = true;
}

function openEditPaymentMethod(pm) {
  editingPm.value = pm;
  pmModalErrors.value = {};
  pmForm.name = pm.name ?? '';
  pmForm.type = pm.type ?? 'cash';
  pmForm.chart_of_account_id = pm.chart_of_account_id ?? null;
  pmForm.is_active = Boolean(pm.is_active);
  pmModalOpen.value = true;
}

watch(
  () => pmForm.type,
  (newType) => {
    const allowed = compatiblePaymentAccounts.value[newType] || [];
    const stillValid = allowed.some((acc) => Number(acc.id) === Number(pmForm.chart_of_account_id));
    if (!stillValid) {
      pmForm.chart_of_account_id = null;
    }
  }
);

async function submitPaymentMethodModal() {
  pmModalSaving.value = true;
  pmModalErrors.value = {};

  const payload = {
    name: pmForm.name,
    type: pmForm.type,
    chart_of_account_id: pmForm.chart_of_account_id ? Number(pmForm.chart_of_account_id) : null,
    is_active: Boolean(pmForm.is_active),
  };

  try {
    if (editingPm.value) {
      await api.put(`/payment-methods/${editingPm.value.id}`, payload);
    } else {
      await api.post('/payment-methods', payload);
    }
    showSuccess({
      title: t('common.successTitle'),
      text: t('accountingSettings.paymentMethodSavedSuccess'),
    });
    pmModalOpen.value = false;
    await loadPaymentMethods();
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      pmModalErrors.value = err.response.data.errors;
    }
    const firstMsg = Object.values(pmModalErrors.value)[0]?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: firstMsg });
  } finally {
    pmModalSaving.value = false;
  }
}

onMounted(() => {
  loadAll();
});
</script>
