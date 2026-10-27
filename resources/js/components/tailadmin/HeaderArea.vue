<template>
  <header class="sticky top-0 z-40 flex w-full border-b border-[var(--border)] bg-[var(--card)] transition-colors">
    <div class="flex grow items-center justify-between px-4 py-3 sm:px-6 md:px-8">
      <!-- Mobile sidebar toggle -->
      <div class="flex items-center gap-3">
        <button
          type="button"
          class="rounded-lg p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] lg:hidden"
          :aria-label="$t('common.dashboard')"
          :title="$t('common.dashboard')"
          @click="$emit('toggle-sidebar')"
        >
          <Menu class="h-5 w-5" aria-hidden="true" />
        </button>
      </div>

      <!-- Actions: global search, language, theme, profile -->
      <div class="ms-auto flex items-center gap-2 sm:gap-3">
        <!-- Global search (dashboard) -->
        <div v-if="isDashboard" class="relative hidden w-64 lg:block xl:w-80">
          <Search
            class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
            aria-hidden="true"
          />
          <input
            v-model="globalSearch"
            type="search"
            class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] pl-4 pr-10 text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/75 focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson)]/15"
            :placeholder="$t('dashboard.searchPlaceholder')"
            :aria-label="$t('dashboard.searchPlaceholder')"
            @keyup.enter="submitSearch"
          />
        </div>

        <!-- Language switcher -->
        <div ref="langMenuRef" class="relative">
          <button
            type="button"
            class="flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-2 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/35"
            :aria-label="$t('header.language')"
            :title="$t('header.language')"
            aria-haspopup="menu"
            :aria-expanded="langOpen"
            @click="langOpen = !langOpen"
          >
            <Globe class="h-4 w-4 text-[var(--crimson)]" aria-hidden="true" />
            <span class="hidden sm:inline">{{ currentLocaleLabel }}</span>
            <span class="sm:hidden">{{ locale.toUpperCase() }}</span>
          </button>

          <div
            v-if="langOpen"
            class="absolute z-50 mt-2 w-36 rounded-xl border border-[var(--border)] bg-[var(--card)] p-1.5 shadow-xl"
            :class="locale === 'ar' ? 'left-0' : 'right-0'"
            role="menu"
          >
            <button
              type="button"
              class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
              :class="{ 'bg-[var(--crimson-soft)] text-[var(--crimson)]': locale === 'ar' }"
              role="menuitem"
              @click="switchLocale('ar')"
            >
              <span>العربية</span>
              <Check v-if="locale === 'ar'" class="h-4 w-4 text-[var(--crimson)]" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-semibold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
              :class="{ 'bg-[var(--crimson-soft)] text-[var(--crimson)]': locale === 'en' }"
              role="menuitem"
              @click="switchLocale('en')"
            >
              <span>English</span>
              <Check v-if="locale === 'en'" class="h-4 w-4 text-[var(--crimson)]" aria-hidden="true" />
            </button>
          </div>
        </div>

        <!-- Dark mode toggle -->
        <button
          type="button"
          class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-2.5 text-[var(--text)] transition-colors hover:border-[var(--crimson)]/35"
          :aria-label="themeStore.isDark ? $t('header.lightMode') : $t('header.darkMode')"
          :title="themeStore.isDark ? $t('header.lightMode') : $t('header.darkMode')"
          @click="themeStore.toggleTheme"
        >
          <Sun v-if="themeStore.isDark" class="h-4 w-4 text-[var(--gold)]" aria-hidden="true" />
          <Moon v-else class="h-4 w-4" aria-hidden="true" />
        </button>

        <!-- User dropdown -->
        <DropdownUser />
      </div>
    </div>
  </header>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { Menu, Globe, Sun, Moon, Check, Search } from 'lucide-vue-next';
import { useThemeStore } from '../../stores/theme';
import { setLanguage } from '../../i18n';
import DropdownUser from './DropdownUser.vue';

defineEmits(['toggle-sidebar']);

const themeStore = useThemeStore();
const { locale } = useI18n();
const route = useRoute();
const router = useRouter();

const langOpen = ref(false);
const langMenuRef = ref(null);
const globalSearch = ref('');

const isDashboard = computed(() => route.name === 'home');

const submitSearch = () => {
  if (!globalSearch.value.trim()) return;
  router.push({ name: 'publications', query: { q: globalSearch.value.trim() } });
};

const currentLocaleLabel = computed(() => {
  return locale.value === 'ar' ? 'العربية' : 'English';
});

const switchLocale = (target) => {
  setLanguage(target);
  langOpen.value = false;
};

const handleClickOutside = (e) => {
  if (langMenuRef.value && !langMenuRef.value.contains(e.target)) {
    langOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
  themeStore.initTheme();
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
