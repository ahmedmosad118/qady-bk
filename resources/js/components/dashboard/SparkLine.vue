<template>
  <svg
    :viewBox="`0 0 ${width} ${height}`"
    preserveAspectRatio="none"
    role="presentation"
    aria-hidden="true"
  >
    <polyline
      :points="linePoints"
      fill="none"
      :stroke="color"
      stroke-width="1.8"
      stroke-linecap="round"
      stroke-linejoin="round"
      vector-effect="non-scaling-stroke"
    />
  </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  points: {
    type: Array,
    default: () => [4, 7, 5, 9, 8, 12, 10],
  },
  width: {
    type: Number,
    default: 120,
  },
  height: {
    type: Number,
    default: 36,
  },
  color: {
    type: String,
    default: 'var(--crimson)',
  },
});

const linePoints = computed(() => {
  const values = props.points?.length ? props.points.map(Number) : [0, 0];
  const min = Math.min(...values);
  const max = Math.max(...values);
  const span = max - min || 1;
  const step = props.width / Math.max(values.length - 1, 1);
  const padding = 3;

  return values
    .map((value, index) => {
      const x = index * step;
      const y = props.height - padding - ((value - min) / span) * (props.height - padding * 2);
      return `${x.toFixed(2)},${y.toFixed(2)}`;
    })
    .join(' ');
});
</script>
