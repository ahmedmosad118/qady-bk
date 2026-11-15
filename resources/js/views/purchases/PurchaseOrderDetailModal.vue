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
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--crimson)]/20 bg-[var(--crimson-soft)] text-[var(--crimson)] shadow-xs"
          >
            <ShoppingBag class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono" dir="ltr">
                {{ order?.order_number || '—' }}
              </h2>

              <!-- Status Badge -->
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="statusBadgeClass(order?.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel(order?.status) }}
              </span>
            </div>

            <!-- Subtitle -->
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span>{{ $t('purchaseOrders.supplier') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ order?.supplier?.name || '—' }}</span>
              <span>•</span>
              <span class="font-mono">{{ formatDateOnly(order?.order_date) }}</span>
            </div>
          </div>
        </div>

        <!-- Header Actions: Close Button -->
        <div class="flex items-center gap-1">
          <button
            type="button"
            class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            :title="$t('common.close')"
            @click="emit('close')"
          >
            <X class="h-5 w-5" />
          </button>
        </div>
      </div>
    </template>

    <!-- Top Sticky Action Bar (All actions placed at the TOP) -->
    <template #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span class="text-xs font-bold text-[var(--muted)]">
            {{ $t('common.actions') }}:
          </span>

          <!-- Status Indicator if converted / cancelled -->
          <span
            v-if="order?.status === 'converted'"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>تم التحويل لفاتورة</span>
          </span>

          <span
            v-else-if="order?.status === 'cancelled'"
            class="inline-flex items-center gap-1 rounded-lg bg-rose-500/10 px-2.5 py-1 text-xs font-bold text-rose-700 dark:text-rose-300"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>أمر شراء ملغي</span>
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <!-- Print -->
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
            @click="printOrder"
          >
            <Printer class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseOrders.print') }}</span>
          </button>

          <!-- Edit Order (Draft only) -->
          <button
            v-if="order?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
            :disabled="actionLoading"
            @click="emit('edit', order)"
          >
            <Edit2 class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseOrders.edit') }}</span>
          </button>

          <!-- Cancel Order (Draft only) -->
          <button
            v-if="order?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-50 px-3.5 py-1.5 text-xs font-bold text-rose-700 transition-colors hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400"
            :disabled="actionLoading"
            @click="emit('cancel', order)"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseOrders.cancel') }}</span>
          </button>

          <!-- Confirm & Convert (Draft only) -->
          <button
            v-if="order?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-emerald-700 disabled:opacity-50"
            :disabled="actionLoading"
            @click="emit('confirm', order)"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseOrders.confirm') }}</span>
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Content -->
    <div v-if="order" class="space-y-6 text-sm text-[var(--text)]">
      <!-- Quick Information Cards -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <!-- Supplier Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Truck class="h-4 w-4 text-[var(--crimson)]" />
            <span>{{ $t('purchaseOrders.supplier') }}</span>
          </div>
          <p class="mt-2 text-base font-extrabold text-[var(--text)] truncate">{{ order.supplier?.name || '—' }}</p>
          <p v-if="order.supplier?.phone || order.supplier?.mobile" class="mt-1 text-xs text-[var(--muted)]" dir="ltr">
            {{ order.supplier?.mobile || order.supplier?.phone }}
          </p>
          <p v-if="order.supplier?.tax_number" class="mt-0.5 text-xs text-[var(--muted)]">
            الرقم الضريبي: {{ order.supplier.tax_number }}
          </p>
        </div>

        <!-- Dates Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Calendar class="h-4 w-4 text-[var(--crimson)]" />
            <span>التواريخ</span>
          </div>
          <div class="mt-2 space-y-1">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseOrders.orderDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(order.order_date) }}</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseOrders.deliveryDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(order.expected_delivery_date) }}</span>
            </div>
          </div>
        </div>

        <!-- Audit & Tracking Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <UserCheck class="h-4 w-4 text-[var(--crimson)]" />
            <span>المسؤول والاعتماد</span>
          </div>
          <div class="mt-2 space-y-1">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseOrders.creator') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ order.creator?.name || '—' }}</span>
            </div>
            <div v-if="order.confirmer" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseOrders.confirmer') }}:</span>
              <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ order.confirmer?.name }}</span>
            </div>
            <div v-if="order.confirmed_at" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseOrders.confirmedAt') }}:</span>
              <span class="font-medium text-[var(--muted)]">{{ formatDateTime(order.confirmed_at) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Line Items Section -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-extrabold text-[var(--text)] flex items-center gap-2">
            <span>{{ $t('purchaseOrders.items') }}</span>
            <span class="rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">
              {{ order.items?.length || 0 }}
            </span>
          </h3>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <table class="w-full text-right text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="py-3 px-4">#</th>
                <th class="py-3 px-4">{{ $t('purchaseOrders.product') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseOrders.quantity') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseOrders.unitPrice') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseOrders.discount') }}</th>
                <th class="py-3 px-4 text-left">{{ $t('purchaseOrders.lineTotal') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr
                v-for="(item, idx) in order.items"
                :key="item.id || idx"
                class="transition-colors hover:bg-[var(--bg)]/50"
              >
                <td class="py-3 px-4 text-[var(--muted)] font-mono">{{ idx + 1 }}</td>
                <td class="py-3 px-4">
                  <div class="font-bold text-[var(--text)]">
                    {{ item.product_snapshot?.name || item.product?.name || '—' }}
                  </div>
                  <div class="text-[11px] text-[var(--muted)] font-mono" dir="ltr">
                    {{ item.product_snapshot?.sku || item.product?.sku }}
                  </div>
                </td>
                <td class="py-3 px-4 text-center font-bold text-[var(--text)]">
                  {{ formatQty(item.quantity) }}
                  <span class="text-[10px] font-normal text-[var(--muted)]">
                    {{ item.product_snapshot?.unit_name || 'قطعة' }}
                  </span>
                </td>
                <td class="py-3 px-4 text-center font-mono">
                  {{ formatMoney(item.unit_price) }}
                </td>
                <td class="py-3 px-4 text-center">
                  <span v-if="Number(item.discount_amount) > 0" class="font-bold text-amber-600 dark:text-amber-400">
                    -{{ formatMoney(item.discount_amount) }}
                    <span v-if="item.discount_type === 'percentage'" class="text-[10px]">
                      ({{ Number(item.discount_value) }}%)
                    </span>
                  </span>
                  <span v-else class="text-[var(--muted)]">—</span>
                </td>
                <td class="py-3 px-4 text-left font-bold font-mono text-[var(--text)]">
                  {{ formatMoney(item.line_total) }} ج.م
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Notes Banner (Moved to end) -->
      <div
        v-if="order.notes"
        class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 text-xs leading-relaxed text-[var(--text)]"
      >
        <div class="flex items-center gap-1.5 font-bold text-amber-700 dark:text-amber-400 mb-1">
          <FileText class="h-4 w-4" />
          <span>{{ $t('purchaseOrders.notes') }}</span>
        </div>
        <p class="whitespace-pre-line text-xs font-medium">{{ order.notes }}</p>
      </div>

      <!-- Financial Totals Summary -->
      <div class="flex justify-end">
        <div class="w-full sm:w-88 space-y-2.5 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center justify-between text-xs">
            <span class="font-bold text-[var(--muted)]">{{ $t('purchaseOrders.subtotal') }}:</span>
            <span class="font-bold font-mono text-[var(--text)]">{{ formatMoney(order.subtotal) }} ج.م</span>
          </div>

          <div
            v-if="Number(order.discount_amount) > 0"
            class="flex items-center justify-between text-xs text-rose-600 dark:text-rose-400"
          >
            <span class="font-bold">
              {{ $t('purchaseOrders.orderDiscount') }}
              <span v-if="order.discount_type === 'percentage'" class="text-[11px] font-normal">({{ Number(order.discount_value) }}%)</span>:
            </span>
            <span class="font-bold font-mono">- {{ formatMoney(order.discount_amount) }} ج.م</span>
          </div>

          <div class="border-t border-[var(--border)] my-1"></div>

          <div class="flex items-baseline justify-between pt-1">
            <span class="text-base font-extrabold text-[var(--text)]">{{ $t('purchaseOrders.total') }}</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl font-black font-mono text-[var(--text)]">{{ formatMoney(order.total) }}</span>
              <span class="text-xs font-bold text-[var(--muted)]">ج.م</span>
            </div>
          </div>

          <!-- Total Savings Pill -->
          <div
            v-if="totalOrderSavings > 0"
            class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300 border border-emerald-200/50 dark:border-emerald-800/30"
          >
            <span>إجمالي التوفير</span>
            <span class="font-mono">{{ formatMoney(totalOrderSavings) }} ج.م</span>
          </div>
        </div>
      </div>
    </div>
  </AppDrawer>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import {
  ShoppingBag,
  Truck,
  Calendar,
  UserCheck,
  FileText,
  Printer,
  Edit2,
  CheckCircle2,
  XCircle,
  X,
} from 'lucide-vue-next';

const props = defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
  actionLoading: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'edit', 'confirm', 'cancel']);
const { t } = useI18n();

const totalOrderSavings = computed(() => {
  if (!props.order) return 0;
  const itemsDisc = (props.order.items || []).reduce((sum, i) => sum + (Number(i.discount_amount) || 0), 0);
  const orderDisc = Number(props.order.discount_amount) || 0;
  return itemsDisc + orderDisc;
});

function statusLabel(status) {
  if (!status) return '—';
  return t(`purchaseOrders.statuses.${status}`) || status;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft':
      return 'bg-amber-500/10 text-amber-700 border border-amber-500/20 dark:text-amber-400';
    case 'converted':
      return 'bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 dark:text-emerald-400';
    case 'cancelled':
      return 'bg-rose-500/10 text-rose-700 border border-rose-500/20 dark:text-rose-400';
    default:
      return 'bg-gray-500/10 text-gray-700 border border-gray-500/20 dark:text-gray-400';
  }
}

function formatMoney(amount) {
  const num = Number(amount) || 0;
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatQty(qty) {
  const num = Number(qty) || 0;
  return Number.isInteger(num) ? num.toString() : num.toFixed(3);
}

function formatDateOnly(val) {
  if (!val) return 'غير محدد';
  return String(val).substring(0, 10);
}

function formatDateTime(isoStr) {
  if (!isoStr) return '—';
  const d = new Date(isoStr);
  return d.toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function printOrder() {
  window.print();
}
</script>
