<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs select-none"
    dir="rtl"
    @keydown.esc="emit('close')"
  >
    <div
      class="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-3xl bg-[var(--card)] shadow-2xl border border-[var(--border)] text-[var(--text)] overflow-hidden"
    >
      <!-- Modal Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--border)] bg-[var(--card)] flex-none">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-amber-500/10 text-amber-600 flex items-center justify-center font-bold">
            <Clock class="w-5 h-5" />
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h3 class="font-extrabold text-lg leading-tight">تفاصيل وردية البيع</h3>
              <span v-if="shift" class="font-mono text-sm px-2.5 py-0.5 rounded-lg bg-[var(--bar)] font-black text-[var(--crimson)]">
                {{ shift.shift_number }}
              </span>
              <span
                v-if="shift"
                class="px-2.5 py-0.5 rounded-full text-xs font-bold"
                :class="shift.closed_at ? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30'"
              >
                {{ shift.closed_at ? 'مغلقة' : 'مفتوحة حالياً' }}
              </span>
            </div>
            <p class="text-xs text-[var(--muted)] mt-0.5">
              مكتبة القاضي
            </p>
          </div>
        </div>

        <button
          type="button"
          class="w-9 h-9 rounded-xl border border-[var(--border)] text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)] transition flex items-center justify-center"
          @click="emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Navigation Tabs -->
      <div class="flex items-center gap-1 px-6 pt-3 border-b border-[var(--border)] bg-[var(--card)] flex-none overflow-x-auto">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          :class="[
            'px-4 py-2.5 text-xs font-bold rounded-t-xl transition-all border-b-2 flex items-center gap-1.5 whitespace-nowrap cursor-pointer',
            activeTab === tab.id
              ? 'border-[var(--crimson)] text-[var(--crimson)] bg-[var(--crimson)]/5'
              : 'border-transparent text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'
          ]"
          @click="activeTab = tab.id"
        >
          <component :is="tab.icon" class="w-4 h-4" />
          <span>{{ tab.label }}</span>
          <span
            v-if="tab.badge !== undefined"
            class="px-1.5 py-0.2 rounded-full text-[10px] font-black"
            :class="activeTab === tab.id ? 'bg-[var(--crimson)] text-white' : 'bg-[var(--border)] text-[var(--muted)]'"
          >
            {{ tab.badge }}
          </span>
        </button>
      </div>

      <!-- Modal Body (Scrollable) -->
      <div class="flex-1 overflow-y-auto p-6 min-h-0 bg-[var(--bg)]/40">
        <!-- Loading State -->
        <div v-if="loading" class="py-16 text-center">
          <div class="inline-block w-8 h-8 border-3 border-[var(--crimson)] border-t-transparent rounded-full animate-spin"></div>
          <p class="text-xs font-bold text-[var(--muted)] mt-3">جاري تحميل بيانات الوردية...</p>
        </div>

        <div v-else-if="summary" class="space-y-6">
          <!-- Tab 1: Shift Information & Summary -->
          <div v-if="activeTab === 'info'" class="space-y-5">
            <!-- Key Financial Highlights Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">الرصيد الافتتاحي</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block font-mono">
                  {{ Number(summary.opening_balance || 0).toFixed(2) }} <span class="text-xs font-sans font-medium">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">المبيعات النقدية</span>
                <b class="text-base font-extrabold text-emerald-600 mt-1 block font-mono">
                  +{{ Number(summary.cash_sales || 0).toFixed(2) }} <span class="text-xs font-sans font-medium">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">المصروفات والسحوبات</span>
                <b class="text-base font-extrabold text-amber-600 mt-1 block font-mono">
                  -{{ (Number(summary.expenses || 0) + Number(summary.withdrawals || 0)).toFixed(2) }} <span class="text-xs font-sans font-medium">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">المرتجع النقدي</span>
                <b class="text-base font-extrabold text-red-600 mt-1 block font-mono">
                  -{{ Number(summary.cash_returns || 0).toFixed(2) }} <span class="text-xs font-sans font-medium">ج.م</span>
                </b>
              </div>
            </div>

            <!-- Cash Drawer Balancing Box (Only for closed shifts, or preview for open) -->
            <div class="p-5 rounded-2xl border bg-[var(--card)] space-y-4" :class="shift.closed_at ? 'border-[var(--border)]' : 'border-dashed border-amber-300 bg-amber-50/30 dark:bg-amber-950/10'">
              <div class="flex items-center justify-between pb-3 border-b border-[var(--border)]">
                <div class="flex items-center gap-2">
                  <Coins class="w-5 h-5 text-amber-600" />
                  <h4 class="font-extrabold text-sm text-[var(--text)]">مطابقة وموازنة الدرج النقدي</h4>
                </div>
                <span v-if="!shift.closed_at" class="text-xs font-bold text-amber-600 bg-amber-100 dark:bg-amber-950/50 px-2.5 py-0.5 rounded-full">
                  الوردية جارية (الرصيد المتوقع تقديري)
                </span>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-3 rounded-xl bg-[var(--bar)]/50">
                  <span class="text-xs text-[var(--muted)] block">النقدية المتوقعة بالدرج</span>
                  <b class="text-lg font-black font-mono text-[var(--text)] mt-1 block">
                    {{ Number(summary.expected_cash || 0).toFixed(2) }} ج.م
                  </b>
                </div>

                <div class="p-3 rounded-xl bg-[var(--bar)]/50">
                  <span class="text-xs text-[var(--muted)] block">النقدية الفعلية عند الإغلاق</span>
                  <b v-if="shift.closed_at" class="text-lg font-black font-mono text-[var(--text)] mt-1 block">
                    {{ Number(summary.actual_cash || 0).toFixed(2) }} ج.م
                  </b>
                  <span v-else class="text-xs font-bold text-[var(--muted)] mt-1 block">
                    لم تُغلق بعد
                  </span>
                </div>

                <!-- Difference Presentation -->
                <div
                  v-if="shift.closed_at"
                  class="p-3 rounded-xl"
                  :class="diffClass"
                >
                  <span class="text-xs font-bold block">الفارق (عجز / فائض)</span>
                  <div class="flex items-center gap-1.5 mt-1">
                    <b class="text-lg font-black font-mono">
                      {{ diffFormatted }}
                    </b>
                    <span class="text-xs font-bold">({{ diffLabel }})</span>
                  </div>
                </div>

                <div v-else class="p-3 rounded-xl bg-[var(--bar)]/50 text-[var(--muted)]">
                  <span class="text-xs block">الفارق النهائي</span>
                  <span class="text-xs font-medium mt-1 block">يُحسب عند إغلاق الوردية وجرد الدرج</span>
                </div>
              </div>
            </div>

            <!-- Detailed Shift Metadata Table -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <div class="px-4 py-3 bg-[var(--bar)]/50 border-b border-[var(--border)]">
                <h4 class="text-xs font-black text-[var(--text)]">بيانات وسجل الوردية</h4>
              </div>
              <div class="divide-y divide-[var(--border)] text-xs">
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">رقم الوردية:</span>
                  <span class="col-span-2 font-mono font-bold">{{ shift.shift_number }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">المستخدم الفاتح للوردية:</span>
                  <span class="col-span-2 font-bold">{{ shift.opened_by?.name || shift.opened_by_user?.name || 'غير معروف' }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">تاريخ ووقت الفتح:</span>
                  <span class="col-span-2 font-mono font-bold" dir="ltr">{{ formatDateTime(shift.opened_at) }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">المستخدم المغلق للوردية:</span>
                  <span class="col-span-2 font-bold">
                    {{ shift.closed_at ? (shift.closed_by?.name || shift.closed_by_user?.name || 'نفس الكاشير') : '—' }}
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">تاريخ ووقت الإغلاق:</span>
                  <span class="col-span-2 font-mono font-bold" dir="ltr">
                    {{ shift.closed_at ? formatDateTime(shift.closed_at) : 'الوردية ما زالت مفتوحة' }}
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">مبيعات الدفع الإلكتروني:</span>
                  <span class="col-span-2 font-mono text-[var(--text)]">
                    فيزا: <b>{{ Number(summary.visa_sales || 0).toFixed(2) }} ج.م</b> · تحويل بنكي: <b>{{ Number(summary.bank_transfer_sales || 0).toFixed(2) }} ج.م</b>
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">ملاحظات الوردية:</span>
                  <span class="col-span-2 text-[var(--text)]">{{ shift.notes || 'لا توجد ملاحظات مسجلة' }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Tab 2: Shift Transactions -->
          <div v-else-if="activeTab === 'transactions'" class="space-y-3">
            <div
              v-if="shift.transactions && shift.transactions.length > 0"
              class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
            >
              <table class="w-full text-xs text-start">
                <thead>
                  <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                    <th class="px-3.5 py-2.5 text-start">#</th>
                    <th class="px-3.5 py-2.5 text-start">نوع الحركة</th>
                    <th class="px-3.5 py-2.5 text-start">البيان / الوصف</th>
                    <th class="px-3.5 py-2.5 text-start">مرجع العملية</th>
                    <th class="px-3.5 py-2.5 text-start">المستخدم</th>
                    <th class="px-3.5 py-2.5 text-start">التاريخ والوقت</th>
                    <th class="px-3.5 py-2.5 text-end">المبلغ</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr
                    v-for="(tx, idx) in shift.transactions"
                    :key="tx.id || idx"
                    class="hover:bg-[var(--bg)]/50 transition-colors"
                  >
                    <td class="px-3.5 py-3 font-mono text-[var(--muted)]">{{ idx + 1 }}</td>
                    <td class="px-3.5 py-3">
                      <span
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold"
                        :class="txTypeBadge(tx.type)"
                      >
                        {{ txTypeLabel(tx.type) }}
                      </span>
                    </td>
                    <td class="px-3.5 py-3">
                      <b class="text-[var(--text)] block">{{ tx.name || 'حركة نقدية' }}</b>
                      <span v-if="tx.description" class="text-[11px] text-[var(--muted)] block">{{ tx.description }}</span>
                    </td>
                    <td class="px-3.5 py-3 font-mono text-[11px]">
                      <span v-if="tx.sale?.invoice_number" class="text-[var(--crimson)] font-bold">
                        فاتورة: {{ tx.sale.invoice_number }}
                      </span>
                      <span v-else-if="tx.return?.return_number" class="text-amber-600 font-bold">
                        مرتجع: {{ tx.return.return_number }}
                      </span>
                      <span v-else class="text-[var(--muted)]">—</span>
                    </td>
                    <td class="px-3.5 py-3 text-[var(--muted)]">{{ tx.creator?.name || 'الكاشير' }}</td>
                    <td class="px-3.5 py-3 font-mono text-[11px] text-[var(--muted)]" dir="ltr">
                      {{ formatDateTime(tx.created_at) }}
                    </td>
                    <td class="px-3.5 py-3 text-end font-mono font-black" :class="txAmountClass(tx.type)">
                      {{ txSign(tx.type) }}{{ Number(tx.amount || 0).toFixed(2) }} ج.م
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center text-xs text-[var(--muted)]">
              لا توجد حركات نقدية مسجلة لهذه الوردية حتى الآن.
            </div>
          </div>

          <!-- Tab 3: Shift Sales -->
          <div v-else-if="activeTab === 'sales'" class="space-y-3">
            <div
              v-if="shift.sales && shift.sales.length > 0"
              class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
            >
              <table class="w-full text-xs text-start">
                <thead>
                  <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                    <th class="px-3.5 py-2.5 text-start">رقم الفاتورة</th>
                    <th class="px-3.5 py-2.5 text-start">التاريخ والوقت</th>
                    <th class="px-3.5 py-2.5 text-start">العميل</th>
                    <th class="px-3.5 py-2.5 text-start">طريقة الدفع</th>
                    <th class="px-3.5 py-2.5 text-start">الكاشير</th>
                    <th class="px-3.5 py-2.5 text-end">الإجمالي</th>
                    <th class="px-3.5 py-2.5 text-center">إجراء</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr
                    v-for="s in shift.sales"
                    :key="s.id"
                    class="hover:bg-[var(--bg)]/50 transition-colors"
                  >
                    <td class="px-3.5 py-3 font-mono font-bold text-[var(--crimson)]">
                      <button
                        type="button"
                        class="hover:underline cursor-pointer"
                        @click="emit('open-sale', s.id)"
                      >
                        {{ s.invoice_number }}
                      </button>
                    </td>
                    <td class="px-3.5 py-3 font-mono text-[11px] text-[var(--muted)]" dir="ltr">
                      {{ formatDateTime(s.sold_at) }}
                    </td>
                    <td class="px-3.5 py-3">
                      <span v-if="s.customer" class="font-bold text-[var(--text)]">{{ s.customer.name }}</span>
                      <span v-else class="text-[var(--muted)]">عميل نقدي</span>
                    </td>
                    <td class="px-3.5 py-3">
                      <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-[var(--bar)] text-[var(--text)]">
                        {{ s.payment_method?.name || 'نقدي' }}
                      </span>
                    </td>
                    <td class="px-3.5 py-3 text-[var(--muted)]">{{ s.creator?.name || '—' }}</td>
                    <td class="px-3.5 py-3 text-end font-mono font-black text-[var(--crimson)]">
                      {{ Number(s.grand_total || 0).toFixed(2) }} ج.م
                    </td>
                    <td class="px-3.5 py-3 text-center">
                      <button
                        type="button"
                        class="h-7 px-2.5 rounded-lg border border-[var(--border)] bg-[var(--card)] hover:bg-[var(--bg)] text-[11px] font-bold transition inline-flex items-center gap-1 cursor-pointer"
                        @click="emit('open-sale', s.id)"
                      >
                        <ExternalLink class="w-3 h-3" />
                        <span>تفاصيل</span>
                      </button>
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="bg-[var(--bar)]/50 font-bold border-t border-[var(--border)]">
                    <td colspan="5" class="px-3.5 py-3 text-start">
                      إجمالي مبيعات الوردية ({{ shift.sales.length }} فاتورة)
                    </td>
                    <td class="px-3.5 py-3 text-end font-mono font-black text-[var(--crimson)]">
                      {{ Number(summary.total_sales_amount || shiftSalesTotal).toFixed(2) }} ج.م
                    </td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center text-xs text-[var(--muted)]">
              لا توجد فواتير بيع مسجلة ضمن هذه الوردية.
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="flex items-center justify-between px-6 py-3.5 border-t border-[var(--border)] bg-[var(--card)] flex-none">
        <span class="text-xs text-[var(--muted)]">
          مكتبة القاضي · نظام إدارة الورديات ونقاط البيع
        </span>

        <button
          type="button"
          class="h-9 px-4 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:bg-[var(--bg)] transition"
          @click="emit('close')"
        >
          إغلاق
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  Clock,
  X,
  FileText,
  ArrowLeftRight,
  Receipt,
  Coins,
  ExternalLink,
} from 'lucide-vue-next';
import api from '../../../services/api';

const props = defineProps({
  shiftId: {
    type: [Number, String],
    required: true,
  },
  initialShift: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'open-sale']);

const summary = ref(null);
const shift = computed(() => summary.value?.shift || props.initialShift);
const loading = ref(true);
const activeTab = ref('info');

const tabs = computed(() => [
  { id: 'info', label: 'بيانات وملخص الوردية', icon: FileText },
  { id: 'transactions', label: 'الحركات النقدية (الدرج)', icon: ArrowLeftRight, badge: shift.value?.transactions?.length },
  { id: 'sales', label: 'فواتير البيع بالوردية', icon: Receipt, badge: shift.value?.sales?.length },
]);

const shiftSalesTotal = computed(() => {
  if (!shift.value?.sales) return 0;
  return shift.value.sales.reduce((acc, s) => acc + Number(s.grand_total || 0), 0);
});

// Difference calculations:
// Positive difference: surplus (فائض)
// Negative difference: shortage (عجز)
// Zero difference: balanced (مطابق)
const diffVal = computed(() => {
  if (!shift.value?.closed_at) return null;
  return Number(summary.value?.difference ?? shift.value?.difference ?? 0);
});

const diffLabel = computed(() => {
  const d = diffVal.value;
  if (d === null) return '';
  if (d > 0.001) return 'فائض بالدرج';
  if (d < -0.001) return 'عجز بالدرج';
  return 'مطابق تماماً';
});

const diffFormatted = computed(() => {
  const d = diffVal.value;
  if (d === null) return '—';
  const prefix = d > 0 ? '+' : '';
  return `${prefix}${d.toFixed(2)} ج.م`;
});

const diffClass = computed(() => {
  const d = diffVal.value;
  if (d === null) return 'bg-[var(--bar)] text-[var(--muted)]';
  if (d > 0.001) return 'bg-emerald-50 text-emerald-800 border border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-200';
  if (d < -0.001) return 'bg-red-50 text-red-800 border border-red-300 dark:bg-red-950/40 dark:text-red-200';
  return 'bg-zinc-100 text-zinc-800 border border-zinc-300 dark:bg-zinc-800 dark:text-zinc-200';
});

function txTypeLabel(type) {
  switch (type) {
    case 'cash_sale': return 'مبيعات نقدية';
    case 'cash_return': return 'مرتجع نقدي';
    case 'expense': return 'مصروف نقدي';
    case 'withdrawal': return 'سحب عهدة';
    default: return type;
  }
}

function txTypeBadge(type) {
  switch (type) {
    case 'cash_sale': return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30';
    case 'cash_return': return 'bg-red-50 text-red-700 dark:bg-red-950/30';
    case 'expense': return 'bg-amber-50 text-amber-700 dark:bg-amber-950/30';
    case 'withdrawal': return 'bg-blue-50 text-blue-700 dark:bg-blue-950/30';
    default: return 'bg-[var(--bar)] text-[var(--muted)]';
  }
}

function txSign(type) {
  return type === 'cash_sale' ? '+' : '-';
}

function txAmountClass(type) {
  return type === 'cash_sale' ? 'text-emerald-600' : 'text-amber-600';
}

async function fetchShiftDetails() {
  loading.value = true;
  try {
    const res = await api.get(`/pos/shifts/${props.shiftId}`);
    if (res.data?.success) {
      summary.value = res.data.data;
    }
  } catch (err) {
    console.error('Failed to load shift details:', err);
  } finally {
    loading.value = false;
  }
}

function formatDateTime(val) {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

onMounted(() => {
  fetchShiftDetails();
});
</script>
