<template>
  <AppDrawer
    :open="open"
    :title="isEditing ? $t('chartOfAccounts.editAccount') : $t('chartOfAccounts.addAccount')"
    :subtitle="isEditing ? $t('chartOfAccounts.editSubtitle') : $t('chartOfAccounts.addSubtitle')"
    size="md"
    @close="$emit('close')"
  >
    <form id="chart-account-form" class="space-y-4" @submit.prevent="handleSubmit">
      <!-- Account Type -->
      <div>
        <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
          {{ $t('chartOfAccounts.accountType') }} <span class="text-[var(--crimson)]">*</span>
        </label>
        <select
          v-model="form.account_type"
          :disabled="isTypeLocked || saving"
          class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:cursor-not-allowed disabled:opacity-60"
        >
          <option v-for="typeOpt in accountTypes" :key="typeOpt.value" :value="typeOpt.value">
            {{ typeOpt.label }}
          </option>
        </select>
        <p v-if="errors.account_type" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
          {{ errors.account_type[0] }}
        </p>
      </div>

      <!-- Account Purpose (Asset accounts only) -->
      <div v-if="form.account_type === 'asset'">
        <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
          {{ $t('chartOfAccounts.accountPurpose') }}
        </label>
        <select
          v-model="form.account_purpose"
          :disabled="isPurposeLocked || saving"
          class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:cursor-not-allowed disabled:opacity-60"
        >
          <option v-for="purposeOpt in accountPurposes" :key="purposeOpt.value" :value="purposeOpt.value">
            {{ purposeOpt.label }}
          </option>
        </select>
        <p class="mt-1 text-[11px] text-[var(--muted)]">
          {{ $t('chartOfAccounts.purposeHint') }}
        </p>
        <p v-if="errors.account_purpose" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
          {{ errors.account_purpose[0] }}
        </p>
      </div>

      <!-- Account Code -->
      <div>
        <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
          {{ $t('chartOfAccounts.accountCode') }}
          <span v-if="isEditing" class="text-[var(--crimson)]">*</span>
        </label>
        <input
          v-model="form.code"
          type="text"
          :placeholder="isEditing ? '' : $t('chartOfAccounts.autoCodePlaceholder')"
          :disabled="saving"
          class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 font-mono text-sm font-semibold text-[var(--text)] outline-none transition placeholder:font-sans placeholder:text-xs focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
        />
        <p class="mt-1 text-[11px] text-[var(--muted)]">
          {{ isEditing ? $t('chartOfAccounts.editCodeHint') : $t('chartOfAccounts.autoCodeHint') }}
        </p>
        <p v-if="errors.code" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
          {{ errors.code[0] }}
        </p>
      </div>

      <!-- Account Name -->
      <div>
        <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
          {{ $t('chartOfAccounts.accountName') }} <span class="text-[var(--crimson)]">*</span>
        </label>
        <input
          v-model="form.name"
          type="text"
          required
          :placeholder="$t('chartOfAccounts.namePlaceholder')"
          :disabled="saving"
          class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
        />
        <p v-if="errors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
          {{ errors.name[0] }}
        </p>
      </div>

      <!-- Description -->
      <div>
        <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
          {{ $t('common.description') }}
        </label>
        <textarea
          v-model="form.description"
          rows="3"
          :placeholder="$t('chartOfAccounts.descriptionPlaceholder')"
          :disabled="saving"
          class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 py-2.5 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
        />
        <p v-if="errors.description" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
          {{ errors.description[0] }}
        </p>
      </div>
    </form>

    <template #footer>
      <div class="flex items-center justify-end gap-3">
        <button
          type="button"
          class="inline-flex h-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--muted)] transition-colors hover:text-[var(--text)] cursor-pointer"
          :disabled="saving"
          @click="$emit('close')"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          type="submit"
          form="chart-account-form"
          class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
          :disabled="saving"
        >
          <span v-if="saving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
          <span>{{ $t('common.save') }}</span>
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';
import AppDrawer from '../../components/ui/AppDrawer.vue';

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  editingAccount: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();

const saving = ref(false);
const errors = ref({});

const form = reactive({
  code: '',
  name: '',
  account_type: 'asset',
  account_purpose: 'general',
  description: '',
});

const isEditing = computed(() => Boolean(props.editingAccount?.id));

const accountTypes = computed(() => [
  { value: 'asset', label: t('chartOfAccounts.types.asset') },
  { value: 'liability', label: t('chartOfAccounts.types.liability') },
  { value: 'equity', label: t('chartOfAccounts.types.equity') },
  { value: 'revenue', label: t('chartOfAccounts.types.revenue') },
  { value: 'expense', label: t('chartOfAccounts.types.expense') },
]);

const accountPurposes = computed(() => [
  { value: 'general', label: t('chartOfAccounts.purposes.general') },
  { value: 'cash', label: t('chartOfAccounts.purposes.cash') },
  { value: 'bank', label: t('chartOfAccounts.purposes.bank') },
]);

const isTypeLocked = computed(() => {
  if (!isEditing.value) return false;
  return Boolean(
    props.editingAccount?.is_linked_to_settings
    || props.editingAccount?.is_linked_to_payment_method
  );
});

const isPurposeLocked = computed(() => {
  if (!isEditing.value) return false;
  return Boolean(
    props.editingAccount?.is_linked_to_settings
    || props.editingAccount?.is_linked_to_payment_method
  );
});

watch(
  () => [props.open, props.editingAccount],
  () => {
    errors.value = {};
    if (!props.open) return;

    if (props.editingAccount) {
      form.code = props.editingAccount.code ?? '';
      form.name = props.editingAccount.name ?? '';
      form.account_type = props.editingAccount.account_type ?? 'asset';
      form.account_purpose = props.editingAccount.account_purpose ?? 'general';
      form.description = props.editingAccount.description ?? '';
    } else {
      form.code = '';
      form.name = '';
      form.account_type = 'asset';
      form.account_purpose = 'general';
      form.description = '';
    }
  },
  { immediate: true }
);

watch(
  () => form.account_type,
  (newType) => {
    if (newType !== 'asset') {
      form.account_purpose = 'general';
    }
  }
);

async function handleSubmit() {
  saving.value = true;
  errors.value = {};

  const payload = {
    name: form.name,
    account_type: form.account_type,
    account_purpose: form.account_type === 'asset' ? form.account_purpose : 'general',
    description: form.description || null,
  };

  if (form.code && form.code.trim() !== '') {
    payload.code = form.code.trim();
  }

  try {
    if (isEditing.value) {
      await api.put(`/accounting/accounts/${props.editingAccount.id}`, payload);
      showSuccess({ title: t('common.successTitle'), text: t('chartOfAccounts.updatedSuccess') });
    } else {
      await api.post('/accounting/accounts', payload);
      showSuccess({ title: t('common.successTitle'), text: t('chartOfAccounts.createdSuccess') });
    }
    emit('saved');
    emit('close');
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
    }
    const firstError = Object.values(errors.value)[0]?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: firstError });
  } finally {
    saving.value = false;
  }
}
</script>
