<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" dir="rtl">
    <div class="w-full max-w-[460px] rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <h3 class="text-xl font-black">تسجيل حركة نقدية في الوردية</h3>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Type Toggle -->
      <div class="grid grid-cols-2 gap-2 mb-4">
        <button
          type="button"
          :class="[
            'py-2.5 rounded-xl font-bold text-sm border-2 transition',
            txType === 'expense'
              ? 'border-[var(--crimson)] bg-[var(--soft)] text-[var(--crimson)]'
              : 'border-[var(--border)] bg-[var(--card)] text-[var(--muted)]'
          ]"
          @click="txType = 'expense'"
        >
          مصروف نقدي
        </button>
        <button
          type="button"
          :class="[
            'py-2.5 rounded-xl font-bold text-sm border-2 transition',
            txType === 'withdrawal'
              ? 'border-[var(--crimson)] bg-[var(--soft)] text-[var(--crimson)]'
              : 'border-[var(--border)] bg-[var(--card)] text-[var(--muted)]'
          ]"
          @click="txType = 'withdrawal'"
        >
          سحب نقدي (عهدة/توريد)
        </button>
      </div>

      <form @submit.prevent="submitTx">
        <div class="mb-3">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">المبلغ (ج.م) *</label>
          <input
            v-model.number="amount"
            type="number"
            min="0.01"
            step="any"
            required
            class="w-full h-11 px-3 text-lg font-black text-center rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <div class="mb-3">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">بيان الحركة / اسم المصروف *</label>
          <input
            v-model="name"
            type="text"
            required
            placeholder="مثال: ضيافة، أدوات نظافة، توريد للخزينة الرئيسية..."
            class="w-full h-11 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <div class="mb-5">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">تفاصيل إضافية (اختياري)</label>
          <textarea
            v-model="description"
            rows="2"
            placeholder="أي تفاصيل أو ملاحظات إضافية..."
            class="w-full p-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)] resize-none"
          ></textarea>
        </div>

        <div class="flex gap-3">
          <button
            type="button"
            class="flex-1 h-11 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-bold hover:bg-[var(--border)] transition"
            :disabled="loading"
            @click="$emit('close')"
          >
            إلغاء
          </button>
          <button
            type="submit"
            class="flex-1 h-11 rounded-xl bg-[var(--crimson)] text-white font-bold hover:opacity-90 transition flex items-center justify-center gap-2 disabled:opacity-50"
            :disabled="loading"
          >
            <span v-if="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
            <span>تأكيد التسجيل</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { X } from 'lucide-vue-next';

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'submit']);

const txType = ref('expense');
const amount = ref(null);
const name = ref('');
const description = ref('');

function submitTx() {
  emit('submit', {
    type: txType.value,
    amount: Number(amount.value),
    name: name.value,
    description: description.value,
  });
}
</script>
