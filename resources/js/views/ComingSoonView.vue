<template>
  <div class="flex min-h-[62vh] flex-col items-center justify-center px-4 py-12 text-center">
    <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
      <Construction class="h-7 w-7" aria-hidden="true" />
    </span>

    <h1 class="mt-5 text-2xl font-extrabold text-[var(--text)]">
      {{ pageTitle }}
    </h1>
    <p class="mt-2 max-w-md text-sm leading-7 text-[var(--muted)]">
      {{ $t('common.comingSoonDesc') }}
    </p>

    <router-link
      to="/"
      class="mt-6 inline-flex h-11 items-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
    >
      <ArrowLeft class="h-4 w-4 rtl:rotate-180" aria-hidden="true" />
      {{ $t('common.backToDashboard') }}
    </router-link>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ArrowLeft, Construction } from 'lucide-vue-next';

const route = useRoute();
const { t, te } = useI18n();

const pageTitle = computed(() => {
  const key = route.meta?.titleKey;
  return key && te(key) ? t(key) : t('common.comingSoon');
});
</script>
