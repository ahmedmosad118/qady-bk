<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('activities.title')" :subtitle="$t('activities.subtitle')">
      <template #actions>
        <button
          v-if="canClear && (meta?.total ?? 0) > 0"
          type="button"
          :disabled="clearing"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 text-sm font-bold text-rose-700 transition-colors hover:bg-rose-500/20 disabled:cursor-not-allowed disabled:opacity-50 dark:text-rose-300 cursor-pointer"
          @click="handleClearAll"
        >
          <Trash2 class="h-4 w-4" aria-hidden="true" />
          <span>{{ $t('activities.clearAll') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Refresh Error Banner -->
    <div
      v-if="loadError && items.length"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadActivities">
        {{ $t('activities.retry') }}
      </button>
    </div>

    <!-- Main Table Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Search & Filter Drawer -->
      <FilterToolbar
        v-model:search="search"
        :search-placeholder="$t('activities.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        :drawer-title="$t('activities.filtersDrawerTitle')"
        :drawer-subtitle="$t('activities.filtersDrawerSubtitle')"
        @reset="resetFilters"
        @apply="applyDrawerFilters"
      >
        <div class="space-y-4">
          <!-- Action Type -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('activities.actionFilterLabel') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('activities.allActions')"
                :active="eventFilter === ''"
                @click="eventFilter = ''"
              />
              <FilterChip
                :label="$t('activities.additions')"
                :active="eventFilter === 'created'"
                @click="eventFilter = 'created'"
              />
              <FilterChip
                :label="$t('activities.modifications')"
                :active="eventFilter === 'updated'"
                @click="eventFilter = 'updated'"
              />
              <FilterChip
                :label="$t('activities.deletions')"
                :active="eventFilter === 'deleted'"
                @click="eventFilter = 'deleted'"
              />
            </div>
          </div>

          <!-- Module Filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('activities.filterByModule') }}</label>
            <div class="relative">
              <select
                v-model="moduleFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('activities.filterByModule')"
              >
                <option value="">{{ $t('activities.allModules') }}</option>
                <option v-for="m in modulesList" :key="m.key" :value="m.key">{{ m.label }}</option>
              </select>
              <ChevronDown class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
            </div>
          </div>

          <!-- User Filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('activities.filterByUser') }}</label>
            <div class="relative">
              <select
                v-model="causerFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('activities.filterByUser')"
              >
                <option value="">{{ $t('activities.allUsers') }}</option>
                <option v-for="u in usersList" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
              </select>
              <ChevronDown class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
            </div>
          </div>

          <!-- Month Filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('activities.filterByMonth') }}</label>
            <div class="relative">
              <select
                v-model="monthFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('activities.filterByMonth')"
              >
                <option value="">{{ $t('activities.allMonths') }}</option>
                <option v-for="mb in monthlyBreakdown" :key="mb.key" :value="mb.key">
                  {{ mb.label }}
                </option>
              </select>
              <ChevronDown class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]" aria-hidden="true" />
            </div>
          </div>

          <!-- Date Range -->
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('activities.dateFrom') }}</label>
              <input
                v-model="dateFrom"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              />
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('activities.dateTo') }}</label>
              <input
                v-model="dateTo"
                type="date"
                class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Table / Error / Empty -->
      <ActivityTable
        v-if="loading || items.length"
        :items="items"
        :loading="loading"
      />

      <div v-else-if="loadError" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <AlertCircle class="h-6 w-6" aria-hidden="true" />
        </span>
        <p class="mt-4 text-sm font-semibold text-[var(--muted)]">{{ loadError }}</p>
        <button
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadActivities"
        >
          <RotateCw class="h-4 w-4" aria-hidden="true" />
          {{ $t('activities.retry') }}
        </button>
      </div>

      <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg)] text-[var(--muted)]">
          <Activity class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">{{ $t('activities.emptyTitle') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">{{ $t('activities.emptySubtitle') }}</p>
      </div>

      <!-- Pagination -->
      <PaginationBar :meta="meta" @change-page="goToPage" />
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Activity, AlertCircle, ChevronDown, RotateCw, Trash2 } from 'lucide-vue-next';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import PaginationBar from '../../components/ui/PaginationBar.vue';
import ActivityTable from '../../components/activities/ActivityTable.vue';
import { clearAllActivities, fetchActivities } from '../../services/activityApi';
import { confirmDelete } from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import { useNotificationStore } from '../../stores/notification';
import { extractApiError } from '../../utils/apiError';

const { t } = useI18n();
const authStore = useAuthStore();
const notify = useNotificationStore();

const canClear = computed(() => authStore.hasPermission('activities.clear'));

const items = ref([]);
const analytics = ref(null);
const meta = ref({ current_page: 1, last_page: 1, per_page: 15, total: 0 });
const loading = ref(false);
const clearing = ref(false);
const loadError = ref('');

const search = ref('');
const eventFilter = ref('');
const moduleFilter = ref('');
const causerFilter = ref('');
const monthFilter = ref('');
const dateFrom = ref('');
const dateTo = ref('');
const currentPage = ref(1);

let searchTimer = null;

const modulesList = computed(() => analytics.value?.modules_list || []);
const usersList = computed(() => analytics.value?.users_list || []);
const monthlyBreakdown = computed(() => analytics.value?.monthly_breakdown || []);

const activeFiltersCount = computed(() => {
  let count = 0;
  if (eventFilter.value) count++;
  if (moduleFilter.value) count++;
  if (causerFilter.value) count++;
  if (monthFilter.value) count++;
  if (dateFrom.value || dateTo.value) count++;
  return count;
});

function buildQueryParams() {
  const params = {
    page: currentPage.value,
    per_page: 15,
  };
  if (search.value.trim()) params.search = search.value.trim();
  if (eventFilter.value) params.event = eventFilter.value;
  if (moduleFilter.value) params.module = moduleFilter.value;
  if (causerFilter.value) params.causer_id = Number(causerFilter.value);
  if (monthFilter.value) params.month = monthFilter.value;
  if (dateFrom.value) params.date_from = dateFrom.value;
  if (dateTo.value) params.date_to = dateTo.value;
  return params;
}

async function loadActivities() {
  loading.value = true;
  loadError.value = '';
  try {
    const response = await fetchActivities(buildQueryParams());
    const payload = response.data || {};
    items.value = payload.data || [];
    meta.value = payload.meta || { current_page: 1, last_page: 1, per_page: 15, total: 0 };
    analytics.value = payload.analytics || null;
  } catch (err) {
    loadError.value = extractApiError(err, t('activities.loadError'));
  } finally {
    loading.value = false;
  }
}

function goToPage(page) {
  currentPage.value = page;
  loadActivities();
}

function applyDrawerFilters() {
  currentPage.value = 1;
  loadActivities();
}

function resetFilters() {
  search.value = '';
  eventFilter.value = '';
  moduleFilter.value = '';
  causerFilter.value = '';
  monthFilter.value = '';
  dateFrom.value = '';
  dateTo.value = '';
  currentPage.value = 1;
  loadActivities();
}

async function handleClearAll() {
  const confirmed = await confirmDelete({
    title: t('activities.clearConfirmTitle'),
    text: t('activities.clearConfirmText'),
    confirmButtonText: t('activities.clearConfirmBtn'),
  });
  if (!confirmed) return;

  clearing.value = true;
  try {
    await clearAllActivities();
    notify.success(t('activities.clearedSuccess'));
    currentPage.value = 1;
    await loadActivities();
  } catch (err) {
    notify.error(extractApiError(err, t('activities.loadError')));
  } finally {
    clearing.value = false;
  }
}

watch(search, () => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    currentPage.value = 1;
    loadActivities();
  }, 300);
});

onMounted(() => {
  loadActivities();
});

onBeforeUnmount(() => {
  if (searchTimer) clearTimeout(searchTimer);
});
</script>
