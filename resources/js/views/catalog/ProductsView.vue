<template>
  <div class="space-y-6">
    <!-- Page header -->
    <PageHeader :title="$t('products.title')" :subtitle="$t('products.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('products.addProduct') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Refresh error (stale data still shown) -->
    <div
      v-if="loadError && products.length"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadProducts">
        {{ $t('products.retry') }}
      </button>
    </div>

    <!-- Products card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="search"
        :search-placeholder="$t('products.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Status -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('common.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('products.statusAll')"
                :active="statusFilter === 'all'"
                @click="statusFilter = 'all'"
              />
              <FilterChip
                :label="$t('products.statusActive')"
                :active="statusFilter === 'active'"
                @click="statusFilter = 'active'"
              />
              <FilterChip
                :label="$t('products.statusInactive')"
                :active="statusFilter === 'inactive'"
                @click="statusFilter = 'inactive'"
              />
            </div>
          </div>

          <!-- Online Shop visibility -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('products.shopFilter') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('products.shopAll')"
                :active="shopFilter === 'all'"
                @click="shopFilter = 'all'"
              />
              <FilterChip
                :label="$t('products.shopVisible')"
                :active="shopFilter === 'visible'"
                @click="shopFilter = 'visible'"
              />
              <FilterChip
                :label="$t('products.shopHidden')"
                :active="shopFilter === 'hidden'"
                @click="shopFilter = 'hidden'"
              />
            </div>
          </div>

          <!-- Category -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('products.categoryFilter') }}</label>
            <div class="relative">
              <select
                v-model="categoryFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('products.categoryFilter')"
              >
                <option value="all">{{ $t('products.allCategories') }}</option>
                <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Bulk actions -->
      <div
        v-if="selectedIds.length"
        class="flex flex-col gap-3 border-b border-[var(--border)] bg-[var(--crimson-soft)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
      >
        <p class="text-sm font-bold text-[var(--text)]">
          {{ $t('products.selectedCount', { count: selectedIds.length }) }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3.5 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
            @click="printSelectedBarcodes"
          >
            <Printer class="h-4 w-4" aria-hidden="true" />
            {{ $t('products.printBarcodes') }}
          </button>
          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[var(--crimson)] px-3.5 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
            @click="deleteSelected"
          >
            <Trash2 class="h-4 w-4" aria-hidden="true" />
            {{ $t('products.bulkDelete') }}
          </button>
          <button
            type="button"
            class="inline-flex h-9 items-center rounded-lg border border-[var(--border)] bg-[var(--card)] px-3.5 text-sm font-semibold text-[var(--muted)] transition-colors hover:text-[var(--text)]"
            @click="selectedIds = []"
          >
            {{ $t('products.clearSelection') }}
          </button>
        </div>
      </div>

      <!-- Table -->
      <div v-if="loading || products.length" class="overflow-x-auto">
        <table class="w-full min-w-[920px] table-fixed text-start">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th scope="col" class="w-11 px-3 py-3.5">
                <AppCheckbox
                  :checked="allPageSelected"
                  :indeterminate="somePageSelected && !allPageSelected"
                  :aria-label="$t('products.selectAll')"
                  @change="toggleAllOnPage"
                />
              </th>
              <th scope="col" class="w-1/4 px-3 py-3.5 text-start">
                {{ $t('products.productCol') }}
              </th>
              <th scope="col" class="px-3 py-3.5 text-start">
                {{ $t('products.barcodeCol') }}
              </th>
              <th scope="col" class="hidden w-[10%] px-3 py-3.5 text-start lg:table-cell">
                {{ $t('products.categoryCol') }}
              </th>
              <th scope="col" class="hidden w-[9%] px-3 py-3.5 text-center md:table-cell">
                {{ $t('products.purchasePriceCol') }}
              </th>
              <th scope="col" class="hidden w-[9%] px-3 py-3.5 text-center md:table-cell">
                {{ $t('products.priceCol') }}
              </th>
              <th scope="col" class="hidden w-[7%] px-3 py-3.5 text-center sm:table-cell">
                {{ $t('products.stockQtyCol') }}
              </th>
              <th scope="col" class="hidden w-[6%] px-3 py-3.5 text-center lg:table-cell">
                {{ $t('products.shopCol') }}
              </th>
              <th scope="col" class="w-[8%] px-3 py-3.5 text-center">
                {{ $t('products.statusCol') }}
              </th>
              <th scope="col" class="w-20 px-3 py-3.5 text-end">
                {{ $t('common.actions') }}
              </th>
            </tr>
          </thead>

          <tbody v-if="loading" aria-busy="true">
            <tr v-for="n in 5" :key="n" class="border-t border-[var(--border)]">
              <td colspan="10" class="px-3 py-4">
                <div class="flex-1 space-y-2">
                  <div class="h-3.5 w-40 animate-pulse rounded bg-[var(--bg)]"></div>
                  <div class="h-3 w-56 max-w-full animate-pulse rounded bg-[var(--bg)]"></div>
                </div>
              </td>
            </tr>
          </tbody>

          <tbody v-else class="divide-y divide-[var(--border)]">
            <tr
              v-for="product in products"
              :key="product.id"
              class="transition-colors"
              :class="isSelected(product) ? 'bg-[var(--crimson-soft)]' : 'hover:bg-[var(--bg)]'"
            >
              <td class="px-3 py-3.5">
                <AppCheckbox
                  :checked="isSelected(product)"
                  :aria-label="$t('products.selectRow', { name: product.name })"
                  @change="toggleRow(product)"
                />
              </td>

              <td class="w-1/4 px-3 py-3.5">
                <div class="min-w-0">
                  <p class="truncate text-sm font-bold text-[var(--text)]" :title="product.name">{{ product.name }}</p>
                  <p class="mt-0.5 truncate text-sm text-[var(--muted)]">
                    {{ product.author || product.publisher || '—' }}
                  </p>
                </div>
              </td>

              <td class="px-3 py-3.5">
                <span class="block truncate font-mono text-sm text-[var(--muted)]" :title="product.barcode || ''">{{ product.barcode || '—' }}</span>
              </td>

              <td class="hidden px-3 py-3.5 lg:table-cell">
                <span v-if="product.category" class="block truncate text-sm text-[var(--muted)]" :title="product.category.name">{{ product.category.name }}</span>
                <span v-else class="text-sm text-[var(--muted)]">{{ $t('products.uncategorized') }}</span>
              </td>

              <td class="hidden px-3 py-3.5 text-center md:table-cell">
                <span class="whitespace-nowrap text-sm font-medium tabular-nums text-[var(--muted)]">{{ formatPrice(product.purchase_price) }}</span>
              </td>

              <td class="hidden px-3 py-3.5 text-center md:table-cell">
                <span class="whitespace-nowrap text-sm font-semibold tabular-nums text-[var(--text)]">{{ formatPrice(product.selling_price) }}</span>
              </td>

              <td class="hidden px-3 py-3.5 text-center sm:table-cell">
                <span
                  class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold tabular-nums"
                  :class="Number(product.warehouse_stocks_sum_quantity ?? 0) > 0
                    ? 'bg-[var(--bg)] text-[var(--text)]'
                    : 'bg-[var(--crimson-soft)] text-[var(--crimson)]'"
                >
                  {{ formatStockQty(product.warehouse_stocks_sum_quantity) }}
                </span>
              </td>

              <td class="hidden px-3 py-3.5 text-center lg:table-cell">
                <span
                  class="inline-flex h-7 w-7 items-center justify-center rounded-full border"
                  :class="
                    product.show_in_shop
                      ? 'border-[var(--success-border)] bg-[var(--success-soft)] text-[var(--success)]'
                      : 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)]'
                  "
                  :title="product.show_in_shop ? $t('products.shopVisible') : $t('products.shopHidden')"
                  :aria-label="product.show_in_shop ? $t('products.shopVisible') : $t('products.shopHidden')"
                >
                  <Check v-if="product.show_in_shop" class="h-4 w-4" aria-hidden="true" />
                  <X v-else class="h-4 w-4" aria-hidden="true" />
                </span>
              </td>

              <td class="px-3 py-3.5 text-center">
                <StatusBadge :active="!!product.is_active" />
              </td>

              <td class="px-3 py-3.5 text-end">
                <div class="flex items-center justify-end gap-1.5">
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :aria-label="$t('products.editProduct', { name: product.name })"
                    :title="$t('common.edit')"
                    @click="openEdit(product)"
                  >
                    <Pencil class="h-4 w-4" aria-hidden="true" />
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]"
                    :aria-label="$t('products.deleteProduct', { name: product.name })"
                    :title="$t('common.delete')"
                    @click="deleteProduct(product)"
                  >
                    <Trash2 class="h-4 w-4" aria-hidden="true" />
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Error state -->
      <div v-else-if="loadError" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <AlertCircle class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">{{ $t('products.loadErrorTitle') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">{{ loadError }}</p>
        <button
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="loadProducts"
        >
          <RotateCw class="h-4 w-4" aria-hidden="true" />
          {{ $t('products.retry') }}
        </button>
      </div>

      <!-- Empty state -->
      <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg)] text-[var(--muted)]">
          <BookOpen v-if="!hasFilters" class="h-6 w-6" aria-hidden="true" />
          <SearchX v-else class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">
          {{ hasFilters ? $t('products.noResultsTitle') : $t('products.emptyTitle') }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ hasFilters ? $t('products.noResultsDesc') : $t('products.emptyDesc') }}
        </p>
        <button
          v-if="hasFilters"
          type="button"
          class="mt-5 inline-flex h-10 items-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40"
          @click="resetFilters"
        >
          {{ $t('products.resetFilters') }}
        </button>
        <button
          v-else
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          {{ $t('products.addProduct') }}
        </button>
      </div>

      <!-- Pagination -->
      <div
        v-if="!loading && totalRecords > 0"
        class="flex flex-col gap-3 border-t border-[var(--border)] px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5"
      >
        <p class="text-sm text-[var(--muted)]">{{ rangeLabel }}</p>

        <div class="flex items-center gap-2">
          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page <= 1"
            @click="page -= 1"
          >
            <component :is="previousIcon" class="h-4 w-4" aria-hidden="true" />
            {{ $t('products.previous') }}
          </button>

          <span class="px-1 text-sm font-medium text-[var(--muted)]">{{ pageOfLabel }}</span>

          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page >= pageCount"
            @click="page += 1"
          >
            {{ $t('products.next') }}
            <component :is="nextIcon" class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>

    <!-- Create / edit modal -->
    <CrudModal
      :show="showModal"
      :title="modalTitle"
      :mode="modalMode"
      :loading="modalLoading"
      size="2xl"
      @close="closeModal"
      @save="saveRecord"
    >
      <CrudForm
        :schema="schema"
        :form-data="formData"
        :errors="formErrors"
        :related-options="{ category_id: categoryOptions }"
      />
    </CrudModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  BookOpen,
  Check,
  ChevronDown,
  Pencil,
  Plus,
  Printer,
  RotateCw,
  SearchX,
  Trash2,
  X,
} from 'lucide-vue-next';
import api from '../../services/api';
import alertService from '../../services/alert';
import { useNotificationStore } from '../../stores/notification';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import AppCheckbox from '../../components/ui/AppCheckbox.vue';
import CrudModal from '../../components/crud/CrudModal.vue';
import CrudForm from '../../components/crud/CrudForm.vue';

const { t, locale } = useI18n();
const router = useRouter();
const notificationStore = useNotificationStore();

const schema = {
  fields: [
    { name: 'name', label: 'products.fields.name', type: 'text', required: true, col: 12 },
    { name: 'barcode', label: 'products.fields.barcode', type: 'text', col: 6 },
    { name: 'category_id', label: 'products.fields.category', type: 'select', col: 6 },
    { name: 'author', label: 'products.fields.author', type: 'text', col: 6 },
    { name: 'publisher', label: 'products.fields.publisher', type: 'text', col: 6 },
    { name: 'edition', label: 'products.fields.edition', type: 'text', col: 6 },
    { name: 'publication_year', label: 'products.fields.publicationYear', type: 'number', col: 6 },
    { name: 'purchase_price', label: 'products.fields.purchasePrice', type: 'currency', required: true, col: 6, default: 0 },
    { name: 'selling_price', label: 'products.fields.sellingPrice', type: 'currency', required: true, col: 6, default: 0 },
    { name: 'cover_image', label: 'products.fields.coverImage', type: 'text', col: 12 },
    { name: 'is_active', label: 'products.fields.isActive', type: 'boolean', col: 6, default: true },
    { name: 'show_in_shop', label: 'products.fields.showInShop', type: 'boolean', col: 6, default: false },
  ],
};

const products = ref([]);
const categoryTree = ref([]);
const stats = ref({ total: 0, active: 0, inactive: 0 });
const loading = ref(true);
const loadError = ref('');

const search = ref('');
const statusFilter = ref('all');
const shopFilter = ref('all');
const categoryFilter = ref('all');

const page = ref(1);
const perPage = 10;
const selectedIds = ref([]);

const totalRecords = ref(0);
const lastPage = ref(1);

const showModal = ref(false);
const modalMode = ref('create');
const modalLoading = ref(false);
const activeRecordId = ref(null);
const formData = ref({});
const formErrors = ref({});

const categoryOptions = computed(() => {
  return categoryTree.value.flatMap((category) => [
    { value: category.id, label: category.name },
    ...(category.children || []).map((child) => ({ value: child.id, label: `— ${child.name}` })),
  ]);
});

const activeFiltersCount = computed(() => {
  let count = 0;
  if (statusFilter.value !== 'all') count++;
  if (shopFilter.value !== 'all') count++;
  if (categoryFilter.value !== 'all') count++;
  return count;
});

const hasFilters = computed(
  () => search.value.trim() !== '' || activeFiltersCount.value > 0
);

const pageCount = computed(() => Math.max(1, lastPage.value));

const allPageSelected = computed(
  () => products.value.length > 0 && products.value.every((product) => selectedIds.value.includes(product.id))
);
const somePageSelected = computed(() => products.value.some((product) => selectedIds.value.includes(product.id)));

const rangeLabel = computed(() => {
  const total = totalRecords.value;
  const from = total === 0 ? 0 : (page.value - 1) * perPage + 1;
  const to = Math.min(page.value * perPage, total);
  return t('products.showingRange', { from, to, total });
});

const pageOfLabel = computed(() => t('products.pageOf', { page: page.value, pages: pageCount.value }));

const previousIcon = computed(() => (locale.value === 'ar' ? ArrowRight : ArrowLeft));
const nextIcon = computed(() => (locale.value === 'ar' ? ArrowLeft : ArrowRight));

const modalTitle = computed(
  () => (modalMode.value === 'create' ? t('products.addProduct') : `${t('common.edit')} ${t('products.productCol')}`)
);

const formatPrice = (value) => {
  const amount = Number(value ?? 0);
  const formatted = new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(amount);

  return `${formatted} ${t('common.currency')}`;
};

const formatStockQty = (value) => {
  const qty = Number(value ?? 0);
  return new Intl.NumberFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 3,
  }).format(qty);
};

const isSelected = (product) => selectedIds.value.includes(product.id);

const loadCategories = async () => {
  try {
    const response = await api.get('/product-categories');
    categoryTree.value = response.data?.data || [];
  } catch {
    categoryTree.value = [];
  }
};

const loadProducts = async () => {
  loading.value = true;
  loadError.value = '';

  try {
    const response = await api.get('/products', {
      params: {
        page: page.value,
        per_page: perPage,
        search: search.value.trim() || undefined,
        category_id: categoryFilter.value !== 'all' ? categoryFilter.value : undefined,
        is_active: statusFilter.value === 'all' ? undefined : statusFilter.value === 'active',
        show_in_shop: shopFilter.value === 'all' ? undefined : shopFilter.value === 'visible',
        sort_by: 'name',
        sort_order: 'asc',
      },
    });

    const payload = response.data;
    products.value = payload.data || [];
    totalRecords.value = Number(payload.meta?.total ?? products.value.length);
    lastPage.value = Number(payload.meta?.last_page ?? 1);

    if (payload.stats) {
      stats.value = payload.stats;
    }

    if (page.value > pageCount.value) {
      page.value = pageCount.value;
    }
  } catch {
    loadError.value = t('products.loadError');
  } finally {
    loading.value = false;
  }
};

const createDefaults = () => ({
  name: '',
  barcode: null,
  category_id: null,
  author: null,
  publisher: null,
  edition: null,
  publication_year: null,
  cover_image: null,
  purchase_price: 0,
  selling_price: 0,
  is_active: true,
  show_in_shop: false,
});

const openCreate = () => {
  modalMode.value = 'create';
  activeRecordId.value = null;
  formErrors.value = {};
  formData.value = createDefaults();
  showModal.value = true;
};

const openEdit = (product) => {
  modalMode.value = 'edit';
  activeRecordId.value = product.id;
  formErrors.value = {};
  formData.value = {
    name: product.name ?? '',
    barcode: product.barcode ?? null,
    category_id: product.category_id ?? null,
    author: product.author ?? null,
    publisher: product.publisher ?? null,
    edition: product.edition ?? null,
    publication_year: product.publication_year ?? null,
    cover_image: product.cover_image ?? null,
    purchase_price: Number(product.purchase_price ?? 0),
    selling_price: Number(product.selling_price ?? 0),
    is_active: !!product.is_active,
    show_in_shop: !!product.show_in_shop,
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  formErrors.value = {};
};

const saveRecord = async () => {
  formErrors.value = {};

  const purchasePrice = Number(formData.value.purchase_price);
  const sellingPrice = Number(formData.value.selling_price);
  if (
    formData.value.purchase_price !== null &&
    formData.value.purchase_price !== '' &&
    formData.value.selling_price !== null &&
    formData.value.selling_price !== '' &&
    Number.isFinite(purchasePrice) &&
    Number.isFinite(sellingPrice) &&
    sellingPrice <= purchasePrice
  ) {
    formErrors.value = {
      selling_price: [t('products.validation.sellingPriceGtPurchase')],
    };
    notificationStore.error(t('products.validation.sellingPriceGtPurchase'), t('common.errorTitle'));
    return;
  }

  modalLoading.value = true;

  try {
    const response =
      modalMode.value === 'create'
        ? await api.post('/products', formData.value)
        : await api.put(`/products/${activeRecordId.value}`, formData.value);

    notificationStore.success(response.data.message || t('common.successSave'), t('common.successTitle'));
    closeModal();
    await loadProducts();
    await loadCategories();
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      formErrors.value = err.response.data.errors;
      notificationStore.error(t('common.validationError'), t('common.errorTitle'));
    } else {
      notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
    }
  } finally {
    modalLoading.value = false;
  }
};

const toggleAllOnPage = () => {
  const pageIds = products.value.map((product) => product.id);

  if (allPageSelected.value) {
    selectedIds.value = selectedIds.value.filter((id) => !pageIds.includes(id));
    return;
  }

  selectedIds.value = Array.from(new Set([...selectedIds.value, ...pageIds]));
};

const toggleRow = (product) => {
  if (selectedIds.value.includes(product.id)) {
    selectedIds.value = selectedIds.value.filter((id) => id !== product.id);
    return;
  }

  selectedIds.value = [...selectedIds.value, product.id];
};

const deleteProduct = async (product) => {
  const confirmed = await alertService.confirmDelete({
    title: t('products.deleteTitle'),
    text: t('products.deleteConfirm', { name: product.name }),
    confirmButtonText: t('common.delete'),
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    const response = await api.delete(`/products/${product.id}`);
    selectedIds.value = selectedIds.value.filter((id) => id !== product.id);
    notificationStore.success(response.data.message || t('common.successDelete'), t('common.successTitle'));
    await loadProducts();
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }
};

const printSelectedBarcodes = () => {
  router.push({ name: 'products.barcodes', query: { ids: selectedIds.value.slice(0, 100).join(',') } });
};

const deleteSelected = async () => {
  if (!selectedIds.value.length) return;

  const confirmed = await alertService.confirmDelete({
    title: t('products.bulkDeleteTitle'),
    text: t('products.bulkDeleteConfirm', { count: selectedIds.value.length }),
    confirmButtonText: `${t('common.delete')} (${selectedIds.value.length})`,
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    await api.delete(`/products/${selectedIds.value.join(',')}`);
    notificationStore.success(t('products.bulkDeleted', { count: selectedIds.value.length }), t('common.successTitle'));
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }

  selectedIds.value = [];
  await loadProducts();
};

const resetFilters = () => {
  const hadFilters = search.value !== '' || statusFilter.value !== 'all' || shopFilter.value !== 'all' || categoryFilter.value !== 'all';
  search.value = '';
  statusFilter.value = 'all';
  shopFilter.value = 'all';
  categoryFilter.value = 'all';
  if (!hadFilters && page.value !== 1) {
    page.value = 1;
  }
};

let searchDebounce = null;
watch([search, statusFilter, shopFilter, categoryFilter], () => {
  selectedIds.value = [];
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    if (page.value !== 1) {
      page.value = 1;
    } else {
      loadProducts();
    }
  }, 300);
});

watch(page, () => {
  loadProducts();
});

watch(pageCount, (count) => {
  if (page.value > count) page.value = count;
});

onMounted(async () => {
  await Promise.all([loadProducts(), loadCategories()]);
});
</script>
