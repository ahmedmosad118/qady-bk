<template>
  <aside
    :class="[
      sidebarOpen
        ? 'translate-x-0'
        : isRtl
          ? 'translate-x-full lg:translate-x-0'
          : '-translate-x-full lg:translate-x-0',
      isRtl ? 'right-0 border-l' : 'left-0 border-r',
    ]"
    class="fixed top-0 z-50 flex h-screen w-72 flex-col overflow-y-hidden border-[var(--border)] bg-[var(--card)] duration-300 ease-linear transition-all lg:static"
  >
    <!-- Sidebar Header -->
    <div class="flex items-center justify-between gap-2 border-b border-[var(--border)] px-6 py-5">
      <div class="flex w-full flex-col gap-2.5">
        <router-link to="/" class="inline-flex w-fit" :aria-label="$t('common.dashboard')">
          <BrandLogo size="md" />
        </router-link>
        <span class="h-px w-full bg-gradient-to-l from-transparent via-[var(--gold)] to-[var(--gold)]/40"></span>
      </div>

      <button
        type="button"
        class="block rounded-lg p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] lg:hidden"
        :aria-label="$t('common.close')"
        :title="$t('common.close')"
        @click="$emit('close-sidebar')"
      >
        <X class="h-5 w-5" aria-hidden="true" />
      </button>
    </div>

    <!-- Navigation Links -->
    <div class="no-scrollbar flex flex-col space-y-6 overflow-y-auto p-4 duration-300 ease-linear">
      <div v-for="group in navigation" :key="group.key">
        <!-- Plain group -->
        <template v-if="group.items">
          <h3
            v-if="group.label"
            class="mb-2 px-3 text-sm font-semibold text-[var(--muted)]"
          >
            {{ $t(group.label) }}
          </h3>
          <ul class="space-y-1">
            <li v-for="item in group.items" :key="item.to">
              <router-link
                :to="item.to"
                :aria-current="isItemActive(item) ? 'page' : undefined"
                :class="[
                  'group flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]',
                  isItemActive(item) ? activeClass : '',
                ]"
              >
                <component
                  :is="item.icon"
                  class="h-[18px] w-[18px] shrink-0"
                  :class="isItemActive(item) ? 'text-[var(--crimson)]' : 'text-[var(--muted)] group-hover:text-[var(--text)]'"
                  aria-hidden="true"
                />
                <span>{{ $t(item.label) }}</span>
                <span
                  v-if="item.badge"
                  class="ms-auto inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-[var(--crimson)] px-1.5 text-sm font-bold leading-none text-white"
                >
                  {{ item.badge }}
                </span>
              </router-link>
            </li>
          </ul>
        </template>

        <!-- Collapsible group -->
        <template v-else-if="group.children">
          <button
            type="button"
            class="group mb-2 flex w-full items-center justify-between rounded-lg px-3 py-1 text-sm font-semibold text-[var(--muted)] transition-colors hover:text-[var(--text)]"
            :aria-expanded="isGroupOpen(group.key)"
            :aria-controls="`nav-group-${group.key}`"
            @click="toggleGroup(group.key)"
          >
            <span class="flex items-center gap-2">
              <span>{{ $t(group.label) }}</span>
              <span
                v-if="isGroupActive(group)"
                class="h-1.5 w-1.5 rounded-full bg-[var(--crimson)]"
                aria-hidden="true"
              ></span>
            </span>
            <ChevronDown
              class="h-4 w-4 transform transition-transform duration-200"
              :class="{ '-rotate-90 rtl:rotate-90': !isGroupOpen(group.key), 'rotate-0': isGroupOpen(group.key) }"
              aria-hidden="true"
            />
          </button>

          <transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 -translate-y-1"
          >
            <ul v-show="isGroupOpen(group.key)" :id="`nav-group-${group.key}`" class="space-y-1">
              <li v-for="item in group.children" :key="item.to">
                <router-link
                  :to="item.to"
                  :aria-current="isItemActive(item) ? 'page' : undefined"
                  :class="[
                    'group flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-semibold text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]',
                    isItemActive(item) ? activeClass : '',
                  ]"
                >
                  <component
                    :is="item.icon"
                    class="h-[18px] w-[18px] shrink-0"
                    :class="isItemActive(item) ? 'text-[var(--crimson)]' : 'text-[var(--muted)] group-hover:text-[var(--text)]'"
                    aria-hidden="true"
                  />
                  <span>{{ $t(item.label) }}</span>
                <span
                  v-if="item.badge"
                  class="ms-auto inline-flex h-6 min-w-6 items-center justify-center rounded-full bg-[var(--crimson)] px-1.5 text-sm font-bold leading-none text-white"
                >
                  {{ item.badge }}
                </span>
                </router-link>
              </li>
            </ul>
          </transition>
        </template>
      </div>
    </div>

    <!-- Sidebar Footer -->
    <div class="mt-auto border-t border-[var(--border)] p-4 text-center">
      <p class="text-sm font-medium text-[var(--muted)]">
        {{ appName }} &middot; v1.0
      </p>
    </div>
  </aside>
</template>

<script setup>
import { computed, reactive, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import BrandLogo from '../common/BrandLogo.vue';
import { ChevronDown, X } from 'lucide-vue-next';
import { navigation } from '../../config/navigation';
import brand from '../../config/brand';

defineProps({
  sidebarOpen: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['close-sidebar']);

const appName = brand.name;
const activeClass =
  'bg-[var(--crimson-soft)] text-[var(--crimson)] font-bold hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]';

const route = useRoute();
const { locale } = useI18n();
const isRtl = computed(() => locale.value === 'ar');

/**
 * A nav item is active only on its own page (the dashboard "/" must not
 * match every route, which is what router-link's default active matching does).
 */
const isItemActive = (item) => {
  if (item.to === '/') {
    return route.path === '/';
  }
  return route.path === item.to || route.path.startsWith(`${item.to}/`);
};

/**
 * Collapsible groups state, persisted per group key.
 */
const openGroups = reactive({});

const groups = navigation.filter((group) => group.children);

const storageKey = (key) => `sidebar_${key}_open`;

const isGroupOpen = (key) => {
  if (openGroups[key] === undefined) {
    const saved = localStorage.getItem(storageKey(key));
    openGroups[key] = saved === null ? true : saved === 'true';
  }
  return openGroups[key];
};

const toggleGroup = (key) => {
  openGroups[key] = !isGroupOpen(key);
  localStorage.setItem(storageKey(key), String(openGroups[key]));
};

const isRouteInGroup = (group) =>
  (group.children || []).some(
    (item) => route.path === item.to || route.path.startsWith(`${item.to}/`),
  );

const isGroupActive = (group) => isRouteInGroup(group);

watch(
  () => route.path,
  () => {
    groups.forEach((group) => {
      if (isRouteInGroup(group)) {
        openGroups[group.key] = true;
        localStorage.setItem(storageKey(group.key), 'true');
      }
    });
  },
  { immediate: true },
);
</script>
