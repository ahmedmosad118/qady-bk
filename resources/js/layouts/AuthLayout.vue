<template>
  <div
    class="flex min-h-[100dvh] flex-col bg-[var(--bg)] font-sans text-[var(--text)] lg:flex-row"
    :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
  >
    <!-- Brand panel (desktop) -->
    <section
      class="relative hidden w-1/2 flex-col overflow-hidden bg-[#16181D] lg:flex"
      aria-label="مكتبة القاضي"
    >
      <!-- Ambient decoration -->
      <div class="pointer-events-none absolute inset-0" aria-hidden="true">
        <div class="absolute -top-32 -right-24 h-[30rem] w-[30rem] rounded-full bg-[#A3162B]/25 blur-[130px]"></div>
        <div class="absolute -bottom-40 -left-24 h-[26rem] w-[26rem] rounded-full bg-[#B8923A]/10 blur-[130px]"></div>
        <img
          :src="symbolUrl"
          alt=""
          class="absolute -bottom-16 left-1/2 w-[84%] max-w-[540px] -translate-x-1/2 opacity-[0.055] brightness-0 invert"
        />
      </div>

      <!-- Centered stacked lockup -->
      <div class="relative z-10 flex flex-1 items-center justify-center px-10 py-12">
        <img
          :src="stackedLogoUrl"
          alt="مكتبة القاضي — للإصدارات القانونية"
          class="h-auto w-[300px] max-w-[78%] xl:w-[340px]"
        />
      </div>

      <!-- Footer -->
      <div class="relative z-10 flex items-center justify-between gap-4 border-t border-white/10 px-10 py-5 xl:px-14">
        <p class="text-sm text-white/45">
          &copy; {{ year }} {{ brand.name }}. {{ $t('auth.allRightsReserved') }}
        </p>
        <span class="shrink-0 text-sm font-semibold text-[var(--gold)]">{{ $t('common.isolatedDb') }}</span>
      </div>
    </section>

    <!-- Form side -->
    <section class="flex min-h-[100dvh] flex-1 flex-col lg:min-h-0">
      <!-- Utilities -->
      <div class="flex items-center justify-end gap-2 px-5 py-5 sm:px-8">
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40"
          :aria-label="$t('header.language')"
          @click="toggleLang"
        >
          <Globe class="h-4 w-4 text-[var(--crimson)]" aria-hidden="true" />
          <span>{{ $i18n.locale === 'ar' ? 'English' : 'العربية' }}</span>
        </button>

        <button
          type="button"
          class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40"
          :aria-label="themeStore.isDark ? $t('header.lightMode') : $t('header.darkMode')"
          :title="themeStore.isDark ? $t('header.lightMode') : $t('header.darkMode')"
          @click="themeStore.toggleTheme"
        >
          <Sun v-if="themeStore.isDark" class="h-[18px] w-[18px] text-[var(--gold)]" aria-hidden="true" />
          <Moon v-else class="h-[18px] w-[18px]" aria-hidden="true" />
        </button>
      </div>

      <!-- Form -->
      <div class="flex flex-1 items-center justify-center px-5 pb-10 pt-4 sm:px-8">
        <div class="w-full max-w-[420px]">
          <router-view />
        </div>
      </div>

      <footer class="px-5 pb-6 text-center text-sm text-[var(--muted)] sm:px-8">
        &copy; {{ year }} {{ brand.name }}. {{ $t('auth.allRightsReserved') }}
      </footer>
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Globe, Sun, Moon } from 'lucide-vue-next';
import brand from '../config/brand';
import { setLanguage } from '../i18n';
import { useThemeStore } from '../stores/theme';

const { locale } = useI18n();
const themeStore = useThemeStore();
const year = computed(() => new Date().getFullYear());

const basePath = (window.APP_BASE_PATH || '/').replace(/\/+$/, '');
const symbolUrl = `${basePath}/brand/symbol.svg`;
const stackedLogoUrl = `${basePath}/brand/logo-primary-white-transparent.svg`;

const toggleLang = () => {
  setLanguage(locale.value === 'ar' ? 'en' : 'ar');
};
</script>
