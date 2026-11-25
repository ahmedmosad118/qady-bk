<template>
  <AppModal
    :open="open"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl"
    @close="emit('close')"
  >
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)]">
            <Banknote class="h-6 w-6" />
          </div>
          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="font-mono text-lg font-black text-[var(--text)] sm:text-xl">
                {{ detail?.payment_number || '—' }}
              </h2>
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                :class="statusBadgeClass(detail?.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel(detail?.status) }}
              </span>
              <span
                v-if="detail?.payment_type"
                class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--text)]"
              >
                {{ $t(`supplierPayments.paymentTypes.${detail.payment_type}`) }}
              </span>
              <span
                v-if="isSupplierAdvance"
                class="inline-flex items-center rounded-full border border-[var(--gold)]/35 bg-[var(--gold-soft)] px-2.5 py-0.5 text-xs font-bold text-[var(--gold-strong)]"
              >
                {{ $t('supplierPayments.advanceBadge') }}
              </span>
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <template v-if="partyName">
                <span>{{ partyLabel }}:</span>
                <span class="font-bold text-[var(--text)]">{{ partyName }}</span>
                <span>•</span>
              </template>
              <span class="font-mono">{{ formatDateOnly(detail?.payment_date) }}</span>
            </div>
          </div>
        </div>

        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
          :title="$t('common.close')"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>
    </template>

    <template #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span
            v-if="detail?.status === 'posted'"
            class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--success-soft)] px-2.5 py-1 text-xs font-bold text-[var(--success)]"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('supplierPayments.postedNotice') }}</span>
          </span>
          <span
            v-else-if="detail?.status === 'cancelled'"
            class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--crimson-soft)] px-2.5 py-1 text-xs font-bold text-[var(--crimson)]"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('supplierPayments.cancelledNotice') }}</span>
          </span>
        </div>

        <div v-if="!readOnly && detail?.status === 'draft'" class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--success)] px-3.5 py-1.5 text-xs font-bold text-white transition-opacity hover:opacity-90 disabled:opacity-50 cursor-pointer"
            :disabled="actionLoading"
            @click="emit('post', detail)"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('supplierPayments.post') }}</span>
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] hover:bg-[var(--bg)] disabled:opacity-50 cursor-pointer"
            :disabled="actionLoading"
            @click="emit('edit', detail)"
          >
            <Pencil class="h-3.5 w-3.5" />
            <span>{{ $t('supplierPayments.edit') }}</span>
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--crimson)] hover:bg-[var(--crimson-soft)] disabled:opacity-50 cursor-pointer"
            :disabled="actionLoading"
            @click="emit('cancel-payment', detail)"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('supplierPayments.cancelPayment') }}</span>
          </button>
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--crimson)] px-3.5 py-1.5 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
            :disabled="actionLoading"
            @click="emit('delete', detail)"
          >
            <Trash2 class="h-3.5 w-3.5" />
            <span>{{ $t('supplierPayments.delete') }}</span>
          </button>
        </div>
      </div>
    </template>

    <div v-if="loading" class="flex items-center justify-center py-16">
      <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
        <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
        <span>{{ $t('common.loading') }}</span>
      </div>
    </div>

    <div v-else-if="loadError" class="rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] p-4 text-sm font-semibold text-[var(--crimson)]">
      {{ loadError }}
    </div>

    <div v-else-if="detail" class="space-y-5">
      <!-- Summary Grid -->
      <div class="grid grid-cols-1 gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <span class="block text-xs text-[var(--muted)]">{{ $t('supplierPayments.amount') }}</span>
          <span class="font-mono text-base font-black text-[var(--text)]">
            {{ formatMoney(detail.amount) }} {{ $t('common.currency') }}
          </span>
        </div>
        <div>
          <span class="block text-xs text-[var(--muted)]">{{ $t('supplierPayments.paymentMethod') }}</span>
          <span class="text-sm font-bold text-[var(--text)]">{{ detail.payment_method?.name || '—' }}</span>
        </div>
        <div>
          <span class="block text-xs text-[var(--muted)]">{{ $t('supplierPayments.journal') }}</span>
          <span class="text-sm font-bold text-[var(--text)]">{{ detail.journal?.name || '—' }}</span>
        </div>
        <div v-if="detail.payment_type === 'transfer' && detail.payment_method?.account">
          <span class="block text-xs text-[var(--muted)]">{{ $t('supplierPayments.sourceAccount') }}</span>
          <span class="text-sm font-bold text-[var(--text)]">
            {{ detail.payment_method.account.code ? `${detail.payment_method.account.code} — ${detail.payment_method.account.name}` : detail.payment_method.account.name }}
          </span>
        </div>
        <div v-if="detail.account">
          <span class="block text-xs text-[var(--muted)]">{{ detail.payment_type === 'transfer' ? $t('supplierPayments.destinationAccount') : $t('supplierPayments.account') }}</span>
          <span class="text-sm font-bold text-[var(--text)]">
            {{ detail.account.code ? `${detail.account.code} — ${detail.account.name}` : detail.account.name }}
          </span>
        </div>
        <div>
          <span class="block text-xs text-[var(--muted)]">{{ $t('supplierPayments.reference') }}</span>
          <span class="font-mono text-sm font-semibold text-[var(--text)]">{{ detail.reference || '—' }}</span>
        </div>
        <div class="sm:col-span-2">
          <span class="block text-xs text-[var(--muted)]">{{ $t('supplierPayments.description') }}</span>
          <span class="text-sm text-[var(--text)]">{{ detail.description || '—' }}</span>
        </div>
      </div>

      <!-- Allocations or Supplier Advance Notice -->
      <div v-if="isSupplierAdvance" class="flex items-start gap-2.5 rounded-2xl border border-[var(--gold)]/30 bg-[var(--gold-soft)] p-4 text-xs">
        <Info class="mt-0.5 h-4 w-4 shrink-0 text-[var(--gold-strong)]" />
        <div>
          <p class="font-bold text-[var(--gold-strong)]">{{ $t('supplierPayments.advanceBadge') }}</p>
          <p class="mt-1 text-[var(--text)]">{{ $t('supplierPayments.noAllocationsNotice') }}</p>
        </div>
      </div>

      <div v-else-if="(detail.allocations?.length ?? 0) > 0" class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
        <div class="border-b border-[var(--border)] bg-[var(--bg)] px-4 py-3 text-xs font-extrabold text-[var(--text)]">
          {{ $t('supplierPayments.allocationsTitle') }}
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)]/50 font-bold text-[var(--muted)]">
              <tr>
                <th class="px-4 py-2.5 text-start">{{ $t('supplierPayments.invoiceNumber') }}</th>
                <th class="px-4 py-2.5 text-center">{{ $t('supplierPayments.invoiceDate') }}</th>
                <th class="px-4 py-2.5 text-center">{{ $t('supplierPayments.invoiceTotal') }}</th>
                <th class="px-4 py-2.5 text-end">{{ $t('supplierPayments.allocatedAmount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-for="alloc in detail.allocations" :key="alloc.id">
                <td class="px-4 py-2.5 font-mono font-bold text-[var(--text)]">
                  {{ alloc.purchase_invoice?.invoice_number || `#${alloc.allocatable_id}` }}
                </td>
                <td class="px-4 py-2.5 text-center font-mono text-[var(--muted)]">
                  {{ formatDateOnly(alloc.purchase_invoice?.invoice_date) }}
                </td>
                <td class="px-4 py-2.5 text-center font-mono text-[var(--text)]">
                  {{ formatMoney(alloc.purchase_invoice?.total) }} {{ $t('common.currency') }}
                </td>
                <td class="px-4 py-2.5 text-end font-mono font-black text-[var(--text)]">
                  {{ formatMoney(alloc.allocated_amount) }} {{ $t('common.currency') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Linked Journal Entry Summary -->
      <div v-if="detail.journal_entry" class="rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4">
        <h3 class="text-xs font-extrabold text-[var(--text)] mb-3">
          {{ $t('supplierPayments.journalEntrySummary') }}
        </h3>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-xs">
          <div>
            <span class="block text-[var(--muted)]">{{ $t('supplierPayments.entryNumber') }}</span>
            <span class="font-mono font-bold text-[var(--text)]">{{ detail.journal_entry.entry_number }}</span>
          </div>
          <div>
            <span class="block text-[var(--muted)]">{{ $t('supplierPayments.entryStatus') }}</span>
            <span class="font-bold text-[var(--text)]">{{ $t(`journalEntries.statuses.${detail.journal_entry.status}`) }}</span>
          </div>
          <div>
            <span class="block text-[var(--muted)]">{{ $t('supplierPayments.paymentDate') }}</span>
            <span class="font-mono font-bold text-[var(--text)]">{{ formatDateOnly(detail.journal_entry.entry_date) }}</span>
          </div>
        </div>
        <div v-if="(detail.journal_entry.lines?.length ?? 0) > 0" class="mt-3 overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)]">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)]/60 font-bold text-[var(--muted)]">
              <tr>
                <th class="px-3 py-2 text-start">{{ $t('supplierPayments.account') }}</th>
                <th class="px-3 py-2 text-end">{{ $t('journalEntries.debit') }}</th>
                <th class="px-3 py-2 text-end">{{ $t('journalEntries.credit') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-for="line in detail.journal_entry.lines" :key="line.id">
                <td class="px-3 py-2 font-semibold text-[var(--text)]">
                  {{ line.account?.code ? `${line.account.code} — ${line.account.name}` : (line.account?.name || '—') }}
                </td>
                <td class="px-3 py-2 text-end font-mono font-bold text-[var(--text)]">
                  {{ formatMoney(line.debit) }}
                </td>
                <td class="px-3 py-2 text-end font-mono font-bold text-[var(--text)]">
                  {{ formatMoney(line.credit) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Audit Trail -->
      <div class="grid grid-cols-1 gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 text-xs sm:grid-cols-2">
        <div>
          <span class="block text-[var(--muted)]">{{ $t('supplierPayments.createdBy') }}</span>
          <span class="font-bold text-[var(--text)]">{{ detail.creator?.name || '—' }}</span>
          <span class="ms-2 font-mono text-[var(--muted)]">{{ formatDateTime(detail.created_at) }}</span>
        </div>
        <div v-if="detail.status === 'posted'">
          <span class="block text-[var(--muted)]">{{ $t('supplierPayments.postedBy') }}</span>
          <span class="font-bold text-[var(--text)]">{{ detail.posted_by?.name || '—' }}</span>
          <span class="ms-2 font-mono text-[var(--muted)]">{{ formatDateTime(detail.posted_at) }}</span>
        </div>
        <div v-else-if="detail.status === 'cancelled'">
          <span class="block text-[var(--muted)]">{{ $t('supplierPayments.cancelledBy') }}</span>
          <span class="font-bold text-[var(--text)]">{{ detail.cancelled_by?.name || '—' }}</span>
          <span class="ms-2 font-mono text-[var(--muted)]">{{ formatDateTime(detail.cancelled_at) }}</span>
        </div>
      </div>
    </div>
  </AppModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Banknote, CheckCircle2, Info, Pencil, Trash2, X, XCircle } from 'lucide-vue-next';
import AppModal from '../../components/ui/AppModal.vue';
import { fetchPayment } from '../../services/paymentsApi';
import { formatDateOnly, formatDateTime } from '../../utils/date';

const props = defineProps({
  open: { type: Boolean, default: false },
  payment: { type: Object, default: null },
  actionLoading: { type: Boolean, default: false },
  readOnly: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'edit', 'post', 'cancel-payment', 'delete']);

const { t, locale } = useI18n();
const loading = ref(false);
const loadError = ref('');
const detail = ref(null);

const isSupplierAdvance = computed(
  () => detail.value?.payment_type === 'send'
    && detail.value?.related_type === 'supplier'
    && (detail.value?.allocations?.length ?? 0) === 0
);

const partyName = computed(() =>
  detail.value?.supplier?.name || detail.value?.customer?.name || detail.value?.contact?.name || ''
);

const partyLabel = computed(() =>
  detail.value?.related_type ? t(`supplierPayments.relatedTypes.${detail.value.related_type}`) : t('supplierPayments.relatedParty')
);

const formatMoney = (val) => {
  const loc = locale.value === 'ar' ? 'ar-EG' : 'en-US';
  return Number(val || 0).toLocaleString(loc, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const statusLabel = (status) => (status ? t(`supplierPayments.statuses.${status}`) : '—');

const statusBadgeClass = (status) => {
  if (status === 'posted') return 'bg-[var(--success-soft)] text-[var(--success)]';
  if (status === 'cancelled') return 'bg-[var(--crimson-soft)] text-[var(--crimson)]';
  return 'bg-[var(--gold-soft)] text-[var(--gold-strong)]';
};

const loadDetail = async () => {
  if (!props.payment?.id) return;
  loading.value = true;
  loadError.value = '';
  try {
    const res = await fetchPayment(props.payment.id);
    detail.value = res.data?.data ?? null;
  } catch (err) {
    loadError.value = err.response?.data?.message || t('supplierPayments.loadOneError');
  } finally {
    loading.value = false;
  }
};

watch(
  () => [props.open, props.payment?.id, props.payment?.updated_at],
  ([isOpen]) => {
    if (isOpen && props.payment?.id) {
      loadDetail();
    } else if (!isOpen) {
      detail.value = null;
    }
  }
);
</script>
