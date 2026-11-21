<template>
  <AppDrawer :open="!!returnId" size="xl" @close="$emit('close')">
    <template #header>
      <div class="flex items-center justify-between gap-4 border-b border-[var(--border)] bg-[var(--card)] px-6 py-4">
        <div class="flex items-center gap-3 min-w-0">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">
            <RotateCcw class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-base font-extrabold text-[var(--text)]">{{ $t('posReturns.detailsTitle') }}</h2>
              <span
                v-if="returnRecord"
                class="rounded-md bg-[var(--bg)] border border-[var(--border)] px-2 py-0.5 font-mono text-xs font-black text-[var(--text)]"
              >
                {{ returnRecord.return_number }}
              </span>
            </div>
            <p class="mt-0.5 text-xs text-[var(--muted)]">
              <span v-if="returnRecord" class="font-mono">{{ formatDateTime(returnRecord.returned_at, locale) }}</span>
              <span v-else>{{ $t('common.loading') }}</span>
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <button
            v-if="returnRecord?.inventory_documents?.length"
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-xl border border-emerald-500/35 bg-emerald-500/10 px-3 text-xs font-bold text-emerald-700 dark:text-emerald-400 transition hover:bg-emerald-600 hover:text-white cursor-pointer"
            @click="openLinkedDoc(returnRecord.inventory_documents[0])"
          >
            <PackageCheck class="w-3.5 h-3.5" />
            <span>{{ $t('posReturns.linkedReceipt') }} ({{ returnRecord.inventory_documents[0].document_number }})</span>
          </button>
          <button
            v-if="returnRecord?.sale?.id"
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--card)] cursor-pointer"
            @click="$emit('open-sale', returnRecord.sale.id)"
          >
            <Receipt class="w-3.5 h-3.5 text-[var(--muted)]" />
            <span>{{ $t('posReturns.originalInvoice') }}</span>
          </button>
          <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--border)] text-[var(--muted)] transition hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            @click="$emit('close')"
          >
            <X class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div v-if="returnRecord && !loading" class="flex items-center gap-1 overflow-x-auto border-b border-[var(--border)] bg-[var(--bg)]/40 px-6 pt-2">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          class="flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-xs font-bold transition cursor-pointer"
          :class="activeTab === tab.id
            ? 'border-[var(--text)] text-[var(--text)]'
            : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]'"
          @click="activeTab = tab.id"
        >
          <component :is="tab.icon" class="w-3.5 h-3.5" />
          <span>{{ tab.label }}</span>
          <span
            v-if="tab.badge !== undefined"
            class="rounded-full bg-[var(--card)] border border-[var(--border)] px-1.5 py-0.5 text-[10px] font-black leading-none"
          >
            {{ tab.badge }}
          </span>
        </button>
      </div>
    </template>

    <!-- Loading Body -->
    <div v-if="loading" class="flex flex-col items-center justify-center py-24 text-[var(--muted)]">
      <Loader2 class="w-8 h-8 animate-spin text-[var(--muted)] mb-3" />
      <p class="text-sm font-medium">{{ $t('posReturns.loadingReturnDetails') }}</p>
    </div>

    <!-- Error Body -->
    <div v-else-if="error" class="flex flex-col items-center justify-center py-20 text-center px-6">
      <div class="w-12 h-12 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center mb-3">
        <AlertCircle class="w-6 h-6" />
      </div>
      <p class="text-sm font-bold text-rose-600">{{ error }}</p>
    </div>

    <!-- Content -->
    <div v-else-if="returnRecord" class="space-y-6 p-6">
      <!-- TAB 1: Overview Info -->
      <div v-show="activeTab === 'info'" class="space-y-6">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">{{ $t('posReturns.totalRefundedAmount') }}</p>
            <p class="mt-1 font-mono text-lg font-black text-[var(--text)] tabular-nums">
              {{ Number(returnRecord.total_returned || 0).toFixed(2) }} <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
            </p>
          </div>

          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">{{ $t('posReturns.returnedItemsCountLabel') }}</p>
            <p class="mt-1 font-mono text-lg font-black text-[var(--text)] tabular-nums">
              {{ returnRecord.items?.length || 0 }}
            </p>
          </div>

          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">{{ $t('posReturns.refundMethod') }}</p>
            <p class="mt-1 text-sm font-black text-[var(--text)] flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
              {{ paymentMethodLabel(returnRecord.payment_method) }}
            </p>
          </div>

          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">{{ $t('posReturns.originalInvoice') }}</p>
            <button
              v-if="returnRecord.sale?.id"
              type="button"
              class="mt-1 font-mono text-sm font-black text-[var(--text)] hover:text-[var(--crimson)] hover:underline flex items-center gap-1 cursor-pointer"
              @click="$emit('open-sale', returnRecord.sale.id)"
            >
              <span>{{ returnRecord.sale.invoice_number }}</span>
              <ArrowUpLeft class="w-3.5 h-3.5 text-[var(--muted)]" />
            </button>
            <p v-else class="mt-1 text-sm font-bold text-[var(--muted)]">—</p>
          </div>
        </div>

        <!-- Key Value Table -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
          <div class="bg-[var(--bg)]/60 px-4 py-2.5 border-b border-[var(--border)] text-xs font-bold text-[var(--text)]">
            {{ $t('posReturns.returnDetailsHeader') }}
          </div>
          <div class="divide-y divide-[var(--border)] text-xs">
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">{{ $t('posReturns.returnNumber') }}</span>
              <span class="font-mono font-bold text-[var(--text)]">{{ returnRecord.return_number }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">{{ $t('posReturns.returnDateTime') }}</span>
              <span class="font-mono font-bold text-[var(--text)]">{{ formatDateTime(returnRecord.returned_at, locale) }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">{{ $t('posReturns.responsibleCashier') }}</span>
              <span class="font-bold text-[var(--text)]">{{ returnRecord.creator?.name || '—' }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">{{ $t('posReturns.shift') }}</span>
              <button
                v-if="returnRecord.shift?.id"
                type="button"
                class="font-mono font-bold text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                @click="$emit('open-shift', returnRecord.shift.id)"
              >
                {{ returnRecord.shift.shift_number }}
              </button>
              <span v-else class="font-bold text-[var(--muted)]">—</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">{{ $t('posReturns.linkedReceiptDoc') }}</span>
              <button
                v-if="returnRecord.inventory_documents?.length"
                type="button"
                class="inline-flex items-center gap-1.5 font-mono font-bold text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                @click="openLinkedDoc(returnRecord.inventory_documents[0])"
              >
                <PackageCheck class="w-3.5 h-3.5 text-emerald-600" />
                <span>{{ returnRecord.inventory_documents[0].document_number }}</span>
              </button>
              <span v-else class="font-bold text-[var(--muted)]">—</span>
            </div>
            <div class="flex items-start justify-between gap-4 px-4 py-3">
              <span class="text-[var(--muted)] font-medium shrink-0">{{ $t('posReturns.returnNotes') }}</span>
              <span class="font-medium text-[var(--text)] text-end">{{ returnRecord.notes || $t('posReturns.noNotes') }}</span>
            </div>
          </div>
        </div>

        <!-- Returned Items Table (Inline with Return Details) -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 bg-[var(--bg)]/60 border-b border-[var(--border)]">
            <div class="flex items-center gap-2">
              <BookOpen class="w-4 h-4 text-[var(--muted)]" />
              <span class="text-xs font-bold text-[var(--text)]">{{ $t('posReturns.returnedItemsTitle') }}</span>
            </div>
            <span class="rounded-full bg-[var(--card)] border border-[var(--border)] px-2 py-0.5 text-[11px] font-extrabold text-[var(--muted)]">
              {{ $t('posReturns.itemCountBadge', { count: returnRecord.items?.length || 0 }) }}
            </span>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-start border-collapse">
              <thead>
                <tr class="border-b border-[var(--border)] bg-[var(--bg)]/40 text-[11px] font-bold text-[var(--muted)]">
                  <th class="px-4 py-3 text-start">{{ $t('posReturns.productCol') }}</th>
                  <th class="px-4 py-3 text-center">{{ $t('posReturns.returnedQtyCol') }}</th>
                  <th class="px-4 py-3 text-center">{{ $t('posReturns.unitPriceCol') }}</th>
                  <th class="px-4 py-3 text-center">{{ $t('posReturns.allocatedDiscountCol') }}</th>
                  <th class="px-4 py-3 text-end">{{ $t('posReturns.refundedAmountCol') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)] text-xs">
                <tr v-for="item in returnRecord.items" :key="item.id" class="hover:bg-[var(--bg)]/40">
                  <td class="px-4 py-3">
                    <div class="font-bold text-[var(--text)]">{{ item.product?.name || $t('posReturns.productFallback', { id: item.product_id }) }}</div>
                    <div class="text-[11px] text-[var(--muted)] font-mono flex items-center gap-2 mt-0.5">
                      <span v-if="item.product?.barcode">{{ $t('posReturns.barcodeLabel') }} {{ item.product.barcode }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-center font-mono font-extrabold text-[var(--text)]">
                    {{ Number(item.quantity).toFixed(item.quantity % 1 === 0 ? 0 : 2) }}
                  </td>
                  <td class="px-4 py-3 text-center font-mono font-semibold text-[var(--muted)]">
                    {{ Number(item.unit_price || 0).toFixed(2) }} {{ $t('common.currency') }}
                  </td>
                  <td class="px-4 py-3 text-center font-mono font-semibold text-[var(--muted)]">
                    -{{ Number(item.allocated_discount || 0).toFixed(2) }} {{ $t('common.currency') }}
                  </td>
                  <td class="px-4 py-3 text-end font-mono font-black text-[var(--text)]">
                    {{ Number(item.refund_amount || 0).toFixed(2) }} {{ $t('common.currency') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- TAB 2: Original Sale & Customer -->
      <div v-show="activeTab === 'sale'" class="space-y-4">
        <div v-if="returnRecord.sale" class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 space-y-4">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] pb-4">
            <div class="flex items-center gap-3">
              <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">
                <Receipt class="w-5 h-5" />
              </div>
              <div>
                <div class="flex items-center gap-2">
                  <span class="font-mono text-sm font-black text-[var(--text)]">{{ returnRecord.sale.invoice_number }}</span>
                  <span class="rounded-md bg-[var(--bg)] border border-[var(--border)] px-2 py-0.5 text-[10px] font-bold text-[var(--muted)]">
                    {{ paymentMethodLabel(returnRecord.sale.payment_method) }}
                  </span>
                </div>
                <p class="mt-0.5 text-xs text-[var(--muted)] font-mono">{{ formatDateTime(returnRecord.sale.sold_at, locale) }}</p>
              </div>
            </div>

            <button
              type="button"
              class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--bg)] cursor-pointer"
              @click="$emit('open-sale', returnRecord.sale.id)"
            >
              <span>{{ $t('posReturns.viewOriginalSale') }}</span>
              <ArrowUpLeft class="w-3.5 h-3.5 text-[var(--muted)]" />
            </button>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div class="rounded-xl bg-[var(--bg)] p-3">
              <span class="text-[var(--muted)] block">{{ $t('posReturns.customer') }}</span>
              <span class="mt-1 block font-bold text-[var(--text)]">
                {{ returnRecord.sale.customer?.name || $t('posReturns.walkInCustomer') }}
              </span>
              <span v-if="returnRecord.sale.customer?.phone" class="mt-0.5 block font-mono text-[11px] text-[var(--muted)]">
                {{ returnRecord.sale.customer.phone }}
              </span>
            </div>
            <div class="rounded-xl bg-[var(--bg)] p-3">
              <span class="text-[var(--muted)] block">{{ $t('posReturns.originalSaleValue') }}</span>
              <span class="mt-1 block font-mono text-sm font-black text-[var(--text)]">
                {{ Number(returnRecord.sale.grand_total || 0).toFixed(2) }} {{ $t('common.currency') }}
              </span>
            </div>
            <div class="rounded-xl bg-[var(--bg)] p-3">
              <span class="text-[var(--muted)] block font-bold">{{ $t('posReturns.thisReturnValue') }}</span>
              <span class="mt-1 block font-mono text-sm font-black text-[var(--text)]">
                {{ Number(returnRecord.total_returned || 0).toFixed(2) }} {{ $t('common.currency') }}
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 4: Linked Inventory Document -->
      <div v-show="activeTab === 'inventory'" class="space-y-4">
        <div v-if="returnRecord.inventory_documents && returnRecord.inventory_documents.length > 0" class="space-y-4">
          <div
            v-for="doc in returnRecord.inventory_documents"
            :key="doc.id"
            class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
          >
            <div class="bg-[var(--bg)]/60 px-4 py-3 border-b border-[var(--border)] flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                  <PackageCheck class="w-4 h-4" />
                </div>
                <div>
                  <button
                    type="button"
                    class="font-mono text-xs font-extrabold text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer flex items-center gap-1"
                    @click="openLinkedDoc(doc)"
                  >
                    <span>{{ doc.document_number }}</span>
                    <ArrowUpLeft class="w-3 h-3 text-[var(--muted)]" />
                  </button>
                  <p class="text-[11px] text-[var(--muted)]">
                    {{ $t('posReturns.linkedReceiptTitle') }}
                  </p>
                </div>
              </div>

              <div class="flex items-center gap-2">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">
                  {{ doc.status === 'posted' ? $t('posReturns.postedToStock') : doc.status }}
                </span>
                <button
                  type="button"
                  class="px-2.5 py-1 rounded-lg bg-[var(--card)] border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
                  @click="openLinkedDoc(doc)"
                >
                  {{ $t('posReturns.viewDocDetails') }}
                </button>
              </div>
            </div>

            <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs bg-[var(--bg)]/20 border-b border-[var(--border)]">
              <div>
                <span class="text-[var(--muted)] block">{{ $t('posReturns.receivingWarehouse') }}</span>
                <span class="font-bold text-[var(--text)] mt-0.5 block">
                  {{ doc.destination_warehouse?.name || '—' }}
                </span>
              </div>
              <div>
                <span class="text-[var(--muted)] block">{{ $t('posReturns.docCreator') }}</span>
                <span class="font-bold text-[var(--text)] mt-0.5 block">{{ doc.creator?.name || '—' }}</span>
              </div>
              <div>
                <span class="text-[var(--muted)] block">{{ $t('posReturns.receivedItemsCount') }}</span>
                <span class="font-bold text-[var(--text)] mt-0.5 block">{{ $t('posReturns.itemsPlural', { count: doc.items?.length || 0 }) }}</span>
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-start border-collapse">
                <thead>
                  <tr class="border-b border-[var(--border)] bg-[var(--bg)]/40 text-[10px] font-bold text-[var(--muted)]">
                    <th class="px-4 py-2 text-start">{{ $t('posReturns.productCol') }}</th>
                    <th class="px-4 py-2 text-center">{{ $t('posReturns.returnedQtyToStock') }}</th>
                    <th class="px-4 py-2 text-end">{{ $t('posReturns.costCol') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)] text-xs">
                  <tr v-for="dItem in doc.items" :key="dItem.id">
                    <td class="px-4 py-2.5 font-bold text-[var(--text)]">{{ dItem.product?.name }}</td>
                    <td class="px-4 py-2.5 text-center font-mono font-extrabold text-emerald-600">+{{ Number(dItem.quantity) }}</td>
                    <td class="px-4 py-2.5 text-end font-mono text-[var(--muted)]">{{ Number(dItem.unit_cost || 0).toFixed(2) }} {{ $t('common.currency') }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div v-else class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-8 text-center">
          <PackageCheck class="w-8 h-8 text-[var(--muted)]/50 mx-auto mb-2" />
          <p class="text-xs font-bold text-[var(--text)]">{{ $t('posReturns.noLinkedDocs') }}</p>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-between border-t border-[var(--border)] bg-[var(--bg)]/60 px-6 py-3.5">
        <span class="text-[11px] font-medium text-[var(--muted)]">{{ $t('posReturns.footerBrand') }}</span>
        <button
          type="button"
          class="px-5 py-2 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
          @click="$emit('close')"
        >
          {{ $t('common.close') }}
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  RotateCcw,
  X,
  Loader2,
  AlertCircle,
  FileText,
  BookOpen,
  Receipt,
  PackageCheck,
  ArrowUpLeft,
} from 'lucide-vue-next';
import AppDrawer from '../../../components/ui/AppDrawer.vue';
import api from '../../../services/api';
import { formatDateTime } from '../../../utils/date';

const router = useRouter();
const { t, te, locale } = useI18n();

function paymentMethodLabel(pm) {
  if (!pm) return '—';
  if (pm.type && te(`pos.paymentMethods.${pm.type}`)) {
    return t(`pos.paymentMethods.${pm.type}`);
  }
  return pm.name || '—';
}

const props = defineProps({
  returnId: {
    type: [Number, String],
    required: true,
  },
});

const emit = defineEmits(['close', 'open-sale', 'open-shift', 'open-inventory-document']);

const returnRecord = ref(null);
const loading = ref(true);
const error = ref(null);
const activeTab = ref('info');

const tabs = computed(() => [
  { id: 'info', label: t('posReturns.tabs.info'), icon: FileText },
  { id: 'sale', label: t('posReturns.tabs.sale'), icon: Receipt },
  { id: 'inventory', label: t('posReturns.tabs.inventory'), icon: PackageCheck, badge: returnRecord.value?.inventory_documents?.length || 0 },
]);

async function loadReturnDetails() {
  if (!props.returnId) return;
  loading.value = true;
  error.value = null;
  try {
    const res = await api.get(`/pos/returns/${props.returnId}`);
    if (res.data?.success) {
      returnRecord.value = res.data.data;
    } else {
      error.value = t('posReturns.fetchError');
    }
  } catch (err) {
    error.value = err.response?.data?.message || t('posReturns.loadError');
  } finally {
    loading.value = false;
  }
}

function openLinkedDoc(doc) {
  if (!doc?.id) return;
  const typeSlugMap = {
    goods_delivery: 'goods-delivery',
    goods_receipt: 'goods-receipt',
    stock_issue: 'stock-issue',
    warehouse_transfer: 'warehouse-transfer',
    stock_adjustment: 'stock-adjustment',
    inventory_return: 'inventory-return',
    stock_write_off: 'stock-write-off',
    opening_balance: 'opening-balance',
  };
  const slug = typeSlugMap[doc.document_type] || 'goods-receipt';
  emit('close');
  router.push({
    path: `/inventory/${slug}`,
    query: {
      search: doc.document_number || undefined,
      open: String(doc.id),
    },
  });
}

watch(() => props.returnId, () => {
  activeTab.value = 'info';
  loadReturnDetails();
}, { immediate: true });
</script>
