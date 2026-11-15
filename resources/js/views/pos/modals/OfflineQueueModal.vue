<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" dir="rtl">
    <div class="w-full max-w-[680px] max-h-[90vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div>
          <h3 class="text-xl font-black flex items-center gap-2">
            <CloudOff class="w-5 h-5 text-amber-500" />
            <span>قائمة مبيعات الأوفلاين (المزامنة المحلية)</span>
          </h3>
          <p class="text-xs text-[var(--muted)]">فواتير تم تسجيلها محلياً أثناء انقطاع الاتصال ومحفوظة بأمان في المتصفح</p>
        </div>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Sync Status Banner -->
      <div class="flex items-center justify-between p-3.5 mb-4 rounded-2xl bg-[var(--bar)] border border-[var(--border)] text-xs">
        <div class="flex items-center gap-2">
          <span class="w-2.5 h-2.5 rounded-full" :class="posStore.isOnline ? 'bg-emerald-500' : 'bg-amber-500'"></span>
          <span>حالة الاتصال: <b>{{ posStore.isOnline ? 'متصل بالإنترنت' : 'غير متصل (أوفلاين)' }}</b></span>
        </div>
        <button
          v-if="posStore.isOnline && posStore.offlineQueue.length > 0"
          type="button"
          :disabled="posStore.syncingQueue"
          class="h-8 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold transition flex items-center gap-1.5 disabled:opacity-50 cursor-pointer"
          @click="posStore.syncOfflineQueue()"
        >
          <RotateCw class="w-3.5 h-3.5" :class="{ 'animate-spin': posStore.syncingQueue }" />
          <span>{{ posStore.syncingQueue ? 'جاري المزامنة...' : 'مزامنة الكل الآن' }}</span>
        </button>
      </div>

      <!-- Queue List -->
      <div v-if="posStore.offlineQueue.length > 0" class="space-y-3 mb-6">
        <div
          v-for="(item, idx) in posStore.offlineQueue"
          :key="item.client_uuid || idx"
          class="p-4 rounded-2xl border transition space-y-2.5"
          :class="item.sync_status === 'conflict'
            ? 'border-red-300 bg-red-50/50 dark:bg-red-950/20'
            : 'border-[var(--border)] bg-[var(--bg)]'"
        >
          <!-- Row Header -->
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
              <span
                class="w-2.5 h-2.5 rounded-full"
                :class="item.sync_status === 'conflict' ? 'bg-red-500' : 'bg-amber-500'"
              ></span>
              <span class="font-mono text-xs font-bold text-[var(--muted)]">
                {{ (item.client_uuid || '').substring(0, 18) }}...
              </span>
              <span
                class="text-[10px] font-bold px-2 py-0.5 rounded-md"
                :class="item.sync_status === 'conflict'
                  ? 'bg-red-100 text-red-800 dark:bg-red-900/60 dark:text-red-300'
                  : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300'"
              >
                {{ item.sync_status === 'conflict' ? 'تعارض في المزامنة' : 'بانتظار المزامنة' }}
              </span>
            </div>
            <b class="text-sm font-black text-[var(--crimson)]">
              {{ formatMoney(item.grand_total) }} ج.م
            </b>
          </div>

          <!-- Metadata -->
          <div class="text-xs text-[var(--muted)] flex flex-wrap gap-2">
            <span>العميل: {{ item.customer?.name || 'عميل نقدي' }}</span>
            <span>·</span>
            <span>الأصناف: {{ item.items?.length || 0 }}</span>
            <span>·</span>
            <span>التاريخ: {{ formatDate(item.created_at) }}</span>
          </div>

          <!-- Conflict Explanation -->
          <div
            v-if="item.sync_status === 'conflict' && item.conflict_reason"
            class="p-2.5 rounded-xl bg-red-100/70 dark:bg-red-900/40 text-red-800 dark:text-red-200 text-xs font-medium border border-red-200 dark:border-red-800/60 flex items-start gap-1.5"
          >
            <AlertTriangle class="w-4 h-4 text-red-600 flex-none mt-0.5" />
            <span>{{ item.conflict_reason }}</span>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-12 px-4 text-[var(--muted)]">
        <CheckCircle2 class="w-12 h-12 mx-auto mb-3 text-emerald-500" />
        <h4 class="font-bold text-base text-[var(--text)] mb-1">لا توجد عمليات بانتظار المزامنة</h4>
        <p class="text-xs">جميع عمليات البيع متزامنة تماماً مع الخادم وقاعدة البيانات المركزية.</p>
      </div>

      <!-- Footer -->
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
import { X, CloudOff, RotateCw, AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import { usePosStore } from '../../../stores/pos';

defineEmits(['close']);

const posStore = usePosStore();

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatDate(isoStr) {
  if (!isoStr) return '';
  return new Date(isoStr).toLocaleString('ar-EG', {
    dateStyle: 'short',
    timeStyle: 'short',
  });
}
</script>
