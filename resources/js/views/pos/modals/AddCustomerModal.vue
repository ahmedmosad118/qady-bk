<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
    <div class="w-full max-w-[460px] rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <div class="flex items-center justify-between pb-3 mb-4 border-b border-[var(--border)]">
        <h3 class="text-xl font-black">{{ $t('pos.modals.addCustomer.title') }}</h3>
        <button
          type="button"
          class="rounded-xl p-1 text-[var(--muted)] hover:bg-[var(--border)] transition"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <form @submit.prevent="submitAdd">
        <div class="mb-3">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">{{ $t('pos.modals.addCustomer.nameLabel') }}</label>
          <input
            ref="nameInput"
            v-model="name"
            type="text"
            required
            :placeholder="$t('pos.modals.addCustomer.namePlaceholder')"
            class="w-full h-11 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          />
        </div>

        <div class="mb-3">
          <label class="block text-xs font-bold mb-1 text-[var(--muted)]">{{ $t('pos.modals.addCustomer.phoneLabel') }}</label>
          <input
            v-model="phone"
            type="tel"
            inputmode="numeric"
            placeholder="01XXXXXXXXX"
            class="w-full h-11 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)] text-left"
            dir="ltr"
          />
        </div>

        <div class="mb-5">
          <label class="block text-xs font-bold mb-1.5 text-[var(--muted)]">{{ $t('pos.modals.addCustomer.typeLabel') }}</label>
          <div class="flex gap-2 flex-wrap">
            <button
              v-for="item in types"
              :key="item.key"
              type="button"
              :class="[
                'px-3.5 py-1.5 rounded-full text-xs font-bold border transition',
                selectedType === item.key
                  ? 'bg-[var(--text)] text-[var(--card)] border-[var(--text)]'
                  : 'bg-[var(--card)] text-[var(--text)] border-[var(--border)] hover:border-[var(--muted)]'
              ]"
              @click="selectedType = item.key"
            >
              {{ item.label }}
            </button>
          </div>
        </div>

        <div class="flex gap-3">
          <button
            type="button"
            class="flex-1 h-11 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-bold hover:bg-[var(--border)] transition"
            :disabled="loading"
            @click="$emit('close')"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="submit"
            class="flex-1 h-11 rounded-xl bg-[var(--crimson)] text-white font-bold hover:opacity-90 transition flex items-center justify-center gap-2 disabled:opacity-50"
            :disabled="loading || !name.trim()"
          >
            <span v-if="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
            <span>{{ $t('pos.modals.addCustomer.submit') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';

const { t } = useI18n();

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'save']);

const nameInput = ref(null);
const name = ref('');
const phone = ref('');
const selectedType = ref('individual');

const types = computed(() => [
  { key: 'individual', label: t('pos.modals.addCustomer.typeIndividual') },
  { key: 'company', label: t('pos.modals.addCustomer.typeCompany') },
]);

function submitAdd() {
  if (!name.value.trim()) return;

  emit('save', {
    name: name.value.trim(),
    phone: phone.value.trim(),
    type: selectedType.value,
  });
}

onMounted(() => {
  nextTick(() => {
    nameInput.value?.focus();
  });
});
</script>
