<template>
  <span
    class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-base font-extrabold text-[var(--crimson)] dark:bg-[var(--crimson)] dark:text-white"
    aria-hidden="true"
  >
    {{ initial }}
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  name: {
    type: String,
    default: '',
  },
});

const initial = computed(() => {
  const trimmed = (props.name || '').trim();
  return trimmed ? Array.from(trimmed)[0] : '؟';
});
</script>
