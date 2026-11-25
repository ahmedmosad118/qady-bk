<template>
  <AppModal
    :open="open"
    :title="$t('purchaseInvoices.payModalTitle')"
    :subtitle="modalSubtitle"
    width-class="max-w-xl sm:max-w-2xl"
    @close="emit('close')"
  >
    <div v-if="loadingOutstanding" class="flex items-center justify-center py-12">
      <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
        <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
        <span>{{ $t('purchaseInvoices.loadingOutstanding') }}</span>
      </div>
    </div>

    <div
      v-else-if="loadError"
      class="flex items-center gap-2 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-xs font-bold text-[var(--crimson)]"
      role="alert"
    >
      <AlertCircle class="h-4 w-4 shrink-0" />
      <span>{{ loadError }}</span>
    </div>

    <div
      v-else-if="outstandingCents <= 0"
      class="flex items-center gap-2 rounded-xl border border-[var(--gold)]/35 bg-[var(--gold-soft)] px-4 py-3 text-xs font-bold text-[var(--gold-strong)]"
      role="status"
    >
      <Info class="h-4 w-4 shrink-0" />
      <span>{{ $t('purchaseInvoices.noOutstandingBalance') }}</span>
    </div>

    <form v-else id="pay-purchase-invoice-form" class="space-y-5" @submit.prevent="submitPayment">
      <!-- Top Error Banner -->
      <div
        v-if="submitError"
        class="flex items-center gap-2 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-xs font-bold text-[var(--crimson)]"
        role="alert"
      >
        <AlertCircle class="h-4 w-4 shrink-0" />
        <span>{{ submitError }}</span>
      </div>

      <!-- Remaining Amount Preview Card -->
      <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 space-y-2.5 text-xs">
        <div class="flex items-center justify-between gap-3">
          <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.invoiceTotal') }}</span>
          <span class="font-mono font-bold text-[var(--text)]">
            {{ formatMoney(summary.total) }} {{ $t('common.currency') }}
          </span>
        </div>
        <div class="flex items-center justify-between gap-3">
          <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.previouslyPaid') }}</span>
          <span class="font-mono font-bold text-[var(--text)]">
            {{ formatMoney(summary.paid_amount) }} {{ $t('common.currency') }}
          </span>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-[var(--border)] pt-2">
          <span class="font-bold text-[var(--text)]">{{ $t('purchaseInvoices.outstandingAmount') }}</span>
          <span class="font-mono text-sm font-black text-[var(--text)]">
            {{ formatMoney(summary.outstanding_amount) }} {{ $t('common.currency') }}
          </span>
        </div>
        <div class="flex items-center justify-between gap-3">
          <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.currentPayment') }}</span>
          <span class="font-mono font-bold text-[var(--crimson)]">
            {{ formatMoney(currentPaymentNumber) }} {{ $t('common.currency') }}
          </span>
        </div>
        <div class="flex items-center justify-between gap-3 border-t border-[var(--border)] pt-2">
          <span class="font-bold text-[var(--text)]">{{ $t('purchaseInvoices.remainingAmount') }}</span>
          <span
            class="font-mono text-sm font-black"
            :class="remainingCents < 0 ? 'text-[var(--crimson)]' : 'text-[var(--success)]'"
          >
            {{ formatMoney(remainingNumber) }} {{ $t('common.currency') }}
          </span>
        </div>
      </div>

      <!-- 3 User-Selectable Fields Only: Journal, Payment Method, Amount -->
      <div class="space-y-4">
        <!-- 1. Journal (Cash/Bank active only) -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.journal') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <SearchableSelect
            v-model="form.journal_id"
            :fetch-options="fetchJournalOptions"
            :paginate="15"
            :placeholder="$t('supplierPayments.selectJournal')"
            :search-placeholder="$t('supplierPayments.searchJournals')"
          />
          <p v-if="errors.journal_id" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.journal_id[0] }}
          </p>
        </div>

        <!-- 2. Payment Method (Active bank_cash-linked methods) -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.paymentMethod') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <SearchableSelect
            v-model="form.payment_method_id"
            :fetch-options="fetchPaymentMethodOptions"
            :placeholder="$t('supplierPayments.selectPaymentMethod')"
            :search-placeholder="$t('supplierPayments.searchPaymentMethods')"
          />
          <p v-if="errors.payment_method_id" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.payment_method_id[0] }}
          </p>
        </div>

        <!-- 3. Amount -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.amount') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="form.amount"
            type="number"
            step="0.01"
            min="0.01"
            :max="summary.outstanding_amount"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 font-mono text-sm font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          />
          <p v-if="amountErrorMessage" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ amountErrorMessage }}
          </p>
        </div>
      </div>

      <!-- Draft Lifecycle Info -->
      <div class="flex items-start gap-2 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 py-2.5 text-xs text-[var(--muted)]">
        <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
        <span>{{ $t('purchaseInvoices.draftPaymentNotice') }}</span>
      </div>
    </form>

    <template #footer>
      <div class="flex items-center justify-end gap-2.5">
        <button
          type="button"
          class="inline-flex h-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] hover:bg-[var(--bg)] cursor-pointer"
          @click="emit('close')"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          v-if="!loadingOutstanding && !loadError && outstandingCents > 0"
          type="submit"
          form="pay-purchase-invoice-form"
          :disabled="saving || Boolean(liveAmountValidationError)"
          class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-xs transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
        >
          <span
            v-if="saving"
            class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
          ></span>
          <span>{{ $t('common.save') }}</span>
        </button>
      </div>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, Info } from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import AppModal from '../../components/ui/AppModal.vue';
import api from '../../services/api';
import { createPayment, fetchOutstandingSupplierInvoices } from '../../services/paymentsApi';
import { useNotificationStore } from '../../stores/notification';
import { formatMoney } from '../../utils/format';
import { createPaginatedFetcher } from '../../utils/selectFetchers';

const props = defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const notificationStore = useNotificationStore();

const loadingOutstanding = ref(false);
const loadError = ref('');
const saving = ref(false);
const submitError = ref('');
const errors = ref({});

const summary = ref({
  total: '0.00',
  paid_amount: '0.00',
  outstanding_amount: '0.00',
});

const form = ref({
  journal_id: null,
  payment_method_id: null,
  amount: '',
});

const modalSubtitle = computed(() => {
  if (!props.invoice) return t('purchaseInvoices.payModalSubtitle');
  const invNo = props.invoice.invoice_number || '';
  const supName = props.invoice.supplier?.name || '';
  return supName ? `${invNo} — ${supName}` : invNo;
});

const toCents = (val) => Math.round((Number(val) || 0) * 100);
const outstandingCents = computed(() => toCents(summary.value.outstanding_amount));
const currentPaymentCents = computed(() => toCents(form.value.amount));
const currentPaymentNumber = computed(() => currentPaymentCents.value / 100);
const remainingCents = computed(() => outstandingCents.value - currentPaymentCents.value);
const remainingNumber = computed(() => remainingCents.value / 100);

const liveAmountValidationError = computed(() => {
  if (form.value.amount === '' || form.value.amount === null || currentPaymentCents.value <= 0) {
    return t('purchaseInvoices.invalidPaymentAmount');
  }
  if (currentPaymentCents.value > outstandingCents.value) {
    return t('purchaseInvoices.paymentExceedsOutstanding');
  }
  return '';
});

const amountErrorMessage = computed(() => {
  if (errors.value.amount?.[0]) return errors.value.amount[0];
  if (errors.value['allocations.0.allocated_amount']?.[0]) {
    return errors.value['allocations.0.allocated_amount'][0];
  }
  return liveAmountValidationError.value;
});

const fetchJournalOptions = createPaginatedFetcher(
  '/accounting/journals',
  () => ({ is_active: 1, type: 'cash,bank' }),
  (j) => ({
    value: j.id,
    label: j.code ? `${j.code} — ${j.name}` : j.name,
    raw: j,
  })
);

const fetchPaymentMethodOptions = async ({ search = '' } = {}) => {
  try {
    const res = await api.get('/payment-methods');
    const rows = Array.isArray(res.data?.data) ? res.data.data : [];
    const active = rows.filter((pm) => pm.is_active !== false && pm.is_active !== 0 && pm.account_id);
    const query = search.trim().toLowerCase();
    const filtered = query
      ? active.filter((pm) => {
          const name = String(pm.name || '').toLowerCase();
          const acc = String(pm.account?.name || '').toLowerCase();
          return name.includes(query) || acc.includes(query);
        })
      : active;

    const options = filtered.map((pm) => ({
      value: pm.id,
      label: pm.account?.name ? `${pm.name} (${pm.account.name})` : pm.name,
      raw: pm,
    }));

    return {
      options,
      hasMore: false,
      total: options.length,
      currentPage: 1,
      lastPage: 1,
    };
  } catch (err) {
    submitError.value = err.response?.data?.message || t('common.genericError');
    return {
      options: [],
      hasMore: false,
      total: 0,
      currentPage: 1,
      lastPage: 1,
    };
  }
};

const todayStr = () => new Date().toISOString().slice(0, 10);

const initModal = async () => {
  loadError.value = '';
  submitError.value = '';
  errors.value = {};
  form.value = {
    journal_id: null,
    payment_method_id: null,
    amount: '',
  };
  summary.value = {
    total: '0.00',
    paid_amount: '0.00',
    outstanding_amount: '0.00',
  };

  if (!props.invoice?.id || !props.invoice?.supplier_id) {
    loadError.value = t('purchaseInvoices.outstandingLoadError');
    return;
  }

  loadingOutstanding.value = true;
  try {
    const res = await fetchOutstandingSupplierInvoices(props.invoice.supplier_id);
    const list = Array.isArray(res.data?.data) ? res.data.data : [];
    const match = list.find((inv) => Number(inv.id) === Number(props.invoice.id));

    if (!match) {
      summary.value = {
        total: props.invoice.total ?? '0.00',
        paid_amount: props.invoice.total ?? '0.00',
        outstanding_amount: '0.00',
      };
      return;
    }

    summary.value = {
      total: match.total,
      paid_amount: match.paid_amount,
      outstanding_amount: match.outstanding_amount,
    };
    form.value.amount = Number(match.outstanding_amount || 0).toFixed(2);
  } catch (err) {
    loadError.value = err.response?.data?.message || t('purchaseInvoices.outstandingLoadError');
  } finally {
    loadingOutstanding.value = false;
  }
};

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      initModal();
    }
  }
);

const submitPayment = async () => {
  if (saving.value) return;

  errors.value = {};
  submitError.value = '';

  if (!form.value.journal_id) {
    errors.value.journal_id = [t('supplierPayments.validation.journalRequired')];
    return;
  }

  if (!form.value.payment_method_id) {
    errors.value.payment_method_id = [t('supplierPayments.validation.paymentMethodRequired')];
    return;
  }

  if (liveAmountValidationError.value) {
    errors.value.amount = [liveAmountValidationError.value];
    return;
  }

  saving.value = true;
  const amountStr = Number(form.value.amount).toFixed(2);

  const payload = {
    payment_type: 'send',
    related_type: 'supplier',
    contact_id: props.invoice.supplier_id,
    account_id: null,
    payment_method_id: form.value.payment_method_id,
    journal_id: form.value.journal_id,
    payment_date: todayStr(),
    amount: amountStr,
    allocations: [
      {
        purchase_invoice_id: props.invoice.id,
        allocated_amount: amountStr,
      },
    ],
  };

  try {
    const res = await createPayment(payload);
    notificationStore.success(t('purchaseInvoices.paymentCreatedSuccess'));
    emit('saved', res.data?.data);
    emit('close');
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
    }
    submitError.value = err.response?.data?.message || t('common.genericError');
  } finally {
    saving.value = false;
  }
};
</script>
