<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-[720px] max-h-[92vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div>
          <h3 class="text-xl font-black">{{ $t('pos.modals.returns.title') }}</h3>
          <p class="text-xs text-[var(--muted)]">{{ $t('pos.modals.returns.subtitle') }}</p>
        </div>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Search Bar -->
      <div class="flex gap-2 mb-4">
        <input
          v-model="invoiceSearchQuery"
          type="text"
          :placeholder="$t('pos.modals.returns.searchPlaceholder')"
          class="flex-1 h-12 px-4 rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-mono text-sm outline-none focus:border-[var(--crimson)]"
          @keydown.enter="searchInvoice"
        />
        <button
          type="button"
          class="h-12 px-6 rounded-xl bg-[var(--crimson)] text-white font-bold text-sm hover:opacity-90 transition flex items-center gap-2 disabled:opacity-50"
          :disabled="searching || !invoiceSearchQuery.trim()"
          @click="searchInvoice"
        >
          <span v-if="searching" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
          <Search v-else class="w-4 h-4" />
          <span>{{ $t('pos.modals.returns.searchBtn') }}</span>
        </button>
      </div>

      <!-- Active Invoice View -->
      <div v-if="activeInvoice" class="space-y-4">
        <!-- Invoice Header Card -->
        <div class="p-4 rounded-2xl bg-[var(--bg)] border border-[var(--border)] space-y-2 text-xs">
          <div class="flex items-center justify-between">
            <span class="font-mono font-black text-sm text-[var(--text)]" dir="ltr">{{ activeInvoice.invoice_number }}</span>
            <span class="font-mono font-bold text-[var(--muted)]" dir="ltr">{{ formatDateTime(activeInvoice.sold_at, locale) }}</span>
          </div>
          <div class="flex flex-wrap justify-between gap-2 pt-1 border-t border-[var(--border)]">
            <span>{{ $t('pos.modals.returns.customerLabel') }} <b>{{ activeInvoice.customer?.name || $t('pos.modals.heldSales.cashCustomer') }}</b></span>
            <span>{{ $t('pos.modals.returns.paymentMethodLabel') }} <b class="text-[var(--text)]">{{ invoicePaymentMethodName }}</b></span>
            <span>{{ $t('pos.modals.returns.totalLabel') }} <b class="font-bold">{{ formatMoney(activeInvoice.grand_total) }} {{ $t('common.currency') }}</b></span>
          </div>
        </div>

        <!-- Returnable Items Table -->
        <div>
          <h4 class="text-sm font-black mb-2 flex items-center justify-between">
            <span>{{ $t('pos.modals.returns.returnableItemsTitle') }}</span>
            <span class="text-xs font-normal text-[var(--muted)]">{{ $t('pos.modals.returns.samePaymentMethodHint') }}</span>
          </h4>

          <div class="border border-[var(--border)] rounded-2xl overflow-hidden">
            <table class="w-full text-start text-xs">
              <thead class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                <tr>
                  <th class="p-2.5 text-start">{{ $t('pos.modals.returns.colItem') }}</th>
                  <th class="p-2.5 text-center">{{ $t('pos.modals.returns.colSold') }}</th>
                  <th class="p-2.5 text-center">{{ $t('pos.modals.returns.colPreviouslyReturned') }}</th>
                  <th class="p-2.5 text-center">{{ $t('pos.modals.returns.colAvailable') }}</th>
                  <th class="p-2.5 text-center">{{ $t('pos.modals.returns.colRefundRate') }}</th>
                  <th class="p-2.5 text-center w-28">{{ $t('pos.modals.returns.colReturnQty') }}</th>
                  <th class="p-2.5 text-end">{{ $t('pos.modals.returns.colRefundAmount') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)]">
                <tr
                  v-for="item in returnableItems"
                  :key="item.pos_sale_item_id"
                  :class="item.can_return ? 'hover:bg-[var(--bg)]' : 'opacity-40 bg-[var(--bar)]'"
                >
                  <td class="p-2.5 font-bold">
                    <div>{{ item.product_name }}</div>
                    <small v-if="item.barcode" class="text-[var(--muted)] font-mono">{{ item.barcode }}</small>
                  </td>
                  <td class="p-2.5 text-center font-semibold">{{ item.sold_quantity }}</td>
                  <td class="p-2.5 text-center font-semibold">{{ item.already_returned_quantity }}</td>
                  <td class="p-2.5 text-center font-black text-[var(--crimson)]">{{ item.remaining_returnable_quantity }}</td>
                  <td class="p-2.5 text-center font-semibold">{{ formatMoney(item.net_paid_unit_price) }} {{ $t('common.currency') }}</td>
                  <td class="p-2.5 text-center">
                    <input
                      v-if="item.can_return"
                      v-model.number="returnQuantities[item.pos_sale_item_id]"
                      type="number"
                      min="0"
                      :max="item.remaining_returnable_quantity"
                      step="any"
                      class="w-20 h-8 text-center font-black rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                    />
                    <span v-else class="text-[var(--muted)]">{{ $t('pos.modals.returns.alreadyReturned') }}</span>
                  </td>
                  <td class="p-2.5 text-end font-black">
                    {{ formatMoney(calculateItemRefund(item)) }} {{ $t('common.currency') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Total Refund Summary -->
        <div class="flex items-center justify-between p-4 rounded-2xl bg-[var(--soft)] border border-[var(--crimson)]/20">
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('pos.modals.returns.totalRefundDue') }}</span>
            <b class="text-2xl font-black text-[var(--crimson)]">{{ formatMoney(totalRefundAmount) }} {{ $t('common.currency') }}</b>
          </div>
          <div class="text-xs text-[var(--muted)] text-end max-w-xs">
            {{ $t('pos.modals.returns.refundNotice', { method: invoicePaymentMethodName }) }}
          </div>
        </div>

        <!-- Return Notes -->
        <div>
          <label class="block text-xs font-semibold mb-1 text-[var(--muted)]">{{ $t('pos.modals.returns.notesLabel') }}</label>
          <input
            v-model="returnNotes"
            type="text"
            :placeholder="$t('pos.modals.returns.notesPlaceholder')"
            class="w-full h-10 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <!-- Submit Return Action -->
        <div class="flex gap-3 pt-2">
          <button
            type="button"
            class="h-11 px-6 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-bold text-sm hover:bg-[var(--border)] transition"
            @click="printActiveInvoice"
          >
            {{ $t('pos.modals.returns.reprintBtn') }}
          </button>
          <div class="flex-1"></div>
          <button
            type="button"
            class="h-11 px-8 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-bold text-sm shadow-md shadow-emerald-600/20 transition flex items-center gap-2 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
            :disabled="submittingReturn || totalRefundAmount <= 0"
            @click="confirmReturn"
          >
            <span v-if="submittingReturn" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
            <RotateCcw class="w-4 h-4" />
            <span>{{ $t('pos.modals.returns.submitReturnBtn') }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { X, Search, RotateCcw } from 'lucide-vue-next';
import { usePosStore } from '../../../stores/pos';
import { useNotificationStore } from '../../../stores/notification';
import { formatDateTime } from '../../../utils/date';

const emit = defineEmits(['close', 'reprint']);

const { t, te, locale } = useI18n();
const posStore = usePosStore();
const notify = useNotificationStore();

const invoiceSearchQuery = ref('');
const searching = ref(false);
const activeInvoice = ref(null);
const returnableItems = ref([]);
const returnQuantities = ref({});
const returnNotes = ref('');
const submittingReturn = ref(false);

const invoicePaymentMethodName = computed(() => {
  const pm = activeInvoice.value?.payment_method || activeInvoice.value?.paymentMethod;
  if (!pm) return t('pos.paymentMethods.cash');
  if (pm.type && te(`pos.paymentMethods.${pm.type}`)) {
    return t(`pos.paymentMethods.${pm.type}`);
  }
  return pm.name || t('pos.paymentMethods.cash');
});

async function searchInvoice() {
  const q = invoiceSearchQuery.value.trim();
  if (!q) return;

  searching.value = true;
  activeInvoice.value = null;
  returnableItems.value = [];
  returnQuantities.value = {};

  try {
    const sale = await posStore.lookupInvoice(q);
    if (sale) {
      activeInvoice.value = sale;
      const returnData = await posStore.getReturnableItems(sale.id);
      if (returnData?.items) {
        returnableItems.value = returnData.items;
        for (const item of returnData.items) {
          returnQuantities.value[item.pos_sale_item_id] = 0;
        }
      }
    }
  } finally {
    searching.value = false;
  }
}

function calculateItemRefund(item) {
  const qty = Number(returnQuantities.value[item.pos_sale_item_id] || 0);
  if (qty <= 0) return 0;
  const netPrice = Number(item.net_paid_unit_price || 0);
  return Math.round((qty * netPrice) * 100) / 100;
}

const totalRefundAmount = computed(() => {
  return returnableItems.value.reduce((acc, it) => acc + calculateItemRefund(it), 0);
});

async function confirmReturn() {
  if (totalRefundAmount.value <= 0) {
    notify.error(t('pos.modals.returns.selectAtLeastOne'));
    return;
  }

  const itemsToReturn = [];
  for (const item of returnableItems.value) {
    const qty = Number(returnQuantities.value[item.pos_sale_item_id] || 0);
    if (qty > 0) {
      if (qty > item.remaining_returnable_quantity) {
        notify.error(t('pos.modals.returns.exceedsReturnable', { name: item.product_name }));
        return;
      }
      itemsToReturn.push({
        pos_sale_item_id: item.pos_sale_item_id,
        quantity: qty,
      });
    }
  }

  submittingReturn.value = true;
  const returnClientUuid = (typeof crypto !== 'undefined' && crypto.randomUUID)
    ? crypto.randomUUID()
    : ('ret_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10));

  try {
    const res = await posStore.submitReturn({
      client_uuid: returnClientUuid,
      pos_sale_id: activeInvoice.value.id,
      items: itemsToReturn,
      notes: returnNotes.value,
    });

    if (res.success) {
      emit('close');
    }
  } finally {
    submittingReturn.value = false;
  }
}

function printActiveInvoice() {
  if (activeInvoice.value) {
    emit('reprint', activeInvoice.value);
  }
}

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
