<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4"
    @click.self="$emit('close')"
  >
    <div class="w-full max-w-lg rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xl">
      <!-- Header -->
      <div class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4">
        <div>
          <h2 class="text-lg font-extrabold text-[var(--text)]">
            {{ isEdit ? $t('chartOfAccounts.editAccount') : $t('chartOfAccounts.addAccount') }}
          </h2>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            {{ isEdit ? $t('chartOfAccounts.editSubtitle') : $t('chartOfAccounts.addSubtitle') }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
          @click="$emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <!-- Form -->
      <form class="space-y-4 p-6" @submit.prevent="submit">
        <div
          v-if="isEdit && editingAccount?.is_system"
          class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-3.5 py-2.5 text-xs font-semibold text-amber-700 dark:text-amber-300"
        >
          {{ $t('chartOfAccounts.systemLockedHint') }}
        </div>

        <!-- Account Type -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('chartOfAccounts.accountType') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <select
            v-model="form.account_type_id"
            required
            :disabled="saving || (isEdit && editingAccount?.is_system)"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:opacity-60"
          >
            <option value="" disabled>{{ $t('chartOfAccounts.selectAccountType') }}</option>
            <optgroup
              v-for="cls in accountClasses"
              :key="cls.id"
              :label="cls.name"
            >
              <option
                v-for="type in cls.account_types || []"
                :key="type.id"
                :value="type.id"
                :disabled="!type.is_active"
              >
                {{ type.name }}
              </option>
            </optgroup>
          </select>
          <p v-if="errors.account_type_id" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ errors.account_type_id[0] }}
          </p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <!-- Code -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('chartOfAccounts.accountCode') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <input
              v-model="form.code"
              type="text"
              required
              maxlength="30"
              dir="ltr"
              :disabled="saving || (isEdit && editingAccount?.is_system)"
              :placeholder="$t('chartOfAccounts.codePlaceholder')"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-mono font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:opacity-60"
            />
            <p v-if="errors.code" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
              {{ errors.code[0] }}
            </p>
          </div>

          <!-- Normal Balance Override -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('chartOfAccounts.normalBalanceOverride') }}
            </label>
            <select
              v-model="form.normal_balance_override"
              :disabled="saving"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            >
              <option value="">{{ $t('chartOfAccounts.normalBalanceDefault') }}</option>
              <option value="debit">{{ $t('chartOfAccounts.normalBalances.debit') }}</option>
              <option value="credit">{{ $t('chartOfAccounts.normalBalances.credit') }}</option>
            </select>
            <p v-if="errors.normal_balance_override" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
              {{ errors.normal_balance_override[0] }}
            </p>
          </div>
        </div>

        <!-- Name -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('chartOfAccounts.accountName') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="form.name"
            type="text"
            required
            maxlength="150"
            :disabled="saving"
            :placeholder="$t('chartOfAccounts.namePlaceholder')"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          />
          <p v-if="errors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ errors.name[0] }}
          </p>
        </div>

        <!-- Reconcile & Is Active -->
        <div class="flex flex-wrap items-center gap-6 pt-1">
          <label class="flex items-center gap-2 text-sm font-semibold text-[var(--text)] cursor-pointer">
            <input v-model="form.reconcile" type="checkbox" :disabled="saving" class="h-4 w-4 accent-[var(--crimson)]" />
            <span>{{ $t('chartOfAccounts.reconcile') }}</span>
          </label>

          <label class="flex items-center gap-2 text-sm font-semibold text-[var(--text)] cursor-pointer">
            <input
              v-model="form.is_active"
              type="checkbox"
              :disabled="saving || (isEdit && editingAccount?.is_system)"
              class="h-4 w-4 accent-[var(--crimson)]"
            />
            <span>{{ $t('common.active') }}</span>
          </label>
        </div>

        <!-- Footer -->
        <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] pt-4">
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
            class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
            :disabled="saving"
          >
            <span v-if="saving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
            <span>{{ $t('common.save') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';

const props = defineProps({
  open: { type: Boolean, default: false },
  editingAccount: { type: Object, default: null },
  defaultAccountTypeId: { type: Number, default: null },
  accountClasses: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();

const isEdit = computed(() => Boolean(props.editingAccount));
const saving = ref(false);
const errors = ref({});

const form = reactive({
  account_type_id: '',
  code: '',
  name: '',
  normal_balance_override: '',
  reconcile: false,
  is_active: true,
});

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      return;
    }

    errors.value = {};
    if (props.editingAccount) {
      form.account_type_id = props.editingAccount.account_type_id ?? '';
      form.code = props.editingAccount.code ?? '';
      form.name = props.editingAccount.name ?? '';
      form.normal_balance_override = props.editingAccount.normal_balance_override ?? '';
      form.reconcile = Boolean(props.editingAccount.reconcile);
      form.is_active = Boolean(props.editingAccount.is_active);
    } else {
      form.account_type_id = props.defaultAccountTypeId ?? '';
      form.code = '';
      form.name = '';
      form.normal_balance_override = '';
      form.reconcile = false;
      form.is_active = true;
    }
  }
);

async function submit() {
  saving.value = true;
  errors.value = {};

  const payload = {
    account_type_id: Number(form.account_type_id),
    code: form.code,
    name: form.name,
    normal_balance_override: form.normal_balance_override || null,
    reconcile: Boolean(form.reconcile),
    is_active: Boolean(form.is_active),
  };

  try {
    const res = isEdit.value
      ? await api.put(`/accounting/accounts/${props.editingAccount.id}`, payload)
      : await api.post('/accounting/accounts', payload);

    showSuccess({
      title: t('common.successTitle'),
      text: isEdit.value ? t('chartOfAccounts.updatedSuccess') : t('chartOfAccounts.createdSuccess'),
    });

    emit('saved', res.data.data);
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
</script>
