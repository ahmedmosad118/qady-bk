<template>
  <span class="relative inline-flex h-[18px] w-[18px] shrink-0 items-center justify-center">
    <input
      :id="id"
      ref="inputRef"
      type="checkbox"
      class="peer h-[18px] w-[18px] cursor-pointer appearance-none rounded-[5px] border border-[var(--border)] bg-[var(--card)] transition-colors checked:border-[var(--crimson)] checked:bg-[var(--crimson)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--crimson)]/25 disabled:cursor-not-allowed disabled:opacity-50"
      :class="indeterminate ? 'border-[var(--crimson)] bg-[var(--crimson)]' : ''"
      :checked="checked"
      :disabled="disabled"
      :aria-label="ariaLabel"
      @change="$emit('change', $event)"
    />
    <Minus
      v-if="indeterminate"
      class="pointer-events-none absolute h-3 w-3 text-white"
      stroke-width="3"
      aria-hidden="true"
    />
    <Check
      v-else
      class="pointer-events-none absolute h-3 w-3 text-white opacity-0 peer-checked:opacity-100"
      stroke-width="3"
      aria-hidden="true"
    />
  </span>
</template>

<script setup>
import { ref, watchEffect } from 'vue';
import { Check, Minus } from 'lucide-vue-next';

const props = defineProps({
  checked: {
    type: Boolean,
    default: false,
  },
  indeterminate: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  id: {
    type: String,
    default: undefined,
  },
  ariaLabel: {
    type: String,
    default: undefined,
  },
});

defineEmits(['change']);

const inputRef = ref(null);

watchEffect(() => {
  if (inputRef.value) {
    inputRef.value.indeterminate = props.indeterminate;
  }
});
</script>
