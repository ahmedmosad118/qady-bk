<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" dir="rtl">
    <div class="w-full max-w-[460px] rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)] text-center">
      <!-- Icon -->
      <div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-[var(--soft)] flex items-center justify-center text-[var(--crimson)]">
        <LockKeyhole class="w-8 h-8" />
      </div>

      <h3 class="text-xl font-black mb-1">الوردية مغلقة</h3>
      <p class="text-xs text-[var(--muted)] mb-4">
        أدخل رصيد النقدية الافتتاحي في درج الكاشير لبدء وردية عمل جديدة وتفعيل البيع.
      </p>

      <form @submit.prevent="submitOpen">
        <div class="mb-4 text-start">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">الرصيد الافتتاحي (ج.م)</label>
          <input
            v-model.number="openingBalance"
            type="number"
            min="0"
            step="any"
            required
            class="w-full h-12 text-center text-2xl font-black rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <div class="mb-5 text-start">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">ملاحظات الافتتاح (اختياري)</label>
          <input
            v-model="notes"
            type="text"
            placeholder="مثال: استلام درج الصباح..."
            class="w-full h-10 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <button
          type="submit"
          class="w-full h-12 rounded-xl bg-[var(--crimson)] text-white text-base font-bold hover:opacity-90 transition flex items-center justify-center gap-2 shadow-lg shadow-[var(--crimson)]/20 disabled:opacity-50"
          :disabled="loading"
        >
          <span v-if="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
          <span>فتح وردية جديدة</span>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { LockKeyhole } from 'lucide-vue-next';

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['open']);

const openingBalance = ref(500);
const notes = ref('');

function submitOpen() {
  emit('open', {
    opening_balance: Number(openingBalance.value || 0),
    notes: notes.value,
  });
}
</script>
