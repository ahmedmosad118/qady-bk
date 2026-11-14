<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" dir="rtl">
    <div class="w-full max-w-[540px] max-h-[92vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div>
          <h3 class="text-xl font-black">إغلاق الوردية</h3>
          <p class="text-xs text-[var(--muted)]">وردية رقم: {{ summary?.shift?.shift_number || '' }}</p>
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
        <p class="text-xs font-bold">جاري تحميل تقرير الوردية من الخادم...</p>
      </div>

      <div v-else class="space-y-2 mb-4 text-xs font-semibold">
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">عدد فواتير البيع</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ summary?.sales_count || 0 }}</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">المبيعات النقدية</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.cash_sales) }} ج.م</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">مبيعات الفيزا / البطاقات</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.visa_sales) }} ج.م</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">مبيعات التحويل البنكي</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.bank_transfer_sales) }} ج.م</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)] text-red-600">
          <span>المرتجعات النقدية المستردة</span>
          <b class="font-bold text-sm">-{{ formatMoney(summary?.cash_returns) }} ج.م</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)] text-red-600">
          <span>المصروفات النقدية</span>
          <b class="font-bold text-sm">-{{ formatMoney(summary?.expenses) }} ج.م</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)] text-red-600">
          <span>السحوبات النقدية</span>
          <b class="font-bold text-sm">-{{ formatMoney(summary?.withdrawals) }} ج.م</b>
        </div>
        <div class="flex justify-between py-2 border-b border-[var(--border)]">
          <span class="text-[var(--muted)]">الرصيد الافتتاحي</span>
          <b class="font-bold text-[var(--text)] text-sm">{{ formatMoney(summary?.opening_balance) }} ج.م</b>
        </div>
        <div class="flex justify-between py-3 border-y-2 border-[var(--border)] bg-[var(--bar)] px-3 rounded-xl text-sm font-black">
          <span>المبلغ المتوقع في الدرج</span>
          <span class="text-[var(--crimson)]">{{ formatMoney(expectedCash) }} ج.م</span>
        </div>
      </div>

      <!-- Actual Cash Input -->
      <div class="mb-4">
        <label class="block text-sm font-bold mb-1 text-[var(--text)]">المبلغ الفعلي الموجود في الدرج (ج.م)</label>
        <input
          v-model.number="actualCash"
          type="number"
          step="any"
          required
          class="w-full h-12 text-center text-2xl font-black rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
        />
      </div>

      <!-- Realtime Difference -->
      <div class="flex items-center justify-between p-3 rounded-xl border mb-4 font-black text-sm" :class="diffClass">
        <span>فارق النقدية (الفعلي - المتوقع):</span>
        <span>{{ diffText }}</span>
      </div>

      <!-- Closing Notes -->
      <div class="mb-5">
        <label class="block text-xs font-semibold mb-1 text-[var(--muted)]">ملاحظات الإغلاق (اختياري)</label>
        <input
          v-model="notes"
          type="text"
          placeholder="ملاحظات حول الوردية أو أسباب الفروقات إن وجدت..."
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
          رجوع
        </button>
        <button
          type="button"
          class="flex-1 h-12 rounded-xl bg-[var(--crimson)] text-white font-bold hover:opacity-90 transition flex items-center justify-center gap-2 disabled:opacity-50"
          :disabled="submitting"
          @click="submitClose"
        >
          <span v-if="submitting" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
          <span>تأكيد إغلاق الوردية</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
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

const expectedCash = computed(() => {
  return Number(props.summary?.expected_cash || 0);
});

const difference = computed(() => {
  return Math.round((Number(actualCash.value || 0) - expectedCash.value) * 100) / 100;
});

const diffClass = computed(() => {
  const d = difference.value;
  if (d === 0) return 'bg-emerald-50 text-emerald-800 border-emerald-200';
  if (d > 0) return 'bg-blue-50 text-blue-800 border-blue-200';
  return 'bg-red-50 text-red-800 border-red-200';
});

const diffText = computed(() => {
  const d = difference.value;
  if (d === 0) return '0.00 ج.م (متطابق تماماً)';
  if (d > 0) return `+${formatMoney(d)} ج.م (زيادة في النقدية)`;
  return `${formatMoney(d)} ج.م (عجز في النقدية)`;
});

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function submitClose() {
  emit('confirm', {
    actual_cash: Number(actualCash.value || 0),
    notes: notes.value,
  });
}

onMounted(() => {
  if (props.summary?.expected_cash) {
    actualCash.value = Number(props.summary.expected_cash);
  }
});
</script>
