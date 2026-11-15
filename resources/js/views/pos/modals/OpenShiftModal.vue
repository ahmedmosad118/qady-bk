<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" dir="rtl">
    <div class="w-full max-w-[520px] max-h-[92vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div class="flex items-center gap-2.5">
          <div class="w-10 h-10 rounded-xl bg-[var(--soft)] flex items-center justify-center text-[var(--crimson)]">
            <LockKeyhole class="w-5 h-5" />
          </div>
          <div>
            <h3 class="text-lg font-black">{{ posStore.isOpenShift ? 'إدارة الوردية' : 'اختيار أو فتح وردية عمل' }}</h3>
            <p class="text-xs text-[var(--muted)]">لا يمكن إتمام المبيعات دون تحديد وردية عمل نشطة</p>
          </div>
        </div>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition cursor-pointer"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Mode Selector (if open shifts exist) -->
      <div v-if="posStore.availableOpenShifts.length > 0" class="flex border border-[var(--border)] rounded-2xl p-1 bg-[var(--bar)] mb-4">
        <button
          type="button"
          class="flex-1 py-2 text-xs font-bold rounded-xl transition cursor-pointer"
          :class="activeTab === 'select' ? 'bg-[var(--card)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--text)]'"
          @click="activeTab = 'select'"
        >
          الورديات المفتوحة ({{ posStore.availableOpenShifts.length }})
        </button>
        <button
          type="button"
          class="flex-1 py-2 text-xs font-bold rounded-xl transition cursor-pointer"
          :class="activeTab === 'new' ? 'bg-[var(--card)] text-[var(--text)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--text)]'"
          @click="activeTab = 'new'"
        >
          فتح وردية جديدة
        </button>
      </div>

      <!-- Tab 1: Select Open Shift -->
      <div v-if="activeTab === 'select' && posStore.availableOpenShifts.length > 0" class="space-y-3 mb-4">
        <p class="text-xs text-[var(--muted)]">اختر وردية العمل التي تود الربط عليها:</p>
        <div
          v-for="shift in posStore.availableOpenShifts"
          :key="shift.id"
          class="p-4 rounded-2xl border transition flex items-center justify-between gap-3"
          :class="posStore.activeShift?.id === shift.id
            ? 'border-[var(--ok)] bg-[var(--okbg)]/20'
            : 'border-[var(--border)] bg-[var(--bg)] hover:border-[var(--crimson)]'"
        >
          <div class="space-y-1">
            <div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
              <b class="text-sm font-black">{{ shift.shift_number }}</b>
              <span v-if="shift.opened_by === authStore.user?.id" class="text-[10px] font-bold bg-[var(--soft)] text-[var(--crimson)] px-1.5 py-0.5 rounded">
                وردتك
              </span>
            </div>
            <div class="text-xs text-[var(--muted)] flex flex-wrap gap-2">
              <span>المستخدم: {{ shift.opened_by_name || 'كاشير' }}</span>
              <span>·</span>
              <span>بدأت: {{ formatDate(shift.opened_at) }}</span>
            </div>
          </div>

          <button
            type="button"
            class="h-9 px-4 rounded-xl text-xs font-bold transition flex items-center gap-1 cursor-pointer"
            :class="posStore.activeShift?.id === shift.id
              ? 'bg-[var(--ok)] text-white'
              : 'bg-[var(--crimson)] text-white hover:opacity-90'"
            @click="selectShift(shift.id)"
          >
            <Check v-if="posStore.activeShift?.id === shift.id" class="w-3.5 h-3.5" />
            <span>{{ posStore.activeShift?.id === shift.id ? 'الوردية الحالية' : 'اختيار هذه الوردية' }}</span>
          </button>
        </div>
      </div>

      <!-- Tab 2: Open New Shift -->
      <form v-else @submit.prevent="submitOpen" class="space-y-4">
        <div class="text-start">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">الرصيد الافتتاحي في الدرج (ج.م)</label>
          <input
            v-model.number="openingBalance"
            type="number"
            min="0"
            step="any"
            required
            class="w-full h-12 text-center text-2xl font-black rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <div class="text-start">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">ملاحظات الافتتاح (اختياري)</label>
          <input
            v-model="notes"
            type="text"
            placeholder="مثال: استلام درج الصباح، عهدة البداية..."
            class="w-full h-10 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <button
          type="submit"
          class="w-full h-12 rounded-xl bg-[var(--crimson)] text-white text-base font-bold hover:opacity-90 transition flex items-center justify-center gap-2 shadow-lg shadow-[var(--crimson)]/20 disabled:opacity-50 cursor-pointer"
          :disabled="loading"
        >
          <span v-if="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
          <span>فتح وردية جديدة وتفعيل البيع</span>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { LockKeyhole, X, Check } from 'lucide-vue-next';
import { usePosStore } from '../../../stores/pos';
import { useAuthStore } from '../../../stores/auth';

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['open', 'close', 'select']);

const posStore = usePosStore();
const authStore = useAuthStore();

const activeTab = ref(posStore.availableOpenShifts.length > 0 ? 'select' : 'new');
const openingBalance = ref(500);
const notes = ref('');

function selectShift(shiftId) {
  if (posStore.selectShift(shiftId)) {
    emit('close');
  }
}

function submitOpen() {
  emit('open', {
    opening_balance: Number(openingBalance.value || 0),
    notes: notes.value,
  });
}

function formatDate(isoStr) {
  if (!isoStr) return '';
  return new Date(isoStr).toLocaleTimeString('ar-EG', {
    hour: '2-digit',
    minute: '2-digit',
  });
}
</script>
