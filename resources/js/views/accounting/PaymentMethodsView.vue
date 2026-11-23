<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader
      :title="$t('accountingSettings.paymentMethodsTitle')"
      :subtitle="$t('accountingSettings.paymentMethodsHelp')"
    >
      <template #actions>
        <button
          v-if="canUpdate"
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreatePaymentMethod"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('accountingSettings.addPaymentMethod') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <FilterToolbar
        v-model:search="searchQuery"
        :search-placeholder="$t('common.searchPlaceholder')"
        :active-filters-count="0"
        @reset="searchQuery = ''"
      />

      <div
        v-if="loadError"
        class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center"
        role="alert"
      >
        <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <AlertCircle class="h-6 w-6" />
        </div>
        <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadPaymentMethods"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingSettings.paymentMethodName') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingSettings.paymentMethodType') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('accountingSettings.linkedAccount') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('common.status') }}</th>
              <th v-if="canUpdate" class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <tr v-if="loading && paymentMethods.length === 0">
              <td :colspan="canUpdate ? 5 : 4" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <tr v-else-if="filteredPaymentMethods.length === 0">
              <td :colspan="canUpdate ? 5 : 4" class="py-16 text-center text-sm text-[var(--muted)]">
                {{ $t('accountingSettings.noPaymentMethods') }}
              </td>
            </tr>

            <tr
              v-for="pm in filteredPaymentMethods"
              :key="pm.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-5 py-4 font-bold text-[var(--text)]">
                {{ pm.name }}
              </td>

              <td class="px-5 py-4">
                <span class="inline-flex items-center rounded-full bg-blue-500/10 px-2.5 py-0.5 text-xs font-bold text-blue-700 dark:text-blue-300">
                  {{ paymentMethodTypeLabel(pm.type) }}
                </span>
              </td>

              <td class="px-5 py-4">
                <span v-if="pm.account" class="text-xs font-semibold text-[var(--text)]">
                  <span class="font-mono font-bold">{{ pm.account.code }}</span>
                  — {{ pm.account.name }}
                </span>
                <span
                  v-else
                  class="inline-flex items-center rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs font-bold text-amber-700 dark:text-amber-300"
                >
                  {{ $t('accountingSettings.unlinkedStatus') }}
                </span>
              </td>

              <td class="px-5 py-4">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                  :class="pm.is_active
                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    : 'bg-[var(--bg)] text-[var(--muted)]'"
                >
                  {{ pm.is_active ? $t('common.active') : $t('common.inactive') }}
                </span>
              </td>

              <td v-if="canUpdate" class="px-5 py-4 text-end">
                <button
                  type="button"
                  :title="$t('common.edit')"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                  @click="openEditPaymentMethod(pm)"
                >
                  <Pencil class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
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
          <div>
            <h2 class="text-lg font-extrabold text-[var(--text)]">
              {{ editingPm ? $t('accountingSettings.editPaymentMethod') : $t('accountingSettings.addPaymentMethod') }}
            </h2>
            <p class="mt-0.5 text-xs text-[var(--muted)]">
              {{ $t('accountingSettings.paymentMethodModalSubtitle') }}
            </p>
          </div>
          <button
            type="button"
            class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            @click="modalOpen = false"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form id="payment-method-form" class="space-y-4 p-6" @submit.prevent="submitPaymentMethodModal">
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('accountingSettings.paymentMethodName') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <input
              v-model="pmForm.name"
              type="text"
              required
              :disabled="modalSaving"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            />
            <p v-if="modalErrors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
              {{ modalErrors.name[0] }}
            </p>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('accountingSettings.paymentMethodType') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <select
              v-model="pmForm.type"
              :disabled="modalSaving"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            >
              <option v-for="typeOpt in paymentMethodTypeOptions" :key="typeOpt.value" :value="typeOpt.value">
                {{ typeOpt.label }}
              </option>
            </select>
            <p v-if="modalErrors.type" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
              {{ modalErrors.type[0] }}
            </p>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('accountingSettings.linkedAccount') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <SearchableSelect
              v-model="pmForm.account_id"
              :options="paymentMethodAccountOptions(pmForm.type)"
              :placeholder="$t('accountingSettings.selectAccountPlaceholder')"
              :clearable="false"
              :disabled="modalSaving"
            />
            <p v-if="modalErrors.account_id" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
              {{ modalErrors.account_id[0] }}
            </p>
          </div>

          <label class="flex items-center gap-2 text-sm font-semibold text-[var(--text)] cursor-pointer">
            <input v-model="pmForm.is_active" type="checkbox" class="h-4 w-4 accent-[var(--crimson)]" />
            <span>{{ $t('common.active') }}</span>
          </label>

          <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] pt-4">
            <button
              type="button"
              class="inline-flex h-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--muted)] transition-colors hover:text-[var(--text)] cursor-pointer"
              :disabled="modalSaving"
              @click="modalOpen = false"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
              :disabled="modalSaving"
            >
              <span v-if="modalSaving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
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
import { useI18n } from 'vue-i18n';
import { AlertCircle, Pencil, Plus, X } from 'lucide-vue-next';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import SearchableSelect from '../../components/common/SearchableSelect.vue';

const { t, te } = useI18n();
const authStore = useAuthStore();

const canUpdate = computed(() => authStore.hasPermission('accounting.settings.update'));

const paymentMethods = ref([]);
const compatiblePaymentAccounts = ref({});
const searchQuery = ref('');
const loading = ref(false);
const loadError = ref('');

const modalOpen = ref(false);
const modalSaving = ref(false);
const modalErrors = ref({});
const editingPm = ref(null);
const pmForm = reactive({
  name: '',
  type: 'cash',
  account_id: null,
  is_active: true,
});

const paymentMethodTypeOptions = computed(() => [
  { value: 'cash', label: t('pos.paymentMethods.cash') },
  { value: 'visa', label: t('pos.paymentMethods.visa') },
  { value: 'bank_transfer', label: t('pos.paymentMethods.bank_transfer') },
]);

const filteredPaymentMethods = computed(() => {
  const term = searchQuery.value.trim().toLowerCase();
  if (!term) {
    return paymentMethods.value;
  }

  return paymentMethods.value.filter((pm) => {
    const nameMatch = (pm.name || '').toLowerCase().includes(term);
    const accName = (pm.account?.name || '').toLowerCase().includes(term);
    const accCode = (pm.account?.code || '').toLowerCase().includes(term);
    return nameMatch || accName || accCode;
  });
});

function paymentMethodTypeLabel(type) {
  if (type && te(`pos.paymentMethods.${type}`)) {
    return t(`pos.paymentMethods.${type}`);
  }
  return type;
}

function paymentMethodAccountOptions(type) {
  const list = compatiblePaymentAccounts.value[type] || [];
  return list.map((acc) => ({
    value: acc.id,
    label: `${acc.code} — ${acc.name}`,
  }));
}

async function loadPaymentMethods() {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await api.get('/payment-methods');
    paymentMethods.value = res.data?.data || [];
    compatiblePaymentAccounts.value = res.data?.compatible_accounts || {};
  } catch (err) {
    loadError.value = err.response?.data?.message || t('accountingSettings.loadError');
  } finally {
    loading.value = false;
  }
}

function openCreatePaymentMethod() {
  editingPm.value = null;
  modalErrors.value = {};
  pmForm.name = '';
  pmForm.type = 'cash';
  pmForm.account_id = null;
  pmForm.is_active = true;
  modalOpen.value = true;
}

function openEditPaymentMethod(pm) {
  editingPm.value = pm;
  modalErrors.value = {};
  pmForm.name = pm.name ?? '';
  pmForm.type = pm.type ?? 'cash';
  pmForm.account_id = pm.account_id ?? null;
  pmForm.is_active = Boolean(pm.is_active);
  modalOpen.value = true;
}

async function submitPaymentMethodModal() {
  modalSaving.value = true;
  modalErrors.value = {};

  const payload = {
    name: pmForm.name,
    type: pmForm.type,
    account_id: pmForm.account_id ? Number(pmForm.account_id) : null,
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
    modalOpen.value = false;
    await loadPaymentMethods();
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      modalErrors.value = err.response.data.errors;
    }
    const firstMsg = Object.values(modalErrors.value)[0]?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: firstMsg });
  } finally {
    modalSaving.value = false;
  }
}

onMounted(() => {
  loadPaymentMethods();
});
</script>
