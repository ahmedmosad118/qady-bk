<template>
  <AppDrawer
    :open="open"
    :title="isEdit ? $t('supplierPayments.editTitle') : $t('supplierPayments.create')"
    :subtitle="isEdit ? $t('supplierPayments.editSubtitle') : $t('supplierPayments.createSubtitle')"
    width-class="max-w-3xl lg:max-w-4xl"
    side="end"
    @close="emit('close')"
  >
    <div v-if="loadingDetails" class="flex items-center justify-center py-16">
      <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
        <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
        <span>{{ $t('common.loading') }}</span>
      </div>
    </div>

    <form v-else id="supplier-payment-form" class="space-y-5" @submit.prevent="submitForm">
      <!-- Top Error Banner -->
      <div
        v-if="submitError"
        class="flex items-center gap-2 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-xs font-bold text-[var(--crimson)]"
        role="alert"
      >
        <AlertCircle class="h-4 w-4 shrink-0" />
        <span>{{ submitError }}</span>
      </div>

      <!-- Core Payment Fields -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <!-- Supplier -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.supplier') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <select
            v-model="form.contact_id"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            @change="onSupplierChange"
          >
            <option :value="null">{{ $t('supplierPayments.selectSupplier') }}</option>
            <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
              {{ sup.name }}
            </option>
          </select>
          <p v-if="errors.contact_id" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.contact_id[0] }}
          </p>
        </div>

        <!-- Payment Date -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.paymentDate') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="form.payment_date"
            type="date"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          />
          <p v-if="errors.payment_date" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.payment_date[0] }}
          </p>
        </div>

        <!-- Payment Method -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.paymentMethod') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <select
            v-model="form.payment_method_id"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          >
            <option :value="null">{{ $t('supplierPayments.selectPaymentMethod') }}</option>
            <option v-for="pm in paymentMethods" :key="pm.id" :value="pm.id">
              {{ pm.name }}
            </option>
          </select>
          <p v-if="errors.payment_method_id" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.payment_method_id[0] }}
          </p>
        </div>

        <!-- Journal -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.journal') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <select
            v-model="form.journal_id"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          >
            <option :value="null">{{ $t('supplierPayments.selectJournal') }}</option>
            <option v-for="j in journals" :key="j.id" :value="j.id">
              {{ j.name }}
            </option>
          </select>
          <p v-if="errors.journal_id" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.journal_id[0] }}
          </p>
        </div>

        <!-- Amount -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.amount') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="form.amount"
            type="number"
            step="0.01"
            min="0.01"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 font-mono text-sm font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          />
          <p v-if="errors.amount" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.amount[0] }}
          </p>
        </div>

        <!-- Reference -->
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
            {{ $t('supplierPayments.reference') }}
          </label>
          <input
            v-model="form.reference"
            type="text"
            maxlength="100"
            :placeholder="$t('supplierPayments.referencePlaceholder')"
            class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
          />
          <p v-if="errors.reference" class="mt-1 text-xs text-[var(--crimson)]">
            {{ errors.reference[0] }}
          </p>
        </div>
      </div>

      <!-- Description -->
      <div>
        <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
          {{ $t('supplierPayments.description') }}
        </label>
        <textarea
          v-model="form.description"
          rows="2"
          maxlength="2000"
          :placeholder="$t('supplierPayments.descriptionPlaceholder')"
          class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
        />
        <p v-if="errors.description" class="mt-1 text-xs text-[var(--crimson)]">
          {{ errors.description[0] }}
        </p>
      </div>

      <!-- Invoice Allocations Section -->
      <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="text-sm font-extrabold text-[var(--text)]">
              {{ $t('supplierPayments.allocationsTitle') }}
            </h3>
            <p class="mt-0.5 text-xs text-[var(--muted)]">
              {{ $t('supplierPayments.allocationsSubtitle') }}
            </p>
          </div>
        </div>

        <!-- Supplier Advance Notice (when no allocations) -->
        <div
          v-if="form.allocations.length === 0"
          class="flex items-start gap-2.5 rounded-xl border border-[var(--gold)]/30 bg-[var(--gold-soft)] px-3.5 py-3 text-xs text-[var(--text)]"
        >
          <Info class="mt-0.5 h-4 w-4 shrink-0 text-[var(--gold-strong)]" />
          <div>
            <p class="font-bold text-[var(--gold-strong)]">{{ $t('supplierPayments.advanceBannerTitle') }}</p>
            <p class="mt-0.5 text-[var(--muted)]">{{ $t('supplierPayments.advanceBannerDesc') }}</p>
          </div>
        </div>

        <!-- Outstanding Invoices Loader / Error / Selector -->
        <div v-if="!form.contact_id" class="text-xs text-[var(--muted)]">
          {{ $t('supplierPayments.selectSupplierFirst') }}
        </div>

        <div v-else-if="loadingInvoices" class="inline-flex items-center gap-2 text-xs text-[var(--muted)]">
          <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('supplierPayments.loadingInvoices') }}</span>
        </div>

        <div
          v-else-if="invoicesError"
          class="rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-3 py-2 text-xs font-semibold text-[var(--crimson)]"
        >
          {{ invoicesError }}
        </div>

        <template v-else>
          <!-- Add Invoice Dropdown -->
          <div v-if="availableInvoices.length > 0" class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <select
              v-model="selectedInvoiceIdToAdd"
              class="h-10 flex-1 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
            >
              <option :value="null">{{ $t('supplierPayments.selectInvoice') }}</option>
              <option v-for="inv in availableInvoices" :key="inv.id" :value="inv.id">
                {{ inv.invoice_number }} — {{ formatDateOnly(inv.invoice_date) }} ({{ formatMoney(inv.outstanding_amount) }} {{ $t('common.currency') }})
              </option>
            </select>
            <button
              type="button"
              :disabled="!selectedInvoiceIdToAdd"
              class="inline-flex h-10 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-xs font-bold text-[var(--text)] transition-colors enabled:hover:border-[var(--crimson)] enabled:hover:text-[var(--crimson)] disabled:opacity-45 cursor-pointer"
              @click="addInvoiceAllocation"
            >
              <Plus class="h-4 w-4" />
              <span>{{ $t('supplierPayments.addInvoice') }}</span>
            </button>
          </div>

          <p v-else-if="form.allocations.length === 0" class="text-xs text-[var(--muted)]">
            {{ $t('supplierPayments.noOutstandingInvoices') }}
          </p>

          <!-- Selected Allocations Table -->
          <div v-if="form.allocations.length > 0" class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)]">
            <table class="w-full text-start text-xs">
              <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
                <tr>
                  <th class="px-3 py-2.5 text-start">{{ $t('supplierPayments.invoiceNumber') }}</th>
                  <th class="px-3 py-2.5 text-center">{{ $t('supplierPayments.invoiceDate') }}</th>
                  <th class="px-3 py-2.5 text-center">{{ $t('supplierPayments.invoiceTotal') }}</th>
                  <th class="px-3 py-2.5 text-center">{{ $t('supplierPayments.paidAmount') }}</th>
                  <th class="px-3 py-2.5 text-center">{{ $t('supplierPayments.outstandingAmount') }}</th>
                  <th class="px-3 py-2.5 text-center">{{ $t('supplierPayments.allocatedAmount') }}</th>
                  <th class="px-3 py-2.5 text-end">{{ $t('common.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)]">
                <tr v-for="(row, idx) in form.allocations" :key="row.purchase_invoice_id">
                  <td class="px-3 py-2.5 font-mono font-bold text-[var(--text)] whitespace-nowrap">
                    {{ row.invoice_number }}
                  </td>
                  <td class="px-3 py-2.5 text-center font-mono text-[var(--muted)] whitespace-nowrap">
                    {{ formatDateOnly(row.invoice_date) }}
                  </td>
                  <td class="px-3 py-2.5 text-center font-mono text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(row.total) }}
                  </td>
                  <td class="px-3 py-2.5 text-center font-mono text-[var(--muted)] whitespace-nowrap">
                    {{ formatMoney(row.paid_amount) }}
                  </td>
                  <td class="px-3 py-2.5 text-center font-mono font-bold text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(row.outstanding_amount) }}
                  </td>
                  <td class="px-3 py-2.5 text-center">
                    <div class="inline-flex items-center gap-1.5">
                      <input
                        v-model="row.allocated_amount"
                        type="number"
                        step="0.01"
                        min="0.01"
                        :max="row.outstanding_amount"
                        class="h-9 w-28 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2 text-center font-mono text-xs font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                      />
                      <button
                        type="button"
                        class="rounded-lg border border-[var(--border)] px-2 py-1 text-[11px] font-semibold text-[var(--muted)] hover:border-[var(--crimson)] hover:text-[var(--crimson)] cursor-pointer"
                        :title="$t('supplierPayments.fillRemaining')"
                        @click="fillRowRemaining(row)"
                      >
                        {{ $t('supplierPayments.fillRemaining') }}
                      </button>
                    </div>
                    <p
                      v-if="errors[`allocations.${idx}.allocated_amount`]"
                      class="mt-1 text-[11px] text-[var(--crimson)]"
                    >
                      {{ errors[`allocations.${idx}.allocated_amount`][0] }}
                    </p>
                  </td>
                  <td class="px-3 py-2.5 text-end">
                    <button
                      type="button"
                      class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] cursor-pointer"
                      :title="$t('supplierPayments.removeAllocation')"
                      @click="removeAllocation(idx)"
                    >
                      <Trash2 class="h-4 w-4" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-if="errors.allocations" class="text-xs font-semibold text-[var(--crimson)]">
            {{ errors.allocations[0] }}
          </p>

          <!-- Allocation Summary -->
          <div
            v-if="form.allocations.length > 0"
            class="grid grid-cols-1 gap-3 rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 sm:grid-cols-3"
          >
            <div>
              <span class="block text-[11px] font-semibold text-[var(--muted)]">{{ $t('supplierPayments.amount') }}</span>
              <span class="font-mono text-sm font-black text-[var(--text)]">
                {{ formatMoney(paymentAmountNumber) }} {{ $t('common.currency') }}
              </span>
            </div>
            <div>
              <span class="block text-[11px] font-semibold text-[var(--muted)]">{{ $t('supplierPayments.allocatedTotal') }}</span>
              <span class="font-mono text-sm font-black text-[var(--text)]">
                {{ formatMoney(allocatedTotalNumber) }} {{ $t('common.currency') }}
              </span>
            </div>
            <div>
              <span class="block text-[11px] font-semibold text-[var(--muted)]">{{ $t('supplierPayments.allocationDifference') }}</span>
              <span
                class="font-mono text-sm font-black"
                :class="allocationDiffCents === 0 ? 'text-[var(--success)]' : 'text-[var(--crimson)]'"
              >
                {{ formatMoney(allocationDiffCents / 100) }} {{ $t('common.currency') }}
              </span>
            </div>
          </div>
        </template>
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
          type="submit"
          form="supplier-payment-form"
          :disabled="saving || loadingDetails"
          class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-xs transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
        >
          <span
            v-if="saving"
            class="h-4 w-4 animate-spin rounded-full border-2 border-white border-t-transparent"
          ></span>
          <span>{{ isEdit ? $t('supplierPayments.saveChanges') : $t('supplierPayments.saveDraft') }}</span>
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, Info, Plus, Trash2 } from 'lucide-vue-next';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import {
  createSupplierPayment,
  fetchOutstandingSupplierInvoices,
  fetchSupplierPayment,
  updateSupplierPayment,
} from '../../services/supplierPaymentsApi';
import { useNotificationStore } from '../../stores/notification';
import { formatDateOnly } from '../../utils/date';

const props = defineProps({
  open: { type: Boolean, default: false },
  payment: { type: Object, default: null },
  suppliers: { type: Array, default: () => [] },
  paymentMethods: { type: Array, default: () => [] },
  journals: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const { t, locale } = useI18n();
const notificationStore = useNotificationStore();

const isEdit = computed(() => Boolean(props.payment?.id));
const loadingDetails = ref(false);
const loadingInvoices = ref(false);
const invoicesError = ref('');
const saving = ref(false);
const submitError = ref('');
const errors = ref({});

const outstandingInvoices = ref([]);
const selectedInvoiceIdToAdd = ref(null);

const todayStr = () => new Date().toISOString().slice(0, 10);

const form = ref({
  contact_id: null,
  payment_method_id: null,
  journal_id: null,
  payment_date: todayStr(),
  amount: '',
  reference: '',
  description: '',
  allocations: [],
});

const toCents = (val) => Math.round((Number(val) || 0) * 100);
const paymentAmountNumber = computed(() => Number(form.value.amount) || 0);
const allocatedTotalCents = computed(() =>
  form.value.allocations.reduce((sum, row) => sum + toCents(row.allocated_amount), 0)
);
const allocatedTotalNumber = computed(() => allocatedTotalCents.value / 100);
const allocationDiffCents = computed(() => toCents(form.value.amount) - allocatedTotalCents.value);

const availableInvoices = computed(() => {
  const selectedIds = new Set(form.value.allocations.map((r) => Number(r.purchase_invoice_id)));
  return outstandingInvoices.value.filter((inv) => !selectedIds.has(Number(inv.id)));
});

const formatMoney = (val) => {
  const loc = locale.value === 'ar' ? 'ar-EG' : 'en-US';
  return Number(val || 0).toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const resetForm = () => {
  form.value = {
    contact_id: null,
    payment_method_id: props.paymentMethods[0]?.id ?? null,
    journal_id: props.journals[0]?.id ?? null,
    payment_date: todayStr(),
    amount: '',
    reference: '',
    description: '',
    allocations: [],
  };
  outstandingInvoices.value = [];
  selectedInvoiceIdToAdd.value = null;
  submitError.value = '';
  invoicesError.value = '';
  errors.value = {};
};

const loadOutstandingInvoices = async (supplierId) => {
  if (!supplierId) {
    outstandingInvoices.value = [];
    return;
  }
  loadingInvoices.value = true;
  invoicesError.value = '';
  try {
    const res = await fetchOutstandingSupplierInvoices(supplierId);
    outstandingInvoices.value = res.data?.data ?? [];
  } catch (err) {
    invoicesError.value = err.response?.data?.message || t('supplierPayments.loadInvoicesError');
    outstandingInvoices.value = [];
  } finally {
    loadingInvoices.value = false;
  }
};

const initModal = async () => {
  resetForm();
  if (!props.payment?.id) {
    return;
  }

  loadingDetails.value = true;
  try {
    const res = await fetchSupplierPayment(props.payment.id);
    const data = res.data?.data;
    if (!data) return;

    await loadOutstandingInvoices(data.contact_id);
    const outstandingMap = new Map(outstandingInvoices.value.map((inv) => [Number(inv.id), inv]));

    form.value = {
      contact_id: data.contact_id,
      payment_method_id: data.payment_method_id,
      journal_id: data.journal_id,
      payment_date: data.payment_date || todayStr(),
      amount: data.amount,
      reference: data.reference || '',
      description: data.description || '',
      allocations: (data.allocations || []).map((alloc) => {
        const inv = outstandingMap.get(Number(alloc.allocatable_id));
        const pi = alloc.purchase_invoice;
        return {
          purchase_invoice_id: Number(alloc.allocatable_id),
          invoice_number: inv?.invoice_number || pi?.invoice_number || `#${alloc.allocatable_id}`,
          invoice_date: inv?.invoice_date || pi?.invoice_date || null,
          total: inv?.total ?? pi?.total ?? alloc.allocated_amount,
          paid_amount: inv?.paid_amount ?? '0.00',
          outstanding_amount: inv?.outstanding_amount ?? alloc.allocated_amount,
          allocated_amount: alloc.allocated_amount,
        };
      }),
    };
  } catch (err) {
    submitError.value = err.response?.data?.message || t('supplierPayments.loadOneError');
  } finally {
    loadingDetails.value = false;
  }
};

watch(
  () => props.open,
  (val) => {
    if (val) {
      initModal();
    }
  }
);

const onSupplierChange = async () => {
  form.value.allocations = [];
  selectedInvoiceIdToAdd.value = null;
  await loadOutstandingInvoices(form.value.contact_id);
};

const addInvoiceAllocation = () => {
  if (!selectedInvoiceIdToAdd.value) return;
  const inv = outstandingInvoices.value.find((i) => Number(i.id) === Number(selectedInvoiceIdToAdd.value));
  if (!inv) return;

  const remainingToAllocateCents = Math.max(0, allocationDiffCents.value);
  const maxInvoiceCents = toCents(inv.outstanding_amount);
  const suggestedCents = remainingToAllocateCents > 0
    ? Math.min(remainingToAllocateCents, maxInvoiceCents)
    : maxInvoiceCents;

  form.value.allocations.push({
    purchase_invoice_id: inv.id,
    invoice_number: inv.invoice_number,
    invoice_date: inv.invoice_date,
    total: inv.total,
    paid_amount: inv.paid_amount,
    outstanding_amount: inv.outstanding_amount,
    allocated_amount: (suggestedCents / 100).toFixed(2),
  });

  if (!form.value.amount || Number(form.value.amount) <= 0) {
    form.value.amount = (allocatedTotalCents.value / 100).toFixed(2);
  }

  selectedInvoiceIdToAdd.value = null;
};

const fillRowRemaining = (row) => {
  const otherAllocatedCents = allocatedTotalCents.value - toCents(row.allocated_amount);
  const paymentRemainingCents = Math.max(0, toCents(form.value.amount) - otherAllocatedCents);
  const maxCents = toCents(row.outstanding_amount);
  const nextCents = paymentRemainingCents > 0 ? Math.min(paymentRemainingCents, maxCents) : maxCents;
  row.allocated_amount = (nextCents / 100).toFixed(2);
};

const removeAllocation = (idx) => {
  form.value.allocations.splice(idx, 1);
};

const validateClientSide = () => {
  errors.value = {};
  submitError.value = '';

  if (toCents(form.value.amount) <= 0) {
    errors.value.amount = [t('supplierPayments.validation.amountPositive')];
    return false;
  }

  if (form.value.allocations.length > 0) {
    let hasRowError = false;
    form.value.allocations.forEach((row, idx) => {
      const allocCents = toCents(row.allocated_amount);
      if (allocCents <= 0) {
        errors.value[`allocations.${idx}.allocated_amount`] = [t('supplierPayments.validation.allocatedPositive')];
        hasRowError = true;
      } else if (allocCents > toCents(row.outstanding_amount)) {
        errors.value[`allocations.${idx}.allocated_amount`] = [t('supplierPayments.validation.exceedsOutstanding')];
        hasRowError = true;
      }
    });

    if (hasRowError) return false;

    if (allocationDiffCents.value !== 0) {
      errors.value.allocations = [t('supplierPayments.validation.totalMismatch')];
      return false;
    }
  }

  return true;
};

const submitForm = async () => {
  if (saving.value) return;
  if (!validateClientSide()) return;

  saving.value = true;
  submitError.value = '';
  errors.value = {};

  const payload = {
    contact_id: form.value.contact_id,
    payment_method_id: form.value.payment_method_id,
    journal_id: form.value.journal_id,
    payment_date: form.value.payment_date,
    amount: Number(form.value.amount).toFixed(2),
    reference: form.value.reference?.trim() || null,
    description: form.value.description?.trim() || null,
    allocations: form.value.allocations.map((row) => ({
      purchase_invoice_id: row.purchase_invoice_id,
      allocated_amount: Number(row.allocated_amount).toFixed(2),
    })),
  };

  try {
    const res = isEdit.value
      ? await updateSupplierPayment(props.payment.id, payload)
      : await createSupplierPayment(payload);

    notificationStore.success(
      isEdit.value ? t('supplierPayments.updatedSuccess') : t('supplierPayments.createdSuccess')
    );
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
