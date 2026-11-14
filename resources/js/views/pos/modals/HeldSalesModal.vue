<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" dir="rtl">
    <div class="w-full max-w-[620px] max-h-[90vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div>
          <h3 class="text-xl font-black">الفواتير المعلّقة</h3>
          <p class="text-xs text-[var(--muted)]">محفوظة محليًا في متصفحك (Local Storage) دون حجز المخزون</p>
        </div>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Held Sales List -->
      <div v-if="heldSales.length > 0" class="space-y-3 mb-6">
        <div
          v-for="held in heldSales"
          :key="held.id"
          class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-4 rounded-2xl border border-[var(--border)] bg-[var(--bg)] hover:border-[var(--crimson)] transition"
        >
          <div class="space-y-1">
            <div class="flex items-center gap-2">
              <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-500"></span>
              <b class="text-base font-black">{{ held.reference_note }}</b>
              <span class="text-xs font-bold text-[var(--crimson)] bg-[var(--soft)] px-2 py-0.5 rounded-md">
                {{ formatMoney(held.total) }} ج.م
              </span>
            </div>
            <div class="text-xs text-[var(--muted)] flex flex-wrap gap-2">
              <span>العميل: {{ held.customer?.name || 'عميل نقدي' }}</span>
              <span>·</span>
              <span>الأصناف: {{ held.lines_count }} ({{ held.pieces_count }} قطعة)</span>
              <span>·</span>
              <span>الوقت: {{ formatDate(held.created_at) }}</span>
            </div>
          </div>

          <div class="flex items-center gap-2 self-end sm:self-center">
            <button
              type="button"
              class="h-9 px-4 rounded-xl bg-[var(--crimson)] text-white text-xs font-bold hover:opacity-90 transition flex items-center gap-1"
              @click="$emit('resume', held.id)"
            >
              <RotateCcw class="w-3.5 h-3.5" />
              <span>استرجاع</span>
            </button>
            <button
              type="button"
              class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--muted)] hover:text-red-600 hover:border-red-300 text-xs font-bold transition flex items-center gap-1"
              @click="$emit('delete', held.id)"
            >
              <Trash2 class="w-3.5 h-3.5" />
              <span>حذف</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-12 px-4 text-[var(--muted)]">
        <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-[var(--bar)] flex items-center justify-center text-[var(--muted)]">
          <PauseCircle class="w-8 h-8" />
        </div>
        <h4 class="font-bold text-base text-[var(--text)] mb-1">لا توجد فواتير معلّقة</h4>
        <p class="text-xs">يمكنك تعليق أي فاتورة جارية بالضغط على زر تعليق أو الاختصار F4.</p>
      </div>

      <!-- Close Button -->
      <div class="flex justify-end pt-2 border-t border-[var(--border)]">
        <button
          type="button"
          class="h-10 px-6 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-sm font-bold hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          إغلاق
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { X, RotateCcw, Trash2, PauseCircle } from 'lucide-vue-next';

defineProps({
  heldSales: {
    type: Array,
    default: () => [],
  },
});

defineEmits(['close', 'resume', 'delete']);

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(isoStr) {
  if (!isoStr) return '';
  return new Date(isoStr).toLocaleTimeString('ar-EG', {
    hour: '2-digit',
    minute: '2-digit',
  });
}
</script>
