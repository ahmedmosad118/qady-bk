<template>
  <div class="inline-flex items-center select-none">
    <!-- Mark only (collapsed header / favicon-sized spot) -->
    <img
      v-if="!showText"
      :src="asset('symbol.svg')"
      :alt="brand.name"
      :class="sizeClasses.icon"
      class="w-auto object-contain"
    />

    <!-- Full lockup -->
    <template v-else>
      <img
        v-if="textColor === 'light'"
        :src="asset('logo-white.svg')"
        :alt="brand.name"
        :class="sizeClasses.logo"
        class="w-auto object-contain"
      />
      <template v-else>
        <img
          :src="asset('logo-ink.svg')"
          :alt="brand.name"
          :class="[sizeClasses.logo, 'dark:hidden']"
          class="w-auto object-contain"
        />
        <img
          :src="asset('logo-white.svg')"
          :alt="brand.name"
          :class="[sizeClasses.logo, 'hidden dark:block']"
          class="w-auto object-contain"
        />
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import brand from '../../config/brand';

const props = defineProps({
  size: {
    type: String,
    default: 'md', // 'sm', 'md', 'lg', 'xl'
  },
  showText: {
    type: Boolean,
    default: true,
  },
  // Kept for backwards compatibility with the template's API.
  showTag: {
    type: Boolean,
    default: true,
  },
  textColor: {
    type: String,
    default: 'auto', // 'auto', 'light'
  },
});

/**
 * Brand files live in `public/brand`, so they must be resolved against the
 * application base path — Vite's default `/` breaks sub-folder deployments
 * such as `http://localhost/kady-sys/public`.
 */
const asset = (file) => {
  const base = (window.APP_BASE_PATH || '/').replace(/\/+$/, '');

  return `${base}/brand/${file}`;
};

const sizeClasses = computed(() => {
  switch (props.size) {
    case 'sm':
      return { logo: 'h-8', icon: 'h-8' };
    case 'lg':
      return { logo: 'h-12 sm:h-14', icon: 'h-12' };
    case 'xl':
      return { logo: 'h-16 sm:h-20', icon: 'h-16' };
    case 'md':
    default:
      return { logo: 'h-9', icon: 'h-9' };
  }
});
</script>
