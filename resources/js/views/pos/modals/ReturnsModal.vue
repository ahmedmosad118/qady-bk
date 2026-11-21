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
        <div class="relative flex-1">
          <input
            ref="searchInputRef"
            v-model="invoiceSearchQuery"
            type="text"
            :placeholder="$t('pos.modals.returns.searchPlaceholder')"
            class="w-full h-12 ps-4 pe-9 rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-sm outline-none focus:border-[var(--crimson)]"
            @input="onSearchInput"
            @keydown.enter.prevent="searchInvoice"
          />
          <button
            v-if="invoiceSearchQuery"
            type="button"
            class="absolute end-2.5 top-1/2 -translate-y-1/2 p-1 rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] transition cursor-pointer"
            :title="$t('pos.clearSearch')"
            @click="clearSearch"
          >
            <X class="w-4 h-4" />
          </button>
        </div>
        <button
          type="button"
          class="h-12 px-6 rounded-xl bg-[var(--crimson)] text-white font-bold text-sm hover:opacity-90 transition flex items-center gap-2 disabled:opacity-50 cursor-pointer"
          :disabled="searching"
          @click="searchInvoice"
        >
          <span v-if="searching" class="animate-spin h-4 w-4 border-2 border-white border-t-transparent rounded-full"></span>
          <Search v-else class="w-4 h-4" />
          <span>{{ $t('pos.modals.returns.searchBtn') }}</span>
        </button>
      </div>

      <!-- Loading Selected Invoice Details -->
      <div v-if="loadingInvoice" class="py-12 text-center text-[var(--muted)]">
        <Loader2 class="w-7 h-7 animate-spin mx-auto mb-2 text-[var(--crimson)]" />
        <p class="text-xs font-bold">{{ $t('pos.modals.returns.loadingInvoice') }}</p>
      </div>

      <!-- Active Invoice View -->
      <div v-else-if="activeInvoice" class="space-y-4">
        <!-- Back to Search Results -->
        <div v-if="searchResults.length > 0" class="flex items-center justify-between">
          <button
            type="button"
            class="inline-flex items-center gap-1.5 text-xs font-bold text-[var(--muted)] hover:text-[var(--crimson)] transition cursor-pointer"
            @click="backToResults"
          >
            <component :is="backIcon" class="w-4 h-4" />
            <span>{{ $t('pos.modals.returns.backToResults') }}</span>
          </button>
        </div>

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
            class="h-11 px-6 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-bold text-sm hover:bg-[var(--border)] transition cursor-pointer"
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

      <!-- Matching Invoices Search Results List -->
      <div v-else class="space-y-3">
        <div v-if="searching && searchResults.length === 0" class="py-10 text-center text-[var(--muted)]">
          <Loader2 class="w-6 h-6 animate-spin mx-auto mb-2 text-[var(--crimson)]" />
        </div>

        <template v-else-if="searchResults.length > 0">
          <div class="flex items-center justify-between text-xs text-[var(--muted)]">
            <span class="font-bold">{{ $t('pos.modals.returns.resultsCount', { count: searchMeta.total }) }}</span>
            <span>{{ $t('pos.modals.returns.selectInvoiceHint') }}</span>
          </div>

          <div
            class="divide-y divide-[var(--border)] rounded-2xl border border-[var(--border)] overflow-hidden bg-[var(--card)] transition-opacity"
            :class="{ 'opacity-60 pointer-events-none': searching }"
          >
            <button
              v-for="sale in searchResults"
              :key="sale.id"
              type="button"
              class="group w-full px-4 py-3 text-start hover:bg-[var(--bg)] transition flex items-center justify-between gap-3 cursor-pointer"
              @click="selectInvoice(sale)"
            >
              <div class="min-w-0 flex-1 space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-mono text-sm font-black text-[var(--text)] group-hover:text-[var(--crimson)] transition-colors" dir="ltr">
                    {{ sale.invoice_number }}
                  </span>
                  <span class="rounded-md bg-[var(--bg)] border border-[var(--border)] px-2 py-0.5 text-[11px] font-bold text-[var(--muted)]">
                    {{ getPaymentMethodName(sale) }}
                  </span>
                  <span v-if="sale.items_count !== undefined" class="text-[11px] font-semibold text-[var(--muted)]">
                    {{ $t('pos.modals.returns.itemsCountBadge', { count: sale.items_count }) }}
                  </span>
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-[var(--muted)]">
                  <span class="font-semibold text-[var(--text)]/80 truncate">
                    {{ sale.customer?.name || $t('pos.modals.heldSales.cashCustomer') }}
                  </span>
                  <span class="font-mono text-[11px]" dir="ltr">{{ formatDateTime(sale.sold_at, locale) }}</span>
                </div>
              </div>

              <div class="text-end shrink-0">
                <div class="font-mono text-sm font-black text-[var(--text)]">
                  {{ formatMoney(sale.grand_total) }}
                  <span class="text-[11px] font-sans font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </div>
              </div>
            </button>
          </div>

          <!-- Results Pagination -->
          <div
            v-if="searchMeta.last_page > 1"
            class="flex items-center justify-between pt-1 text-xs text-[var(--muted)]"
          >
            <span class="font-semibold">
              {{ $t('common.page') }} {{ searchMeta.current_page }} {{ $t('common.of') }} {{ searchMeta.last_page }}
            </span>
            <div class="flex items-center gap-1.5">
              <button
                type="button"
                :disabled="searching || searchMeta.current_page <= 1"
                class="inline-flex h-8 items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 font-bold text-[var(--text)] hover:border-[var(--crimson)]/50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                @click="fetchInvoices(searchMeta.current_page - 1)"
              >
                <component :is="prevIcon" class="w-3.5 h-3.5" />
                <span>{{ $t('common.previous') }}</span>
              </button>
              <button
                type="button"
                :disabled="searching || searchMeta.current_page >= searchMeta.last_page"
                class="inline-flex h-8 items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 font-bold text-[var(--text)] hover:border-[var(--crimson)]/50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                @click="fetchInvoices(searchMeta.current_page + 1)"
              >
                <span>{{ $t('common.next') }}</span>
                <component :is="nextIcon" class="w-3.5 h-3.5" />
              </button>
            </div>
          </div>
        </template>

        <div v-else-if="hasSearched" class="py-10 text-center text-[var(--muted)]">
          <SearchX class="w-8 h-8 mx-auto mb-2 text-[var(--muted)]/50" />
          <p class="text-xs font-bold">{{ $t('pos.modals.returns.noResults') }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { X, Search, SearchX, RotateCcw, Loader2, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { usePosStore } from '../../../stores/pos';
import { useNotificationStore } from '../../../stores/notification';
import { formatDateTime } from '../../../utils/date';

const emit = defineEmits(['close', 'reprint']);

const { t, te, locale } = useI18n();
const posStore = usePosStore();
const notify = useNotificationStore();

const searchInputRef = ref(null);
const invoiceSearchQuery = ref('');
const searching = ref(false);
const hasSearched = ref(false);
const searchResults = ref([]);
const searchMeta = ref({
  current_page: 1,
  last_page: 1,
  per_page: 8,
  total: 0,
});

const loadingInvoice = ref(false);
const activeInvoice = ref(null);
const returnableItems = ref([]);
const returnQuantities = ref({});
const returnNotes = ref('');
const submittingReturn = ref(false);

let searchDebounceTimer = null;
let searchSeq = 0;

const isRtl = computed(() => locale.value === 'ar');
const prevIcon = computed(() => (isRtl.value ? ChevronRight : ChevronLeft));
const nextIcon = computed(() => (isRtl.value ? ChevronLeft : ChevronRight));
const backIcon = computed(() => (isRtl.value ? ChevronRight : ChevronLeft));

function getPaymentMethodName(sale) {
  const pm = sale?.payment_method || sale?.paymentMethod;
  if (!pm) return t('pos.paymentMethods.cash');
  if (pm.type && te(`pos.paymentMethods.${pm.type}`)) {
    return t(`pos.paymentMethods.${pm.type}`);
  }
  return pm.name || t('pos.paymentMethods.cash');
}

const invoicePaymentMethodName = computed(() => getPaymentMethodName(activeInvoice.value));

async function fetchInvoices(page = 1, { autoSelectSingle = false } = {}) {
  const q = invoiceSearchQuery.value.trim();
  const seq = ++searchSeq;
  searching.value = true;

  try {
    const res = await posStore.searchInvoices({
      search: q,
      page,
      perPage: 8,
    });
    if (seq !== searchSeq) return;

    searchResults.value = res.data;
    searchMeta.value = res.meta;
    hasSearched.value = true;

    if (autoSelectSingle && q !== '') {
      const exactMatch = res.data.find(
        (s) => String(s.invoice_number || '').toLowerCase() === q.toLowerCase()
      );
      if (exactMatch) {
        await selectInvoice(exactMatch);
      } else if (res.data.length === 1 && Number(res.meta.total) === 1) {
        await selectInvoice(res.data[0]);
      }
    }
  } finally {
    if (seq === searchSeq) {
      searching.value = false;
    }
  }
}

function onSearchInput() {
  activeInvoice.value = null;
  returnableItems.value = [];
  returnQuantities.value = {};
  clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(() => {
    fetchInvoices(1, { autoSelectSingle: false });
  }, 250);
}

function clearSearch() {
  clearTimeout(searchDebounceTimer);
  invoiceSearchQuery.value = '';
  activeInvoice.value = null;
  returnableItems.value = [];
  returnQuantities.value = {};
  fetchInvoices(1, { autoSelectSingle: false });
  nextTick(() => searchInputRef.value?.focus());
}

async function searchInvoice() {
  clearTimeout(searchDebounceTimer);
  activeInvoice.value = null;
  returnableItems.value = [];
  returnQuantities.value = {};
  await fetchInvoices(1, { autoSelectSingle: true });
}

async function selectInvoice(sale) {
  if (!sale?.id) return;
  loadingInvoice.value = true;
  activeInvoice.value = null;
  returnableItems.value = [];
  returnQuantities.value = {};
  returnNotes.value = '';

  try {
    const [fullSale, returnData] = await Promise.all([
      posStore.getSaleDetails(sale.id),
      posStore.getReturnableItems(sale.id),
    ]);

    activeInvoice.value = fullSale || sale;
    if (returnData?.items) {
      returnableItems.value = returnData.items;
      for (const item of returnData.items) {
        returnQuantities.value[item.pos_sale_item_id] = 0;
      }
    }
  } finally {
    loadingInvoice.value = false;
  }
}

function backToResults() {
  activeInvoice.value = null;
  returnableItems.value = [];
  returnQuantities.value = {};
  returnNotes.value = '';
}

onMounted(() => {
  nextTick(() => searchInputRef.value?.focus());
  fetchInvoices(1, { autoSelectSingle: false });
});

onBeforeUnmount(() => {
  clearTimeout(searchDebounceTimer);
});

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
