<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-[520px] max-h-[92vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Header -->
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <div class="flex items-center gap-2.5">
          <div class="w-10 h-10 rounded-xl bg-[var(--soft)] flex items-center justify-center text-[var(--crimson)]">
            <LockKeyhole class="w-5 h-5" />
          </div>
          <div>
            <h3 class="text-lg font-black">{{ $t('pos.modals.openShift.newShiftTab') }}</h3>
            <p class="text-xs text-[var(--muted)]">{{ $t('pos.modals.openShift.subtitle') }}</p>
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

      <!-- Open New Shift Form -->
      <form @submit.prevent="submitOpen" class="space-y-4">
        <div class="text-start">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">{{ $t('pos.modals.openShift.openingBalanceLabel') }}</label>
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
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">{{ $t('pos.modals.openShift.notesLabel') }}</label>
          <input
            v-model="notes"
            type="text"
            :placeholder="$t('pos.modals.openShift.notesPlaceholder')"
            class="w-full h-10 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <button
          type="submit"
          class="w-full h-12 rounded-xl bg-[var(--crimson)] text-white text-base font-bold hover:opacity-90 transition flex items-center justify-center gap-2 shadow-lg shadow-[var(--crimson)]/20 disabled:opacity-50 cursor-pointer"
          :disabled="loading"
        >
          <span v-if="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
          <span>{{ $t('pos.modals.openShift.submitBtn') }}</span>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { LockKeyhole, X } from 'lucide-vue-next';

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['open', 'close']);

const openingBalance = ref(0);
const notes = ref('');

function submitOpen() {
  emit('open', {
    opening_balance: Number(openingBalance.value || 0),
    notes: notes.value,
  });
}
</script>
