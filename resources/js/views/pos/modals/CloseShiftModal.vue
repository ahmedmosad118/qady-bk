<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-[540px] max-h-[92vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div>
          <h3 class="text-xl font-black">{{ $t('pos.modals.closeShift.title') }}</h3>
          <p class="text-xs text-[var(--muted)]">{{ $t('pos.modals.closeShift.shiftSubtitle', { number: summary?.shift?.shift_number || '' }) }}</p>
        </div>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Financial Summary Grid -->
      <div v-if="loadingSummary" class="py-12 text-center text-[var(--muted)]">
        <span class="inline-block animate-spin h-6 w-6 border-2 border-[var(--crimson)] border-t-transparent rounded-full mb-2"></span>
        <p class="text-xs font-bold">{{ $t('pos.modals.closeShift.loadingSummary') }}</p>
      </div>

      <div v-else class="space-y-2 mb-4 text-xs font-semibold">
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">{{ $t('pos.modals.closeShift.salesCount') }}</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ summary?.sales_count || 0 }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">{{ $t('pos.modals.closeShift.cashSales') }}</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.cash_sales) }} {{ $t('common.currency') }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">{{ $t('pos.modals.closeShift.visaSales') }}</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.visa_sales) }} {{ $t('common.currency') }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">{{ $t('pos.modals.closeShift.bankTransferSales') }}</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.bank_transfer_sales) }} {{ $t('common.currency') }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)] text-red-600">
          <span>{{ $t('pos.modals.closeShift.cashReturns') }}</span>
          <b class="font-bold text-sm">-{{ formatMoney(summary?.cash_returns) }} {{ $t('common.currency') }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)] text-red-600">
          <span>{{ $t('pos.modals.closeShift.cashExpenses') }}</span>
          <b class="font-bold text-sm">-{{ formatMoney(summary?.expenses) }} {{ $t('common.currency') }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)] text-red-600">
          <span>{{ $t('pos.modals.closeShift.cashWithdrawals') }}</span>
          <b class="font-bold text-sm">-{{ formatMoney(summary?.withdrawals) }} {{ $t('common.currency') }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">{{ $t('pos.modals.closeShift.openingBalance') }}</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.opening_balance) }} {{ $t('common.currency') }}</b>
        </div>
      </div>

      <!-- Actual Cash Input -->
      <div class="mb-4">
        <label class="block text-sm font-bold mb-1 text-[var(--text)]">{{ $t('pos.modals.closeShift.actualCashLabel') }}</label>
        <input
          v-model.number="actualCash"
          type="number"
          step="any"
          min="0"
          required
          class="w-full h-12 text-center text-2xl font-black rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
        />
      </div>

      <!-- Closing Notes -->
      <div class="mb-5">
        <label class="block text-xs font-semibold mb-1 text-[var(--muted)]">{{ $t('pos.modals.closeShift.notesLabel') }}</label>
        <input
          v-model="notes"
          type="text"
          :placeholder="$t('pos.modals.closeShift.notesPlaceholder')"
          class="w-full h-10 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
        />
      </div>

      <!-- Actions -->
      <div class="flex gap-3">
        <button
          type="button"
          class="flex-1 h-12 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-bold hover:bg-[var(--border)] transition"
          :disabled="submitting"
          @click="$emit('close')"
        >
          {{ $t('pos.modals.closeShift.back') }}
        </button>
        <button
          type="button"
          class="flex-1 h-12 rounded-xl bg-[var(--crimson)] text-white font-bold hover:opacity-90 transition flex items-center justify-center gap-2 disabled:opacity-50"
          :disabled="submitting"
          @click="submitClose"
        >
          <span v-if="submitting" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
          <span>{{ $t('pos.modals.closeShift.confirmBtn') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { X } from 'lucide-vue-next';

const props = defineProps({
  summary: {
    type: Object,
    default: null,
  },
  loadingSummary: {
    type: Boolean,
    default: false,
  },
  submitting: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'confirm']);

const actualCash = ref(0);
const notes = ref('');

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function submitClose() {
  emit('confirm', {
    actual_cash: Number(actualCash.value || 0),
    notes: notes.value,
  });
}
</script>
