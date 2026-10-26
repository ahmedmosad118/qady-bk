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
    class="fixed top-0 z-50 flex h-screen w-72 flex-col overflow-y-hidden bg-white border-gray-100 duration-300 ease-linear dark:bg-gray-900 dark:border-gray-800 lg:static transition-all"
  >
    <!-- Sidebar Header -->
    <div class="flex items-center justify-between gap-2 px-6 py-5 border-b border-gray-100 dark:border-gray-800/80">
      <div class="flex flex-col gap-2">
        <router-link to="/">
          <BrandLogo size="md" />
        </router-link>
        <span class="h-0.5 w-12 rounded-full bg-gradient-to-r from-[#B8923A] to-[#D7B26A]"></span>
      </div>

      <button
        @click="$emit('close-sidebar')"
        class="block lg:hidden p-2 rounded-xl text-gray-400 hover:text-gray-600 hover:bg-gray-50 dark:hover:bg-gray-800"
      >
        <X class="h-5 w-5" />
      </button>
    </div>

    <!-- Navigation Links -->
    <div class="no-scrollbar flex flex-col overflow-y-auto duration-300 ease-linear p-4 space-y-6">
      <div v-for="group in navigation" :key="group.key">
        <!-- Plain group -->
        <template v-if="group.items">
          <h3
            v-if="group.label"
            class="mb-2.5 px-3 text-[11px] font-black uppercase text-gray-400 tracking-wider"
          >
            {{ $t(group.label) }}
          </h3>
          <ul class="space-y-1">
            <li v-for="item in group.items" :key="item.to">
              <router-link
                :to="item.to"
                :aria-current="isItemActive(item) ? 'page' : undefined"
                :class="[
                  'group relative flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800/60 transition-colors',
                  isItemActive(item) ? activeClass : '',
                ]"
              >
                <component :is="item.icon" class="h-4 w-4 shrink-0 text-[#A3162B]" />
                <span>{{ $t(item.label) }}</span>
              </router-link>
            </li>
          </ul>
        </template>

        <!-- Collapsible group -->
        <template v-else-if="group.children">
          <button
            type="button"
            @click="toggleGroup(group.key)"
            class="w-full flex items-center justify-between mb-2 px-3 py-1 rounded-lg text-[11px] font-black uppercase text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 tracking-wider transition-colors cursor-pointer group"
            :aria-expanded="isGroupOpen(group.key)"
          >
            <span class="flex items-center gap-1.5">
              <span>{{ $t(group.label) }}</span>
              <span
                v-if="isGroupActive(group)"
                class="w-1.5 h-1.5 rounded-full bg-[#A3162B] animate-pulse"
              ></span>
            </span>
            <ChevronDown
              class="h-3.5 w-3.5 transform transition-transform duration-200"
              :class="{ '-rotate-90 rtl:rotate-90': !isGroupOpen(group.key), 'rotate-0': isGroupOpen(group.key) }"
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
            <ul v-show="isGroupOpen(group.key)" class="space-y-1">
              <li v-for="item in group.children" :key="item.to">
                <router-link
                  :to="item.to"
                  :aria-current="isItemActive(item) ? 'page' : undefined"
                  :class="[
                    'group relative flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800/60 transition-colors',
                    isItemActive(item) ? activeClass : '',
                  ]"
                >
                  <component :is="item.icon" class="h-4 w-4 shrink-0 text-[#A3162B]" />
                  <span>{{ $t(item.label) }}</span>
                </router-link>
              </li>
            </ul>
          </transition>
        </template>
      </div>
    </div>

    <!-- Sidebar Footer -->
    <div class="mt-auto p-4 border-t border-gray-100 dark:border-gray-800 text-center">
      <p class="text-[11px] font-bold text-gray-500 dark:text-gray-400">
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
const activeClass = 'bg-[#A3162B]/10 text-[#8A1226] dark:bg-[#A3162B]/15 dark:text-[#E0344B] font-bold shadow-xs';

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
