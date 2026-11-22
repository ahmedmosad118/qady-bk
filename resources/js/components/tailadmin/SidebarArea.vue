<template>
  <!-- Mobile Backdrop -->
  <div
    v-if="sidebarOpen"
    class="print:hidden fixed inset-0 z-40 bg-black/40 backdrop-blur-[1px] lg:hidden"
    @click="closeMobileSidebar"
  />

  <aside
    ref="sidebarRef"
    :class="[
      sidebarOpen
        ? 'translate-x-0'
        : isRtl
          ? 'translate-x-full lg:translate-x-0'
          : '-translate-x-full lg:translate-x-0',
      isRtl ? 'right-0 border-l' : 'left-0 border-r',
      isRail ? 'lg:w-[80px] lg:px-2.5' : 'w-[272px] px-3.5',
    ]"
    class="print:hidden fixed top-0 z-50 flex h-screen shrink-0 flex-col border-[var(--border)] bg-[var(--card)] py-[18px] transition-all duration-200 ease-linear lg:sticky lg:top-0"
  >
    <!-- Brand Header -->
    <div
      class="flex items-center gap-3 border-b-2 border-[var(--gold)] pb-4"
      :class="isRail ? 'lg:justify-center lg:px-0 px-1.5' : 'px-1.5'"
    >
      <router-link
        to="/"
        class="flex min-w-0 flex-1 items-center gap-3"
        :class="isRail ? 'lg:justify-center lg:flex-none' : ''"
        :aria-label="$t('common.dashboard')"
        @click="onDirectNavigate"
      >
        <BrandLogo :show-text="false" size="md" class="shrink-0" />
        <div class="min-w-0" :class="{ 'lg:hidden': isRail }">
          <strong class="block truncate text-[19px] font-extrabold leading-[1.2] text-[var(--text)]">
            {{ isRtl ? brand.name : brand.nameEn }}
          </strong>
          <span class="block truncate text-[12.5px] font-normal text-[var(--muted)]">
            {{ isRtl ? brand.tagline : brand.taglineEn }}
          </span>
        </div>
      </router-link>

      <button
        type="button"
        class="rounded-lg p-1.5 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] lg:hidden"
        :aria-label="$t('common.close')"
        :title="$t('common.close')"
        @click="closeMobileSidebar"
      >
        <X class="h-5 w-5" aria-hidden="true" />
      </button>
    </div>

    <!-- Quick Menu Search -->
    <label
      ref="searchWrapRef"
      class="mt-3.5 mb-2 flex h-[42px] items-center gap-2 rounded-xl border border-transparent bg-[var(--bg)] px-3 text-[var(--muted)] transition-colors focus-within:border-[var(--crimson)] focus-within:bg-[var(--card)]"
      :class="isRail ? 'lg:justify-center lg:px-0 lg:cursor-pointer' : ''"
      @click="onSearchWrapClick"
    >
      <Search class="h-5 w-5 shrink-0" aria-hidden="true" />
      <input
        ref="searchInputRef"
        v-model="searchQuery"
        type="text"
        autocomplete="off"
        :placeholder="$t('nav.menuSearchPlaceholder')"
        class="min-w-0 flex-1 border-0 bg-transparent text-sm text-[var(--text)] outline-none placeholder:text-[var(--muted)]/80"
        :class="{ 'lg:hidden': isRail }"
        @input="onSearchInput"
        @keydown.enter.prevent="selectFirstSearchResult"
      />
      <kbd
        class="shrink-0 rounded-md border border-[var(--border)] px-1.5 py-0.5 text-[11px] font-semibold text-[var(--muted)]"
        :class="{ 'lg:hidden': isRail }"
      >
        Ctrl K
      </kbd>
    </label>

    <!-- Primary Category Rail -->
    <nav
      ref="navRef"
      class="no-scrollbar flex flex-1 flex-col gap-0.5 overflow-y-auto py-1"
      @mouseleave="scheduleCloseFlyout"
    >
      <template v-for="group in visibleNavigation" :key="group.key">
        <!-- Direct Route (e.g., Dashboard, Reports) -->
        <router-link
          v-if="!group.sections"
          :to="group.to"
          :title="$t(group.label)"
          class="group flex w-full items-center gap-3 rounded-xl px-3 py-[11px] text-start text-[15px] font-semibold transition-colors"
          :class="[
            isRail ? 'lg:justify-center lg:px-0 lg:py-3' : '',
            isGroupActive(group)
              ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
              : 'text-[var(--text)] hover:bg-[var(--bg)]',
          ]"
          @mouseenter="onDirectHover"
          @click="onDirectNavigate"
        >
          <component
            :is="group.icon"
            class="h-5 w-5 shrink-0"
            aria-hidden="true"
          />
          <span class="flex-1 truncate" :class="{ 'lg:hidden': isRail }">
            {{ $t(group.label) }}
          </span>
        </router-link>

        <!-- Category Trigger with Floating Flyout -->
        <button
          v-else
          type="button"
          :title="$t(group.label)"
          :aria-expanded="flyoutOpen && activeFlyoutGroup?.key === group.key"
          class="group flex w-full items-center gap-3 rounded-xl border-0 px-3 py-[11px] text-start text-[15px] font-semibold transition-colors cursor-pointer"
          :class="[
            isRail ? 'lg:justify-center lg:px-0 lg:py-3' : '',
            isGroupActive(group)
              ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
              : flyoutOpen && activeFlyoutGroup?.key === group.key
                ? 'bg-[var(--bg)] text-[var(--text)]'
                : 'bg-transparent text-[var(--text)] hover:bg-[var(--bg)]',
          ]"
          @mouseenter="onGroupHover(group, $event)"
          @click="onGroupClick(group, $event)"
        >
          <component
            :is="group.icon"
            class="h-5 w-5 shrink-0"
            aria-hidden="true"
          />
          <span class="flex-1 truncate" :class="{ 'lg:hidden': isRail }">
            {{ $t(group.label) }}
          </span>
          <ChevronLeft
            class="h-4 w-4 shrink-0 transition-transform"
            :class="[
              { 'lg:hidden': isRail },
              !isRtl ? 'rotate-180' : '',
              isGroupActive(group) ? 'text-[var(--crimson)]' : 'text-[var(--muted)]',
            ]"
            aria-hidden="true"
          />
        </button>
      </template>
    </nav>

    <!-- Sidebar Footer -->
    <div
      class="mt-2 flex items-center gap-2.5 border-t border-[var(--border)] pt-3 text-[13px] text-[var(--muted)]"
      :class="isRail ? 'lg:justify-center' : ''"
    >
      <span class="flex-1 truncate font-medium" :class="{ 'lg:hidden': isRail }">
        {{ isRtl ? brand.name : brand.nameEn }} &middot; v1.0
      </span>
      <button
        type="button"
        class="hidden h-[38px] w-[38px] shrink-0 place-items-center rounded-[10px] border border-[var(--border)] bg-transparent text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] lg:grid cursor-pointer"
        :aria-label="isRail ? $t('nav.expandSidebar') : $t('nav.collapseSidebar')"
        :title="isRail ? $t('nav.expandSidebar') : $t('nav.collapseSidebar')"
        @click="toggleRail"
      >
        <PanelRight class="h-5 w-5" :class="{ 'rotate-180': !isRtl }" aria-hidden="true" />
      </button>
    </div>
  </aside>

  <!-- Floating Flyout Menu (Teleported to body so overflow-hidden never clips it) -->
  <Teleport to="body">
    <div
      v-if="flyoutOpen"
      ref="flyoutRef"
      role="menu"
      :dir="isRtl ? 'rtl' : 'ltr'"
      :style="flyoutStyle"
      class="fixed z-[60] max-h-[calc(100vh-24px)] w-[330px] max-w-[calc(100vw-24px)] overflow-y-auto rounded-[18px] border border-[var(--border)] bg-[var(--card)] p-2.5 shadow-[0_24px_50px_-16px_rgba(0,0,0,0.35)]"
      @mouseenter="cancelCloseTimer"
      @mouseleave="scheduleCloseFlyout"
    >
      <!-- Search Results Mode -->
      <template v-if="flyoutMode === 'search'">
        <div class="flex items-center justify-between px-3 pt-2 pb-2.5 text-base font-extrabold text-[var(--text)]">
          <span>{{ $t('nav.searchResults') }}</span>
          <small class="text-[12.5px] font-semibold text-[var(--muted)]">{{ searchResults.length }}</small>
        </div>

        <div v-if="searchResults.length === 0" class="p-5 text-center text-sm text-[var(--muted)]">
          {{ $t('nav.noPagesFound') }}
        </div>

        <template v-else>
          <router-link
            v-for="result in searchResults"
            :key="result.to"
            :to="result.to"
            role="menuitem"
            class="group/item flex w-full items-center gap-2.5 rounded-[10px] px-3 py-[9px] text-start text-[14.5px] font-semibold transition-colors"
            :class="isItemActive(result)
              ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
              : 'text-[var(--text)] hover:bg-[var(--bg)]'"
            @click="onFlyoutLinkClick"
          >
            <span
              class="h-[7px] w-[7px] shrink-0 rounded-full border-2"
              :class="isItemActive(result)
                ? 'border-[var(--crimson)] bg-[var(--crimson)]'
                : 'border-[var(--border)]'"
            />
            <span class="truncate">{{ $t(result.label) }}</span>
            <small class="ms-auto shrink-0 text-xs font-normal text-[var(--muted)]">
              {{ $t(result.groupLabel) }}
            </small>
          </router-link>
        </template>
      </template>

      <!-- Group Sections Mode -->
      <template v-else-if="activeFlyoutGroup">
        <div class="flex items-center justify-between px-3 pt-2 pb-2.5 text-base font-extrabold text-[var(--text)]">
          <span>{{ $t(activeFlyoutGroup.label) }}</span>
          <small class="text-[12.5px] font-semibold text-[var(--muted)]">
            {{ $t('nav.pagesCount', { count: activeGroupItemsCount }) }}
          </small>
        </div>

        <template v-for="(section, sIdx) in activeFlyoutGroup.sections" :key="sIdx">
          <div
            v-if="section.label"
            class="px-3 pt-2.5 pb-1 text-[12.5px] font-semibold text-[var(--muted)]"
            :class="sIdx > 0 ? 'mt-1.5 border-t border-[var(--border)]' : ''"
          >
            {{ $t(section.label) }}
          </div>
          <div
            v-else-if="sIdx > 0"
            class="my-1.5 border-t border-[var(--border)]"
          />

          <router-link
            v-for="item in section.items"
            :key="item.to"
            :to="item.to"
            role="menuitem"
            class="flex w-full items-center gap-2.5 rounded-[10px] px-3 py-[9px] text-start text-[14.5px] font-semibold transition-colors"
            :class="isItemActive(item)
              ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
              : 'text-[var(--text)] hover:bg-[var(--bg)]'"
            @click="onFlyoutLinkClick"
          >
            <span
              class="h-[7px] w-[7px] shrink-0 rounded-full border-2"
              :class="isItemActive(item)
                ? 'border-[var(--crimson)] bg-[var(--crimson)]'
                : 'border-[var(--border)]'"
            />
            <span class="truncate">{{ $t(item.label) }}</span>
          </router-link>
        </template>
      </template>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import BrandLogo from '../common/BrandLogo.vue';
import { ChevronLeft, PanelRight, Search, X } from 'lucide-vue-next';
import { navigation } from '../../config/navigation';
import brand from '../../config/brand';
import { useAuthStore } from '../../stores/auth';

const props = defineProps({
  sidebarOpen: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close-sidebar']);

const route = useRoute();
const router = useRouter();
const { locale, t } = useI18n();
const authStore = useAuthStore();
const isRtl = computed(() => locale.value === 'ar');

const RAIL_STORAGE_KEY = 'sidebar_rail_collapsed';
const isRail = ref(localStorage.getItem(RAIL_STORAGE_KEY) === 'true');

const sidebarRef = ref(null);
const navRef = ref(null);
const searchWrapRef = ref(null);
const searchInputRef = ref(null);
const flyoutRef = ref(null);

const searchQuery = ref('');
const flyoutOpen = ref(false);
const flyoutMode = ref('group'); // 'group' | 'search'
const activeFlyoutGroup = ref(null);
const pinnedKey = ref(null);
const triggerEl = ref(null);
const flyoutStyle = ref({});

let closeTimer = null;

const visibleNavigation = computed(() =>
  navigation
    .map((group) => {
      if (!group.sections) {
        return authStore.hasPermission(group.permission) ? group : null;
      }

      const sections = group.sections
        .map((sec) => ({
          ...sec,
          items: sec.items.filter((item) => authStore.hasPermission(item.permission)),
        }))
        .filter((sec) => sec.items.length > 0);

      return sections.length > 0 ? { ...group, sections } : null;
    })
    .filter(Boolean)
);

const allNavPaths = computed(() =>
  visibleNavigation.value.flatMap((g) =>
    g.sections ? g.sections.flatMap((s) => s.items.map((i) => i.to).filter(Boolean)) : (g.to ? [g.to] : [])
  )
);

function isItemActive(item) {
  if (!item?.to) return false;
  if (route.path === item.to) return true;
  if (item.to === '/') return false;
  if (!route.path.startsWith(`${item.to}/`)) return false;

  return !allNavPaths.value.some(
    (other) => other !== item.to && other.length > item.to.length && (route.path === other || route.path.startsWith(`${other}/`))
  );
}

function isGroupActive(group) {
  if (!group.sections) {
    return isItemActive(group);
  }
  return group.sections.some((sec) => sec.items.some((item) => isItemActive(item)));
}

const activeGroupItemsCount = computed(() => {
  if (!activeFlyoutGroup.value?.sections) return 0;
  return activeFlyoutGroup.value.sections.reduce((acc, sec) => acc + sec.items.length, 0);
});

const allSearchableItems = computed(() => {
  const list = [];
  visibleNavigation.value.forEach((group) => {
    if (!group.sections) {
      list.push({
        label: group.label,
        to: group.to,
        groupLabel: group.label,
      });
      return;
    }
    group.sections.forEach((sec) => {
      sec.items.forEach((item) => {
        list.push({
          label: item.label,
          to: item.to,
          groupLabel: group.label,
        });
      });
    });
  });
  return list;
});

const searchResults = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return [];
  return allSearchableItems.value.filter((item) => {
    const label = t(item.label).toLowerCase();
    const groupLabel = t(item.groupLabel).toLowerCase();
    return label.includes(q) || groupLabel.includes(q);
  });
});

function cancelCloseTimer() {
  if (closeTimer) {
    clearTimeout(closeTimer);
    closeTimer = null;
  }
}

function scheduleCloseFlyout() {
  if (pinnedKey.value) return;
  cancelCloseTimer();
  closeTimer = setTimeout(() => {
    closeFlyout();
  }, 260);
}

function closeFlyout() {
  cancelCloseTimer();
  flyoutOpen.value = false;
  pinnedKey.value = null;
  activeFlyoutGroup.value = null;
  triggerEl.value = null;
}

function closeMobileSidebar() {
  closeFlyout();
  emit('close-sidebar');
}

async function positionFlyout(el) {
  if (!el || !sidebarRef.value) return;
  await nextTick();

  const triggerRect = el.getBoundingClientRect();
  const sbRect = sidebarRef.value.getBoundingClientRect();
  const fyEl = flyoutRef.value;
  const flyoutWidth = fyEl ? fyEl.offsetWidth : 330;
  const flyoutHeight = fyEl ? fyEl.offsetHeight : 280;

  const top = Math.max(12, Math.min(triggerRect.top - 10, window.innerHeight - flyoutHeight - 12));

  let left;
  if (isRtl.value) {
    // Place to the left of the right-hand sidebar; clamp inside viewport for mobile drawers
    const preferredLeft = sbRect.left - flyoutWidth - 8;
    left = Math.max(12, Math.min(preferredLeft, window.innerWidth - flyoutWidth - 12));
  } else {
    // Place to the right of the left-hand sidebar; clamp inside viewport for mobile drawers
    const preferredLeft = sbRect.right + 8;
    left = Math.max(12, Math.min(preferredLeft, window.innerWidth - flyoutWidth - 12));
  }

  flyoutStyle.value = {
    top: `${top}px`,
    left: `${left}px`,
  };
}

function openGroupFlyout(group, el) {
  cancelCloseTimer();
  flyoutMode.value = 'group';
  activeFlyoutGroup.value = group;
  triggerEl.value = el;
  flyoutOpen.value = true;
  positionFlyout(el);
}

function onGroupHover(group, event) {
  cancelCloseTimer();
  if (pinnedKey.value && pinnedKey.value !== group.key) return;
  openGroupFlyout(group, event.currentTarget);
}

function onGroupClick(group, event) {
  if (pinnedKey.value === group.key && flyoutOpen.value) {
    closeFlyout();
    return;
  }
  pinnedKey.value = group.key;
  openGroupFlyout(group, event.currentTarget);
}

function onDirectHover() {
  cancelCloseTimer();
  if (!pinnedKey.value) {
    closeFlyout();
  }
}

function onDirectNavigate() {
  searchQuery.value = '';
  closeFlyout();
  emit('close-sidebar');
}

function onFlyoutLinkClick() {
  searchQuery.value = '';
  closeFlyout();
  emit('close-sidebar');
}

function onSearchInput() {
  const q = searchQuery.value.trim();
  if (!q) {
    closeFlyout();
    return;
  }
  flyoutMode.value = 'search';
  pinnedKey.value = 'search';
  activeFlyoutGroup.value = null;
  triggerEl.value = searchWrapRef.value;
  flyoutOpen.value = true;
  positionFlyout(searchWrapRef.value);
}

function selectFirstSearchResult() {
  if (flyoutMode.value !== 'search' || searchResults.value.length === 0) return;
  const first = searchResults.value[0];
  if (first?.to) {
    router.push(first.to);
    onFlyoutLinkClick();
  }
}

function onSearchWrapClick() {
  if (isRail.value && window.innerWidth >= 1024) {
    isRail.value = false;
    localStorage.setItem(RAIL_STORAGE_KEY, 'false');
    nextTick(() => searchInputRef.value?.focus());
  }
}

function toggleRail() {
  isRail.value = !isRail.value;
  localStorage.setItem(RAIL_STORAGE_KEY, String(isRail.value));
  closeFlyout();
}

function handleDocumentClick(e) {
  if (!flyoutOpen.value) return;
  const target = e.target;
  if (
    flyoutRef.value?.contains(target) ||
    navRef.value?.contains(target) ||
    searchWrapRef.value?.contains(target)
  ) {
    return;
  }
  closeFlyout();
}

function handleKeydown(e) {
  if (e.key === 'Escape') {
    closeFlyout();
    searchInputRef.value?.blur();
    return;
  }
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    if (isRail.value && window.innerWidth >= 1024) {
      isRail.value = false;
      localStorage.setItem(RAIL_STORAGE_KEY, 'false');
    }
    nextTick(() => searchInputRef.value?.focus());
  }
}

function handleResize() {
  if (flyoutOpen.value && triggerEl.value) {
    positionFlyout(triggerEl.value);
  }
}

watch(
  () => route.path,
  () => {
    closeFlyout();
  }
);

onMounted(() => {
  document.addEventListener('click', handleDocumentClick);
  document.addEventListener('keydown', handleKeydown);
  window.addEventListener('resize', handleResize);
});

onBeforeUnmount(() => {
  cancelCloseTimer();
  document.removeEventListener('click', handleDocumentClick);
  document.removeEventListener('keydown', handleKeydown);
  window.removeEventListener('resize', handleResize);
});
</script>
