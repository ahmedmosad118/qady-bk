<template>
  <div class="space-y-6">
    <!-- Page header -->
    <PageHeader :title="pageTitle" :subtitle="pageSubtitle">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ createButtonText }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Refresh error (stale data still shown) -->
    <div
      v-if="loadError && contacts.length"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto cursor-pointer" @click="loadContacts">
        {{ $t('contacts.retry') }}
      </button>
    </div>

    <!-- Contacts card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Toolbar & Filter Drawer -->
      <FilterToolbar
        v-model:search="search"
        :search-placeholder="searchPlaceholder"
        :active-filters-count="activeFiltersCount"
        @reset="resetFilters"
      >
        <div class="space-y-4">
          <!-- Status -->
          <div>
            <label class="mb-2 block text-xs font-bold text-[var(--muted)]">{{ $t('common.status') }}</label>
            <div class="flex flex-wrap gap-2">
              <FilterChip
                :label="$t('contacts.statusAll')"
                :active="statusFilter === 'all'"
                @click="statusFilter = 'all'"
              />
              <FilterChip
                :label="$t('contacts.statusActive')"
                :active="statusFilter === 'active'"
                @click="statusFilter = 'active'"
              />
              <FilterChip
                :label="$t('contacts.statusInactive')"
                :active="statusFilter === 'inactive'"
                @click="statusFilter = 'inactive'"
              />
            </div>
          </div>

          <!-- Role filter only shown in general contacts view -->
          <div v-if="activeRole === 'all'">
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('contacts.roleFilter') }}</label>
            <div class="relative">
              <select
                v-model="roleFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('contacts.roleFilter')"
              >
                <option value="all">{{ $t('contacts.roleAll') }}</option>
                <option value="customer">{{ $t('contacts.roleCustomer') }}</option>
                <option value="supplier">{{ $t('contacts.roleSupplier') }}</option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>
          </div>

          <!-- Type Filter (Company / Individual) -->
          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">{{ $t('contacts.typeFilter') }}</label>
            <div class="relative">
              <select
                v-model="typeFilter"
                class="h-11 w-full cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--bg)] pe-9 ps-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('contacts.typeFilter')"
              >
                <option value="all">{{ $t('contacts.allTypes') }}</option>
                <option value="individual">{{ $t('contacts.typeIndividual') }}</option>
                <option value="company">{{ $t('contacts.typeCompany') }}</option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute end-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>
          </div>
        </div>
      </FilterToolbar>

      <!-- Table -->
      <div v-if="loading || contacts.length" class="overflow-x-auto">
        <table class="w-full min-w-[820px] table-auto text-start">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th scope="col" class="px-4 py-3.5 text-start">
                {{ contactColHeader }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-center sm:table-cell">
                {{ $t('contacts.typeCol') }}
              </th>
              <th
                v-if="activeRole === 'all'"
                scope="col"
                class="px-4 py-3.5 text-start"
              >
                {{ $t('contacts.rolesCol') }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-start lg:table-cell">
                {{ $t('contacts.phoneCol') }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-start xl:table-cell">
                {{ $t('contacts.emailCol') }}
              </th>
              <th scope="col" class="px-4 py-3.5 text-center">
                {{ $t('contacts.statusCol') }}
              </th>
              <th scope="col" class="w-24 px-4 py-3.5 text-end">
                {{ $t('common.actions') }}
              </th>
            </tr>
          </thead>

          <tbody v-if="loading" aria-busy="true">
            <tr v-for="n in 5" :key="n" class="border-t border-[var(--border)]">
              <td :colspan="activeRole === 'all' ? 7 : 6" class="px-4 py-4">
                <div class="flex items-center gap-3">
                  <div class="h-10 w-10 animate-pulse rounded-full bg-[var(--bg)]"></div>
                  <div class="flex-1 space-y-2">
                    <div class="h-3.5 w-40 animate-pulse rounded bg-[var(--bg)]"></div>
                    <div class="h-3 w-56 max-w-full animate-pulse rounded bg-[var(--bg)]"></div>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>

          <tbody v-else class="divide-y divide-[var(--border)]">
            <tr
              v-for="contact in contacts"
              :key="contact.id"
              class="transition-colors hover:bg-[var(--bg)]/40"
            >
              <td class="px-4 py-3.5">
                <div class="flex items-center gap-3">
                  <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-xs font-bold transition-transform"
                    :class="
                      contact.is_supplier && !contact.is_customer
                        ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400'
                        : 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
                    "
                    aria-hidden="true"
                  >
                    {{ initials(contact.name) }}
                  </span>
                  <div class="min-w-0">
                    <div class="flex items-center gap-2">
                      <p class="truncate text-sm font-bold text-[var(--text)]">{{ contact.name }}</p>
                      <span
                        v-if="activeRole === 'customer' && contact.is_supplier"
                        class="inline-flex shrink-0 items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-bold text-amber-700 dark:text-amber-400"
                      >
                        {{ $t('contacts.alsoSupplier') }}
                      </span>
                      <span
                        v-else-if="activeRole === 'supplier' && contact.is_customer"
                        class="inline-flex shrink-0 items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-bold text-emerald-700 dark:text-emerald-400"
                      >
                        {{ $t('contacts.alsoCustomer') }}
                      </span>
                    </div>
                    <p v-if="contact.company_contact_person" class="truncate text-xs text-[var(--muted)]">
                      {{ contact.company_contact_person }}
                    </p>
                  </div>
                </div>
              </td>

              <td class="hidden px-4 py-3.5 text-center sm:table-cell">
                <span class="inline-flex rounded-md bg-[var(--bg)] px-2 py-0.5 text-xs font-semibold text-[var(--muted)]">
                  {{ typeLabel(contact.type) }}
                </span>
              </td>

              <td v-if="activeRole === 'all'" class="px-4 py-3.5">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span
                    v-if="contact.is_customer"
                    class="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-bold text-emerald-700 dark:text-emerald-400"
                  >
                    {{ $t('contacts.customerBadge') }}
                  </span>
                  <span
                    v-if="contact.is_supplier"
                    class="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-bold text-amber-700 dark:text-amber-400"
                  >
                    {{ $t('contacts.supplierBadge') }}
                  </span>
                </div>
              </td>

              <td class="hidden px-4 py-3.5 lg:table-cell">
                <span v-if="contact.mobile || contact.phone" class="font-mono text-sm text-[var(--muted)]">
                  {{ contact.mobile || contact.phone }}
                </span>
                <span v-else class="text-sm text-[var(--muted)]">—</span>
              </td>

              <td class="hidden px-4 py-3.5 xl:table-cell">
                <span v-if="contact.email" class="truncate text-sm text-[var(--muted)]">{{ contact.email }}</span>
                <span v-else class="text-sm text-[var(--muted)]">—</span>
              </td>

              <td class="px-4 py-3.5 text-center">
                <StatusBadge :active="!!contact.is_active" />
              </td>

              <td class="px-4 py-3.5 text-end">
                <div class="flex items-center justify-end gap-1.5">
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    :aria-label="$t('contacts.editContact', { name: contact.name })"
                    :title="$t('common.edit')"
                    @click="openEdit(contact)"
                  >
                    <Pencil class="h-4 w-4" aria-hidden="true" />
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] cursor-pointer"
                    :aria-label="$t('contacts.deleteContact', { name: contact.name })"
                    :title="$t('common.delete')"
                    @click="deleteContact(contact)"
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
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">{{ $t('contacts.loadErrorTitle') }}</h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">{{ loadError }}</p>
        <button
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadContacts"
        >
          <RotateCw class="h-4 w-4" aria-hidden="true" />
          {{ $t('contacts.retry') }}
        </button>
      </div>

      <!-- Empty state -->
      <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg)] text-[var(--muted)]">
          <UsersRound v-if="!hasFilters" class="h-6 w-6" aria-hidden="true" />
          <SearchX v-else class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">
          {{ hasFilters ? $t('contacts.noResultsTitle') : emptyStateTitle }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ hasFilters ? $t('contacts.noResultsDesc') : emptyStateDesc }}
        </p>
        <button
          v-if="hasFilters"
          type="button"
          class="mt-5 inline-flex h-10 items-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 cursor-pointer"
          @click="resetFilters"
        >
          {{ $t('contacts.resetFilters') }}
        </button>
        <button
          v-else
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreate"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          <span>{{ createButtonText }}</span>
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
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:cursor-not-allowed disabled:opacity-40 cursor-pointer"
            :disabled="page <= 1"
            @click="page -= 1"
          >
            <component :is="previousIcon" class="h-4 w-4" aria-hidden="true" />
            {{ $t('contacts.previous') }}
          </button>

          <span class="px-1 text-sm font-medium text-[var(--muted)]">{{ pageOfLabel }}</span>

          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:cursor-not-allowed disabled:opacity-40 cursor-pointer"
            :disabled="page >= pageCount"
            @click="page += 1"
          >
            {{ $t('contacts.next') }}
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
      <CrudForm :schema="activeSchema" :form-data="formData" :errors="formErrors" />
    </CrudModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  ChevronDown,
  Pencil,
  Plus,
  RotateCw,
  SearchX,
  Trash2,
  UsersRound,
} from 'lucide-vue-next';
import api from '../../services/api';
import alertService from '../../services/alert';
import { useNotificationStore } from '../../stores/notification';
import FilterChip from '../../components/ui/FilterChip.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import CrudModal from '../../components/crud/CrudModal.vue';
import CrudForm from '../../components/crud/CrudForm.vue';

const props = defineProps({
  role: {
    type: String,
    default: '',
  },
});

const route = useRoute();
const { t, locale } = useI18n();
const notificationStore = useNotificationStore();

/**
 * Determine the active role mode:
 * 'customer' -> Dedicated customers screen (/customers)
 * 'supplier' -> Dedicated suppliers screen (/suppliers)
 * 'all'      -> Comprehensive directory (/contacts)
 */
const activeRole = computed(() => {
  if (props.role === 'customer' || props.role === 'supplier') {
    return props.role;
  }
  const p = route.path.toLowerCase();
  if (p.includes('/customer')) return 'customer';
  if (p.includes('/supplier')) return 'supplier';
  return 'all';
});

const pageTitle = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.customersTitle');
  if (activeRole.value === 'supplier') return t('contacts.suppliersTitle');
  return t('contacts.title');
});

const pageSubtitle = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.customersSubtitle');
  if (activeRole.value === 'supplier') return t('contacts.suppliersSubtitle');
  return t('contacts.subtitle');
});

const createButtonText = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.addCustomer');
  if (activeRole.value === 'supplier') return t('contacts.addSupplier');
  return t('contacts.addContact');
});

const contactColHeader = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.customerCol');
  if (activeRole.value === 'supplier') return t('contacts.supplierCol');
  return t('contacts.contactCol');
});

const searchPlaceholder = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.searchCustomers');
  if (activeRole.value === 'supplier') return t('contacts.searchSuppliers');
  return t('contacts.searchPlaceholder');
});

const emptyStateTitle = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.emptyCustomersTitle');
  if (activeRole.value === 'supplier') return t('contacts.emptySuppliersTitle');
  return t('contacts.emptyTitle');
});

const emptyStateDesc = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.emptyCustomersDesc');
  if (activeRole.value === 'supplier') return t('contacts.emptySuppliersDesc');
  return t('contacts.emptyDesc');
});

const isIndividual = computed(() => (formData.value?.type || 'individual') === 'individual');

// Dynamic Schema: in dedicated customer or supplier screen, we OMIT the confusing boolean toggles!
const activeSchema = computed(() => {
  const isDedicated = activeRole.value === 'customer' || activeRole.value === 'supplier';
  const hideCompanyFields = isIndividual.value;

  const fields = [
    {
      name: 'type',
      label: 'contacts.fields.type',
      type: 'select',
      required: true,
      col: 6,
      options: [
        { value: 'individual', label: 'contacts.typeIndividual' },
        { value: 'company', label: 'contacts.typeCompany' },
      ],
    },
    {
      name: 'name',
      label: activeRole.value === 'supplier' ? 'contacts.fields.supplierName' : activeRole.value === 'customer' ? 'contacts.fields.customerName' : 'contacts.fields.name',
      type: 'text',
      required: true,
      col: 6,
    },
  ];

  fields.push(
    { name: 'email', label: 'contacts.fields.email', type: 'email', col: 6 },
    { name: 'mobile', label: 'contacts.fields.mobile', type: 'tel', required: true, col: 6 }
  );

  if (!hideCompanyFields) {
    fields.push(
      {
        name: 'company_contact_person',
        label: activeRole.value === 'supplier' ? 'contacts.fields.supplierContactPerson' : 'contacts.fields.companyContactPerson',
        type: 'text',
        col: 6,
      },
      { name: 'tax_number', label: 'contacts.fields.taxNumber', type: 'text', col: 6 },
      { name: 'tax_registration_type', label: 'contacts.fields.taxRegistrationType', type: 'text', col: 6 },
      { name: 'commercial_register', label: 'contacts.fields.commercialRegister', type: 'text', col: 6 }
    );
  }

  // In dedicated mode, the user knows directly what they are adding - no confusing toggles!
  if (!isDedicated) {
    fields.push(
      { name: 'is_customer', label: 'contacts.fields.isCustomer', type: 'boolean', col: 6, default: true },
      { name: 'is_supplier', label: 'contacts.fields.isSupplier', type: 'boolean', col: 6, default: false }
    );
  }

  fields.push(
    { name: 'is_active', label: 'contacts.fields.isActive', type: 'boolean', col: 12, default: true },
    { name: 'notes', label: 'contacts.fields.notes', type: 'textarea', col: 12 }
  );

  return { fields };
});

const contacts = ref([]);
const stats = ref({ total: 0, customers: 0, suppliers: 0, active: 0, inactive: 0 });
const loading = ref(true);
const loadError = ref('');

const search = ref('');
const statusFilter = ref('all');
const roleFilter = ref('all');
const typeFilter = ref('all');

const page = ref(1);
const perPage = 10;

const totalRecords = ref(0);
const lastPage = ref(1);

const showModal = ref(false);
const modalMode = ref('create');
const modalLoading = ref(false);
const activeRecordId = ref(null);
const formData = ref({});
const formErrors = ref({});

// Dynamic Stat Cards
const stat1Label = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.customersCount');
  if (activeRole.value === 'supplier') return t('contacts.suppliersCount');
  return t('contacts.totalContacts');
});
const stat1Short = computed(() => {
  if (activeRole.value === 'customer') return t('contacts.customersShort');
  if (activeRole.value === 'supplier') return t('contacts.suppliersShort');
  return t('contacts.totalContactsShort');
});
const stat1Value = computed(() => {
  if (activeRole.value === 'customer') return stats.value.customers;
  if (activeRole.value === 'supplier') return stats.value.suppliers;
  return stats.value.total;
});

const stat2Label = computed(() => {
  if (activeRole.value === 'customer' || activeRole.value === 'supplier') return t('contacts.activeAvailable');
  return t('contacts.customersCount');
});
const stat2Short = computed(() => {
  if (activeRole.value === 'customer' || activeRole.value === 'supplier') return t('contacts.statusActive');
  return t('contacts.customersShort');
});
const stat2Value = computed(() => {
  if (activeRole.value === 'customer' || activeRole.value === 'supplier') return stats.value.active;
  return stats.value.customers;
});

const stat3Label = computed(() => {
  if (activeRole.value === 'customer' || activeRole.value === 'supplier') return t('contacts.inactiveCurrently');
  return t('contacts.suppliersCount');
});
const stat3Short = computed(() => {
  if (activeRole.value === 'customer' || activeRole.value === 'supplier') return t('contacts.statusInactive');
  return t('contacts.suppliersShort');
});
const stat3Value = computed(() => {
  if (activeRole.value === 'customer' || activeRole.value === 'supplier') return stats.value.inactive;
  return stats.value.suppliers;
});

const statsTotalCount = computed(() => {
  if (activeRole.value === 'customer') return stats.value.customers;
  if (activeRole.value === 'supplier') return stats.value.suppliers;
  return stats.value.total;
});
const statsActiveCount = computed(() => stats.value.active);
const statsInactiveCount = computed(() => stats.value.inactive);

const activeFiltersCount = computed(() => {
  let count = 0;
  if (statusFilter.value !== 'all') count++;
  if (activeRole.value === 'all' && roleFilter.value !== 'all') count++;
  if (typeFilter.value !== 'all') count++;
  return count;
});

const hasFilters = computed(
  () => search.value.trim() !== '' || activeFiltersCount.value > 0
);

const pageCount = computed(() => Math.max(1, lastPage.value));

const rangeLabel = computed(() => {
  const total = totalRecords.value;
  const from = total === 0 ? 0 : (page.value - 1) * perPage + 1;
  const to = Math.min(page.value * perPage, total);
  return t('contacts.showingRange', { from, to, total });
});

const pageOfLabel = computed(() => t('contacts.pageOf', { page: page.value, pages: pageCount.value }));

const previousIcon = computed(() => (locale.value === 'ar' ? ArrowRight : ArrowLeft));
const nextIcon = computed(() => (locale.value === 'ar' ? ArrowLeft : ArrowRight));

const modalTitle = computed(() => {
  if (modalMode.value === 'create') {
    if (activeRole.value === 'customer') return t('contacts.addCustomer');
    if (activeRole.value === 'supplier') return t('contacts.addSupplier');
    return t('contacts.addContact');
  }
  if (activeRole.value === 'customer') return t('contacts.editCustomer');
  if (activeRole.value === 'supplier') return t('contacts.editSupplier');
  return `${t('common.edit')} ${t('contacts.contactCol')}`;
});

const typeLabel = (type) => {
  if (type === 'company') return t('contacts.typeCompany');
  return t('contacts.typeIndividual');
};

const initials = (name) => {
  const parts = String(name || '')
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  return parts.slice(0, 2).map((part) => part.charAt(0)).join('');
};

const loadContacts = async () => {
  loading.value = true;
  loadError.value = '';

  try {
    const isCustomerParam =
      activeRole.value === 'customer'
        ? true
        : activeRole.value === 'supplier'
        ? undefined
        : roleFilter.value === 'customer'
        ? true
        : undefined;

    const isSupplierParam =
      activeRole.value === 'supplier'
        ? true
        : activeRole.value === 'customer'
        ? undefined
        : roleFilter.value === 'supplier'
        ? true
        : undefined;

    const response = await api.get('/contacts', {
      params: {
        page: page.value,
        per_page: perPage,
        search: search.value.trim() || undefined,
        type: typeFilter.value !== 'all' ? typeFilter.value : undefined,
        is_customer: isCustomerParam,
        is_supplier: isSupplierParam,
        is_active: statusFilter.value === 'all' ? undefined : statusFilter.value === 'active',
        sort_by: 'name',
        sort_order: 'asc',
      },
    });

    const payload = response.data;
    contacts.value = payload.data || [];
    totalRecords.value = Number(payload.meta?.total ?? contacts.value.length);
    lastPage.value = Number(payload.meta?.last_page ?? 1);

    if (payload.stats) {
      stats.value = payload.stats;
    }

    if (page.value > pageCount.value) {
      page.value = pageCount.value;
    }
  } catch {
    loadError.value = t('contacts.loadError');
  } finally {
    loading.value = false;
  }
};

const createDefaults = () => ({
  type: 'individual',
  name: '',
  company_contact_person: null,
  mobile: '',
  email: null,
  tax_number: null,
  tax_registration_type: null,
  commercial_register: null,
  is_customer: activeRole.value === 'supplier' ? false : true,
  is_supplier: activeRole.value === 'supplier' ? true : false,
  is_active: true,
  notes: null,
});

const openCreate = () => {
  modalMode.value = 'create';
  activeRecordId.value = null;
  formErrors.value = {};
  formData.value = createDefaults();
  showModal.value = true;
};

const openEdit = (contact) => {
  modalMode.value = 'edit';
  activeRecordId.value = contact.id;
  formErrors.value = {};
  formData.value = {
    type: contact.type ?? 'individual',
    name: contact.name ?? '',
    company_contact_person: contact.company_contact_person ?? null,
    mobile: contact.mobile ?? contact.phone ?? '',
    email: contact.email ?? null,
    tax_number: contact.tax_number ?? null,
    tax_registration_type: contact.tax_registration_type ?? null,
    commercial_register: contact.commercial_register ?? null,
    is_customer: !!contact.is_customer,
    is_supplier: !!contact.is_supplier,
    is_active: !!contact.is_active,
    notes: contact.notes ?? null,
  };
  showModal.value = true;
};

const closeModal = () => {
  showModal.value = false;
  formErrors.value = {};
};

const saveRecord = async () => {
  modalLoading.value = true;
  formErrors.value = {};

  try {
    // If in dedicated mode on creation, guarantee correct role flags
    if (activeRole.value === 'customer' && modalMode.value === 'create') {
      formData.value.is_customer = true;
      formData.value.is_supplier = false;
    } else if (activeRole.value === 'supplier' && modalMode.value === 'create') {
      formData.value.is_customer = false;
      formData.value.is_supplier = true;
    }

    const payload = { ...formData.value };
    if (payload.type === 'individual') {
      payload.company_contact_person = null;
      payload.tax_number = null;
      payload.tax_registration_type = null;
      payload.commercial_register = null;
    }

    const response =
      modalMode.value === 'create'
        ? await api.post('/contacts', payload)
        : await api.put(`/contacts/${activeRecordId.value}`, payload);

    notificationStore.success(response.data.message || t('common.successSave'), t('common.successTitle'));
    closeModal();
    await loadContacts();
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

const deleteContact = async (contact) => {
  const confirmed = await alertService.confirmDelete({
    title: t('contacts.deleteTitle'),
    text: t('contacts.deleteConfirm', { name: contact.name }),
    confirmButtonText: t('common.delete'),
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    const response = await api.delete(`/contacts/${contact.id}`);
    notificationStore.success(response.data.message || t('common.successDelete'), t('common.successTitle'));
    await loadContacts();
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }
};

const resetFilters = () => {
  search.value = '';
  statusFilter.value = 'all';
  roleFilter.value = 'all';
  typeFilter.value = 'all';
  page.value = 1;
};

let searchDebounceTimer = null;
watch(search, () => {
  clearTimeout(searchDebounceTimer);
  searchDebounceTimer = setTimeout(() => {
    page.value = 1;
    loadContacts();
  }, 300);
});

watch([statusFilter, roleFilter, typeFilter, page], () => {
  loadContacts();
});

// React instantly when switching between /customers and /suppliers via navigation
watch(
  () => [route.path, props.role],
  () => {
    page.value = 1;
    loadContacts();
  }
);

onMounted(() => {
  loadContacts();
});
</script>
