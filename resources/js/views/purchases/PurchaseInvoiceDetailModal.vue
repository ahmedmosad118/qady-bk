<template>
  <AppDrawer
    :open="open"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl"
    side="end"
    @close="emit('close')"
  >
    <!-- Drawer Header -->
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
          >
            <FileText class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono">
                {{ invoice?.invoice_number || '—' }}
              </h2>

              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="statusBadgeClass(invoice?.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel(invoice?.status) }}
              </span>
            </div>

            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span>{{ $t('purchaseInvoices.supplier') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ invoice?.supplier?.name || '—' }}</span>
              <span>•</span>
              <span class="font-mono">{{ formatDateOnly(invoice?.invoice_date) }}</span>
            </div>
          </div>
        </div>

        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
          :title="$t('common.close')"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>
    </template>

    <!-- Action Bar -->
    <template #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span class="text-xs font-bold text-[var(--muted)]">
            {{ $t('common.actions') }}:
          </span>

          <span
            v-if="invoice?.status === 'posted'"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.postedNotice') }}</span>
          </span>

          <span
            v-else-if="isLockedByPosting"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
          >
            <Lock class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.lockedNotice') }}</span>
          </span>

          <span
            v-else-if="invoice?.status === 'cancelled'"
            class="inline-flex items-center gap-1 rounded-lg bg-rose-500/10 px-2.5 py-1 text-xs font-bold text-rose-700 dark:text-rose-300"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.cancelledNotice') }}</span>
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-if="invoice?.goods_receipt?.id"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)] cursor-pointer"
            @click="goToGoodsReceipt"
          >
            <PackageCheck class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.openGoodsReceipt') }}</span>
            <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
          </button>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
            @click="printInvoice"
          >
            <Printer class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.print') }}</span>
          </button>

          <button
            v-if="canPay"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--crimson)] px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
            :disabled="actionLoading"
            @click="emit('pay', invoice)"
          >
            <Banknote class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.payInvoice') }}</span>
          </button>

          <button
            v-if="invoice?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-emerald-700 disabled:opacity-50"
            :disabled="actionLoading"
            @click="emit('post', invoice)"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.post') }}</span>
          </button>

          <button
            v-if="canModify"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
            :disabled="actionLoading"
            @click="emit('edit', invoice)"
          >
            <Edit2 class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.edit') }}</span>
          </button>

          <button
            v-if="canModify"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-50 px-3.5 py-1.5 text-xs font-bold text-rose-700 transition-colors hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400"
            :disabled="actionLoading"
            @click="emit('cancel', invoice)"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.cancel') }}</span>
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Content -->
    <div v-if="invoice" class="space-y-6 text-sm text-[var(--text)]">
      <!-- Summary Cards -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <!-- Supplier Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Truck class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.supplier') }}</span>
          </div>
          <p class="mt-2 text-base font-extrabold text-[var(--text)] truncate">{{ invoice.supplier?.name || '—' }}</p>
          <p v-if="invoice.supplier?.phone || invoice.supplier?.mobile" class="mt-1 text-xs font-mono text-[var(--muted)]">
            {{ invoice.supplier?.mobile || invoice.supplier?.phone }}
          </p>
          <p v-if="invoice.supplier?.tax_number" class="mt-0.5 text-xs text-[var(--muted)]">
            {{ $t('purchaseInvoices.taxNumber') }}: {{ invoice.supplier.tax_number }}
          </p>
        </div>

        <!-- Dates & PO Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Calendar class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.dates') }}</span>
          </div>
          <div class="mt-2 space-y-1">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.invoiceDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(invoice.invoice_date) }}</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.dueDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(invoice.due_date) }}</span>
            </div>
            <div v-if="invoice.purchase_order" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.purchaseOrder') }}:</span>
              <button
                type="button"
                class="inline-flex items-center gap-1 font-bold font-mono text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                :title="$t('purchaseInvoices.openPurchaseOrder')"
                @click="goToPurchaseOrder"
              >
                <span>{{ invoice.purchase_order.order_number }}</span>
                <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
              </button>
            </div>
          </div>
        </div>

        <!-- Linked Goods Receipt & Creator Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <PackageCheck class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.goodsReceipt') }}</span>
          </div>
          <div class="mt-2 space-y-1.5">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.goodsReceiptNumber') }}:</span>
              <button
                v-if="invoice.goods_receipt?.id"
                type="button"
                class="inline-flex items-center gap-1 font-bold font-mono text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                @click="goToGoodsReceipt"
              >
                <span>{{ invoice.goods_receipt.document_number }}</span>
                <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
              </button>
              <span v-else class="font-bold font-mono text-[var(--text)]">—</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.goodsReceiptStatus') }}:</span>
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold"
                :class="grStatusBadgeClass(invoice.goods_receipt?.status)"
              >
                {{ grStatusLabel(invoice.goods_receipt?.status) }}
              </span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.creator') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ invoice.creator?.name || '—' }}</span>
            </div>
            <div v-if="invoice.journal" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.journal') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ invoice.journal.code }} — {{ invoice.journal.name }}</span>
            </div>
            <div v-if="invoice.journal_entry" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.journalEntry') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">
                {{ invoice.journal_entry.entry_number }}
                ({{ $t(`journalEntries.statuses.${invoice.journal_entry.status}`) }})
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Items Table -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-extrabold text-[var(--text)] flex items-center gap-2">
            <span>{{ $t('purchaseInvoices.items') }}</span>
            <span class="rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">
              {{ invoice.items?.length || 0 }}
            </span>
          </h3>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 py-3 px-4 text-center">#</th>
                <th class="py-3 px-4 text-start">{{ $t('purchaseInvoices.product') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseInvoices.quantity') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseInvoices.unitPrice') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseInvoices.discount') }}</th>
                <th class="py-3 px-4 text-end">{{ $t('purchaseInvoices.lineTotal') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr
                v-for="(item, idx) in invoice.items"
                :key="item.id || idx"
                class="transition-colors hover:bg-[var(--bg)]/50"
              >
                <td class="py-3 px-4 text-center text-[var(--muted)] font-mono">{{ idx + 1 }}</td>
                <td class="py-3 px-4">
                  <div class="font-bold text-[var(--text)]">
                    {{ item.product_snapshot?.name || item.product?.name || '—' }}
                  </div>
                  <div v-if="item.product_snapshot?.barcode || item.product?.barcode" class="text-[11px] text-[var(--muted)] font-mono">
                    {{ item.product_snapshot?.barcode || item.product?.barcode }}
                  </div>
                </td>
                <td class="py-3 px-4 text-center font-bold text-[var(--text)]">
                  {{ formatQty(item.quantity) }}
                  <span class="text-[10px] font-normal text-[var(--muted)]">
                    {{ $t('purchaseInvoices.unitPiece') }}
                  </span>
                </td>
                <td class="py-3 px-4 text-center font-mono">
                  {{ formatMoney(item.unit_price) }}
                </td>
                <td class="py-3 px-4 text-center">
                  <span v-if="Number(item.discount_amount) > 0" class="font-bold text-[var(--muted)]">
                    -{{ formatMoney(item.discount_amount) }}
                    <span v-if="item.discount_type === 'percentage'" class="text-[10px]">
                      ({{ Number(item.discount_value) }}%)
                    </span>
                  </span>
                  <span v-else class="text-[var(--muted)]">—</span>
                </td>
                <td class="py-3 px-4 text-end font-bold font-mono text-[var(--text)]">
                  {{ formatMoney(item.line_total) }} {{ $t('common.currency') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Additional Expenses Table -->
      <div v-if="invoice.expenses?.length">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-extrabold text-[var(--text)] flex items-center gap-2">
            <span>{{ $t('purchaseInvoices.expenses') }}</span>
            <span class="rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">
              {{ invoice.expenses.length }}
            </span>
          </h3>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 py-3 px-4 text-center">#</th>
                <th class="py-3 px-4 text-start">{{ $t('purchaseInvoices.expenseName') }}</th>
                <th class="py-3 px-4 text-start">{{ $t('purchaseInvoices.expenseNotes') }}</th>
                <th class="py-3 px-4 text-end">{{ $t('purchaseInvoices.expenseAmount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-for="(expense, idx) in invoice.expenses" :key="expense.id || idx">
                <td class="py-2.5 px-4 text-center text-[var(--muted)] font-mono">{{ idx + 1 }}</td>
                <td class="py-2.5 px-4 font-bold text-[var(--text)]">{{ expense.name }}</td>
                <td class="py-2.5 px-4 text-[var(--muted)]">{{ expense.notes || '—' }}</td>
                <td class="py-2.5 px-4 text-end font-mono font-bold text-[var(--text)]">
                  {{ formatMoney(expense.amount) }} {{ $t('common.currency') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Attachment -->
      <PurchaseAttachmentBox
        resource-prefix="purchase-invoices"
        ns="purchaseInvoices"
        :document="invoice"
      />

      <!-- Notes Banner -->
      <div
        v-if="invoice.notes"
        class="rounded-2xl border border-[var(--border)] bg-[var(--bg)]/60 p-4 text-xs leading-relaxed text-[var(--text)]"
      >
        <div class="flex items-center gap-1.5 font-bold text-[var(--muted)] mb-1">
          <FileText class="h-4 w-4" />
          <span>{{ $t('purchaseInvoices.notes') }}</span>
        </div>
        <p class="whitespace-pre-line text-xs font-medium">{{ invoice.notes }}</p>
      </div>

      <!-- Financial Totals Summary -->
      <div class="flex justify-end">
        <div class="w-full sm:w-96 space-y-2 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center justify-between text-xs">
            <span class="font-bold text-[var(--muted)]">{{ $t('purchaseInvoices.subtotal') }}:</span>
            <span class="font-bold font-mono text-[var(--text)]">{{ formatMoney(invoice.subtotal) }} {{ $t('common.currency') }}</span>
          </div>

          <div
            v-if="itemDiscountsTotal > 0"
            class="flex items-center justify-between text-xs text-[var(--muted)]"
          >
            <span class="font-bold">{{ $t('purchaseInvoices.itemDiscounts') }}:</span>
            <span class="font-bold font-mono">- {{ formatMoney(itemDiscountsTotal) }} {{ $t('common.currency') }}</span>
          </div>

          <div
            v-if="Number(invoice.discount_amount) > 0"
            class="flex items-center justify-between text-xs text-[var(--muted)]"
          >
            <span class="font-bold">
              {{ $t('purchaseInvoices.invoiceDiscount') }}
              <span v-if="invoice.discount_type === 'percentage'" class="text-[11px] font-normal">({{ Number(invoice.discount_value) }}%)</span>:
            </span>
            <span class="font-bold font-mono">- {{ formatMoney(invoice.discount_amount) }} {{ $t('common.currency') }}</span>
          </div>

          <div
            v-if="Number(invoice.additional_expenses_total) > 0"
            class="flex items-center justify-between text-xs text-blue-600 dark:text-blue-400"
          >
            <span class="font-bold">{{ $t('purchaseInvoices.expensesTotal') }}:</span>
            <span class="font-bold font-mono">+ {{ formatMoney(invoice.additional_expenses_total) }} {{ $t('common.currency') }}</span>
          </div>

          <div class="border-t border-[var(--border)] my-1"></div>

          <div class="flex items-baseline justify-between pt-1">
            <span class="text-base font-extrabold text-[var(--text)]">{{ $t('purchaseInvoices.total') }}</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl font-black font-mono text-[var(--text)]">{{ formatMoney(invoice.total) }}</span>
              <span class="text-xs font-bold text-[var(--muted)]">{{ $t('common.currency') }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppDrawer>
</template>

<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import PurchaseAttachmentBox from '../../components/purchases/PurchaseAttachmentBox.vue';
import {
  FileText,
  Truck,
  Calendar,
  PackageCheck,
  Printer,
  Edit2,
  XCircle,
  CheckCircle2,
  Lock,
  ExternalLink,
  Banknote,
  X,
} from 'lucide-vue-next';

const props = defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
  actionLoading: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'edit', 'post', 'cancel', 'pay']);
const router = useRouter();
const { t } = useI18n();

const isLockedByPosting = computed(() => {
  const grStatus = props.invoice?.goods_receipt?.status;
  return grStatus === 'posted' || grStatus === 'reversed';
});

const canModify = computed(() => {
  return props.invoice?.status === 'draft' && !isLockedByPosting.value;
});

const canPay = computed(() => {
  return props.invoice?.status === 'posted' && Number(props.invoice?.outstanding_amount ?? 0) > 0;
});

const itemDiscountsTotal = computed(() => {
  return (props.invoice?.items || []).reduce((sum, i) => sum + (Number(i.discount_amount) || 0), 0);
});

function statusLabel(status) {
  if (!status) return '—';
  return t(`purchaseInvoices.statuses.${status}`) || status;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft':
      return 'bg-amber-500/10 text-amber-700 border border-amber-500/20 dark:text-amber-400';
    case 'posted':
      return 'bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 dark:text-emerald-400';
    case 'cancelled':
      return 'bg-rose-500/10 text-rose-700 border border-rose-500/20 dark:text-rose-400';
    default:
      return 'bg-gray-500/10 text-gray-700 border border-gray-500/20 dark:text-gray-400';
  }
}

function grStatusLabel(status) {
  if (!status) return '—';
  return t(`purchaseInvoices.grStatuses.${status}`) || status;
}

function grStatusBadgeClass(status) {
  switch (status) {
    case 'posted':
      return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    case 'cancelled':
    case 'rejected':
      return 'bg-rose-500/10 text-rose-700 dark:text-rose-400';
    case 'pending_approval':
    case 'approved':
      return 'bg-blue-500/10 text-blue-700 dark:text-blue-400';
    default:
      return 'bg-amber-500/10 text-amber-700 dark:text-amber-400';
  }
}

function formatMoney(amount) {
  return (Number(amount) || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatQty(qty) {
  const num = Number(qty) || 0;
  return Number.isInteger(num) ? num.toString() : num.toFixed(3);
}

function formatDateOnly(val) {
  if (!val) return t('purchaseInvoices.notSpecified');
  return String(val).substring(0, 10);
}

function printInvoice() {
  window.print();
}

function goToGoodsReceipt() {
  const gr = props.invoice?.goods_receipt;
  if (!gr?.id) return;
  emit('close');
  router.push({
    path: '/inventory/goods-receipt',
    query: {
      search: gr.document_number || undefined,
      open: String(gr.id),
    },
  });
}

function goToPurchaseOrder() {
  const po = props.invoice?.purchase_order;
  if (!po?.id) return;
  emit('close');
  router.push({
    name: 'purchase-orders',
    query: {
      open: String(po.id),
    },
  });
}
</script>
