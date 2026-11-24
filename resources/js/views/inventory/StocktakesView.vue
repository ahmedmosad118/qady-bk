<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('stocktaking.title')" :subtitle="$t('stocktaking.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateModal = true"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('stocktaking.createButton') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Error Banner -->
    <div
      v-if="loadError"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadStocktakes">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- Main Table Container -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Compact Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('stocktaking.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedSearch"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Warehouse filter (Only show if multiple warehouses exist) -->
          <div v-if="warehouses.length > 1">
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('stocktaking.fields.warehouse') }}</label>
            <SearchableSelect
              v-model="filters.warehouse_id"
              :fetch-options="fetchWarehouseOptions"
              :paginate="10"
              :placeholder="$t('stocktaking.allWarehouses')"
              :search-placeholder="$t('stocktaking.searchWarehouse')"
              :clearable="true"
              @update:model-value="meta.current_page = 1; loadStocktakes()"
            />
          </div>

          <!-- Status filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('stocktaking.fields.status') }}</label>
            <SearchableSelect
              v-model="filters.status"
              :options="statusFilterOptions"
              :placeholder="$t('stocktaking.allStatuses')"
              :search-placeholder="$t('stocktaking.searchStatus')"
              :clearable="true"
              @update:model-value="meta.current_page = 1; loadStocktakes()"
            />
          </div>

          <!-- Scope filter -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('stocktaking.fields.scope') }}</label>
            <SearchableSelect
              v-model="filters.scope"
              :options="scopeFilterOptions"
              :placeholder="$t('stocktaking.allScopes')"
              :search-placeholder="$t('stocktaking.searchScope')"
              :clearable="true"
              @update:model-value="meta.current_page = 1; loadStocktakes()"
            />
          </div>
        </div>
      </FilterToolbar>

      <!-- Table Section -->
      <div class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="w-12 px-4 py-3 text-center">#</th>
              <th class="min-w-[140px] px-4 py-3">{{ $t('stocktaking.fields.number') }}</th>
              <th v-if="warehouses.length > 1" class="min-w-[130px] px-4 py-3">{{ $t('stocktaking.fields.warehouse') }}</th>
              <th class="min-w-[110px] px-4 py-3 text-center">{{ $t('stocktaking.fields.scope') }}</th>
              <th class="min-w-[110px] px-4 py-3 text-center">{{ $t('stocktaking.fields.date') }}</th>
              <th class="min-w-[140px] px-4 py-3 text-center">{{ $t('stocktaking.fields.progress') }}</th>
              <th class="min-w-[110px] px-4 py-3 text-center">{{ $t('stocktaking.fields.status') }}</th>
              <th class="w-20 px-4 py-3 text-center">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-[var(--border)]">
            <tr v-if="loading && stocktakes.length === 0">
              <td colspan="8" class="p-12 text-center text-sm text-[var(--muted)]">
                <span class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                <span class="block mt-2 font-bold">{{ $t('common.loading') }}</span>
              </td>
            </tr>
            <tr v-else-if="stocktakes.length === 0">
              <td :colspan="warehouses.length > 1 ? 8 : 7" class="p-12 text-center text-sm text-[var(--muted)]">
                <ClipboardCheck class="mx-auto h-12 w-12 text-[var(--muted)]/40 mb-3" />
                <p class="font-extrabold text-[var(--text)] text-base">{{ $t('stocktaking.noStocktakes') }}</p>
                <p class="text-xs mt-1">{{ $t('stocktaking.noStocktakesHint') }}</p>
              </td>
            </tr>
            <tr
              v-for="(st, idx) in stocktakes"
              :key="st.id"
              class="hover:bg-[var(--bg)]/50 transition-colors cursor-pointer"
              @click="openStocktakeDrawer(st.id)"
            >
              <td class="px-4 py-3.5 text-center text-xs font-bold text-[var(--muted)]">
                {{ (meta.current_page - 1) * meta.per_page + idx + 1 }}
              </td>
              <td class="px-4 py-3.5 font-mono font-black text-[var(--text)]">
                {{ st.stocktake_number }}
              </td>
              <td v-if="warehouses.length > 1" class="px-4 py-3.5 font-bold text-[var(--text)]">
                {{ st.warehouse?.name || '—' }}
              </td>
              <td class="px-4 py-3.5 text-center">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[var(--bg)] text-[var(--muted)] border border-[var(--border)]">
                  {{ st.scope === 'full' ? $t('stocktaking.scopeFullShort') : $t('stocktaking.scopeSelectedShort') }}
                </span>
              </td>
              <td class="px-4 py-3.5 text-center text-xs text-[var(--muted)]">
                {{ formatDate(st.stocktake_date) }}
              </td>
              <td class="px-4 py-3.5 text-center">
                <div class="flex items-center justify-center gap-2">
                  <div class="w-20 bg-[var(--bg)] rounded-full h-2 border border-[var(--border)] overflow-hidden">
                    <div
                      class="bg-emerald-500 h-full rounded-full transition-all"
                      :style="{ width: `${progressPercentage(st)}%` }"
                    ></div>
                  </div>
                  <span class="text-xs font-mono font-bold text-[var(--muted)]">
                    {{ st.counted_items_count || 0 }}/{{ st.total_items_count || 0 }}
                  </span>
                </div>
              </td>
              <td class="px-4 py-3.5 text-center">
                <span
                  class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                  :class="statusBadgeClass(st.status)"
                >
                  <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                  {{ statusLabel(st.status) }}
                </span>
              </td>
              <td class="px-4 py-3.5 text-center" @click.stop>
                <button
                  type="button"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--bg)] text-[var(--text)] hover:border-[var(--crimson)] hover:text-[var(--crimson)] transition-colors cursor-pointer"
                  :title="$t('stocktaking.viewAndTrack')"
                  @click="openStocktakeDrawer(st.id)"
                >
                  <Eye class="h-4 w-4" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <TablePagination
        :meta="meta"
        :items-count="stocktakes.length"
        :per-page="meta.per_page"
        :loading="loading"
        @update:per-page="meta.per_page = $event"
        @change-per-page="fetchStocktakes(1)"
        @change-page="changePage"
      />
    </div>

    <!-- Modals -->
    <StocktakeFormModal
      :open="openCreateModal"
      :warehouses="warehouses"
      :products="products"
      @close="openCreateModal = false"
      @created="onStocktakeCreated"
    />

    <StocktakeDetailModal
      :open="openDetailDrawer"
      :stocktake-id="selectedStocktakeId"
      @close="openDetailDrawer = false"
      @updated="loadStocktakes"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ClipboardCheck,
  Eye,
  Plus,
} from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import StocktakeFormModal from './StocktakeFormModal.vue';
import StocktakeDetailModal from './StocktakeDetailModal.vue';
import api from '../../services/api';
import { createWarehouseFetcher } from '../../utils/selectFetchers';
import {
  stocktakeStatusBadgeClass as statusBadgeClass,
  stocktakeStatusLabel as statusLabel,
} from '../../utils/stocktakeStatus';
import { formatDateOnly as formatDate } from '../../utils/date';

const { t } = useI18n();
const route = useRoute();
const loading = ref(false);
const loadError = ref('');
const stocktakes = ref([]);
const warehouses = ref([]);
const products = ref([]);

const openCreateModal = ref(false);
const openDetailDrawer = ref(false);
const selectedStocktakeId = ref(null);

const stats = reactive({
  total: 0,
  in_progress: 0,
  pending_approval: 0,
  completed: 0,
});

const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const filters = reactive({
  search: '',
  warehouse_id: null,
  status: null,
  scope: null,
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.warehouse_id) count++;
  if (filters.status) count++;
  if (filters.scope) count++;
  return count;
});

function resetFilters() {
  filters.search = '';
  filters.warehouse_id = null;
  filters.status = null;
  filters.scope = null;
  meta.current_page = 1;
  loadStocktakes();
}

let searchTimeout = null;
function debouncedSearch() {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    meta.current_page = 1;
    loadStocktakes();
  }, 300);
}

const warehouseFilterOptions = computed(() =>
  warehouses.value.map(w => ({
    value: w.id,
    label: `${w.name} (${w.code})`,
  }))
);

const statusFilterOptions = computed(() => [
  { value: 'draft', label: t('stocktaking.statuses.draft') },
  { value: 'in_progress', label: t('stocktaking.statuses.in_progress') },
  { value: 'pending_approval', label: t('stocktaking.statuses.pending_approval') },
  { value: 'approved', label: t('stocktaking.statuses.approved') },
  { value: 'rejected', label: t('stocktaking.statuses.rejected') },
  { value: 'cancelled', label: t('stocktaking.statuses.cancelled') },
  { value: 'completed', label: t('stocktaking.statuses.completed') },
]);

const scopeFilterOptions = computed(() => [
  { value: 'full', label: t('stocktaking.scopeFullShort') },
  { value: 'selected', label: t('stocktaking.scopeSelectedShort') },
]);

async function loadStocktakes() {
  loading.value = true;
  loadError.value = '';
  try {
    const params = {
      page: meta.current_page,
      per_page: meta.per_page,
      search: filters.search || undefined,
      warehouse_id: filters.warehouse_id || undefined,
      status: filters.status || undefined,
      scope: filters.scope || undefined,
    };

    const res = await api.get('/stocktakes', { params });
    stocktakes.value = res.data.data;

    if (res.data.meta) {
      meta.current_page = res.data.meta.current_page;
      meta.last_page = res.data.meta.last_page;
      meta.total = res.data.meta.total;
    }

    if (res.data.stats) {
      stats.total = res.data.stats.total;
      stats.in_progress = res.data.stats.in_progress;
      stats.pending_approval = res.data.stats.pending_approval;
      stats.completed = res.data.stats.completed;
    }
  } catch (err) {
    loadError.value = err.response?.data?.message || t('stocktaking.loadError');
  } finally {
    loading.value = false;
  }
}

const fetchWarehouseOptions = createWarehouseFetcher({ activeOnly: true });

async function loadInitialData() {
  try {
    const whRes = await api.get('/warehouses');
    warehouses.value = whRes.data.data || [];
  } catch (e) {
    // handled gracefully
  }
}

function openStocktakeDrawer(id) {
  selectedStocktakeId.value = id;
  openDetailDrawer.value = true;
}

function onStocktakeCreated(newSt) {
  loadStocktakes();
  if (newSt?.id) {
    openStocktakeDrawer(newSt.id);
  }
}

function changePage(p) {
  meta.current_page = p;
  loadStocktakes();
}

function progressPercentage(st) {
  const total = st.total_items_count || 0;
  if (!total) return 0;
  const counted = st.counted_items_count || 0;
  return Math.min(100, Math.round((counted / total) * 100));
}

onMounted(() => {
  loadInitialData();
  loadStocktakes();
  if (route.query.open) {
    openStocktakeDrawer(Number(route.query.open));
  }
});
</script>
