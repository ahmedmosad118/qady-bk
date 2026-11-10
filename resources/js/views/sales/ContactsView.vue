<template>
  <div class="space-y-6">
    <!-- Page header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-[var(--crimson)]">{{ $t('nav.salesGroup') }}</p>
        <h1 class="mt-1 text-2xl font-extrabold leading-tight text-[var(--text)] sm:text-[28px]">
          {{ $t('contacts.title') }}
        </h1>
        <p class="mt-1.5 text-sm leading-6 text-[var(--muted)]">{{ $t('contacts.subtitle') }}</p>
      </div>

      <button
        type="button"
        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
        @click="openCreate"
      >
        <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
        <span>{{ $t('contacts.addContact') }}</span>
      </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-3 gap-2 sm:gap-4">
      <StatCard
        :label="$t('contacts.totalContacts')"
        :short-label="$t('contacts.totalContactsShort')"
        :value="stats.total"
        :loading="loading"
      />
      <StatCard
        :label="$t('contacts.customersCount')"
        :short-label="$t('contacts.customersShort')"
        :value="stats.customers"
        :loading="loading"
      />
      <StatCard
        :label="$t('contacts.suppliersCount')"
        :short-label="$t('contacts.suppliersShort')"
        :value="stats.suppliers"
        :loading="loading"
      />
    </div>

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
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadContacts">
        {{ $t('contacts.retry') }}
      </button>
    </div>

    <!-- Contacts card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Toolbar -->
      <div class="border-b border-[var(--border)] p-4 sm:p-5">
        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
          <div class="relative w-full xl:max-w-md">
            <Search
              class="pointer-events-none absolute right-3.5 top-1/2 h-[18px] w-[18px] -translate-y-1/2 text-[var(--muted)]"
              aria-hidden="true"
            />
            <input
              v-model="search"
              type="search"
              class="h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] pl-4 pr-11 text-sm text-[var(--text)] outline-none transition placeholder:text-[var(--muted)]/70 focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
              :placeholder="$t('contacts.searchPlaceholder')"
              :aria-label="$t('common.search')"
            />
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <FilterChip
              :label="$t('contacts.statusAll')"
              :count="stats.total"
              :active="statusFilter === 'all'"
              @click="statusFilter = 'all'"
            />
            <FilterChip
              :label="$t('contacts.statusActive')"
              :count="stats.active"
              :active="statusFilter === 'active'"
              @click="statusFilter = 'active'"
            />
            <FilterChip
              :label="$t('contacts.statusInactive')"
              :count="stats.inactive"
              :active="statusFilter === 'inactive'"
              @click="statusFilter = 'inactive'"
            />

            <div class="relative">
              <select
                v-model="roleFilter"
                class="h-10 min-w-[140px] cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--card)] pl-9 pr-3.5 text-sm font-semibold text-[var(--muted)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('contacts.roleFilter')"
              >
                <option value="all">{{ $t('contacts.roleAll') }}</option>
                <option value="customer">{{ $t('contacts.roleCustomer') }}</option>
                <option value="supplier">{{ $t('contacts.roleSupplier') }}</option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>

            <div class="relative">
              <select
                v-model="typeFilter"
                class="h-10 min-w-[140px] cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--card)] pl-9 pr-3.5 text-sm font-semibold text-[var(--muted)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('contacts.typeFilter')"
              >
                <option value="all">{{ $t('contacts.allTypes') }}</option>
                <option value="individual">{{ $t('contacts.typeIndividual') }}</option>
                <option value="company">{{ $t('contacts.typeCompany') }}</option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div v-if="loading || contacts.length" class="overflow-x-auto">
        <table class="w-full min-w-[820px] table-auto text-right">
          <thead class="bg-[var(--bg)]">
            <tr>
              <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('contacts.contactCol') }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] sm:table-cell">
                {{ $t('contacts.typeCol') }}
              </th>
              <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('contacts.rolesCol') }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] lg:table-cell">
                {{ $t('contacts.phoneCol') }}
              </th>
              <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] xl:table-cell">
                {{ $t('contacts.emailCol') }}
              </th>
              <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('contacts.statusCol') }}
              </th>
              <th scope="col" class="w-24 px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
                {{ $t('common.actions') }}
              </th>
            </tr>
          </thead>

          <tbody v-if="loading" aria-busy="true">
            <tr v-for="n in 5" :key="n" class="border-t border-[var(--border)]">
              <td colspan="7" class="px-4 py-4">
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

          <tbody v-else>
            <tr
              v-for="contact in contacts"
              :key="contact.id"
              class="border-t border-[var(--border)] transition-colors hover:bg-[var(--bg)]"
            >
              <td class="px-4 py-3.5">
                <div class="flex items-center gap-3">
                  <span
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-sm font-extrabold text-[var(--crimson)]"
                    aria-hidden="true"
                  >
                    {{ initials(contact.name) }}
                  </span>
                  <div class="min-w-0">
                    <p class="truncate text-sm font-bold text-[var(--text)]">{{ contact.name }}</p>
                    <p class="mt-0.5 truncate text-sm text-[var(--muted)]">
                      {{ contact.company_contact_person || contact.email || '—' }}
                    </p>
                  </div>
                </div>
              </td>

              <td class="hidden px-4 py-3.5 sm:table-cell">
                <span class="text-sm text-[var(--muted)]">{{ typeLabel(contact.type) }}</span>
              </td>

              <td class="px-4 py-3.5">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span
                    v-if="contact.is_customer"
                    class="inline-flex items-center rounded-full border border-[var(--success-border)] bg-[var(--success-soft)] px-2.5 py-1 text-sm font-semibold text-[var(--success)]"
                  >
                    {{ $t('contacts.customerBadge') }}
                  </span>
                  <span
                    v-if="contact.is_supplier"
                    class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 text-sm font-semibold text-[var(--muted)]"
                  >
                    {{ $t('contacts.supplierBadge') }}
                  </span>
                </div>
              </td>

              <td class="hidden px-4 py-3.5 lg:table-cell">
                <span v-if="contact.mobile || contact.phone" dir="ltr" class="inline-block text-sm tabular-nums text-[var(--muted)]">
                  {{ contact.mobile || contact.phone }}
                </span>
                <span v-else class="text-sm text-[var(--muted)]">—</span>
              </td>

              <td class="hidden px-4 py-3.5 xl:table-cell">
                <span v-if="contact.email" dir="ltr" class="inline-block text-sm text-[var(--muted)]">{{ contact.email }}</span>
                <span v-else class="text-sm text-[var(--muted)]">—</span>
              </td>

              <td class="px-4 py-3.5">
                <StatusBadge :active="!!contact.is_active" />
              </td>

              <td class="px-4 py-3.5">
                <div class="flex items-center gap-1.5">
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                    :aria-label="$t('contacts.editContact', { name: contact.name })"
                    :title="$t('common.edit')"
                    @click="openEdit(contact)"
                  >
                    <Pencil class="h-4 w-4" aria-hidden="true" />
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]"
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
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
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
          {{ hasFilters ? $t('contacts.noResultsTitle') : $t('contacts.emptyTitle') }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ hasFilters ? $t('contacts.noResultsDesc') : $t('contacts.emptyDesc') }}
        </p>
        <button
          v-if="hasFilters"
          type="button"
          class="mt-5 inline-flex h-10 items-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40"
          @click="resetFilters"
        >
          {{ $t('contacts.resetFilters') }}
        </button>
        <button
          v-else
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          {{ $t('contacts.addContact') }}
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
            {{ $t('contacts.previous') }}
          </button>

          <span class="px-1 text-sm font-medium text-[var(--muted)]">{{ pageOfLabel }}</span>

          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:cursor-not-allowed disabled:opacity-40"
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
      <CrudForm :schema="schema" :form-data="formData" :errors="formErrors" />
    </CrudModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  ChevronDown,
  Pencil,
  Plus,
  RotateCw,
  Search,
  SearchX,
  Trash2,
  UsersRound,
} from 'lucide-vue-next';
import api from '../../services/api';
import alertService from '../../services/alert';
import { useNotificationStore } from '../../stores/notification';
import StatCard from '../../components/ui/StatCard.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import StatusBadge from '../../components/ui/StatusBadge.vue';
import CrudModal from '../../components/crud/CrudModal.vue';
import CrudForm from '../../components/crud/CrudForm.vue';

const { t, locale } = useI18n();
const notificationStore = useNotificationStore();

const schema = {
  fields: [
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
    { name: 'name', label: 'contacts.fields.name', type: 'text', required: true, col: 6 },
    { name: 'company_contact_person', label: 'contacts.fields.companyContactPerson', type: 'text', col: 6 },
    { name: 'email', label: 'contacts.fields.email', type: 'email', col: 6 },
    { name: 'phone', label: 'contacts.fields.phone', type: 'tel', col: 6 },
    { name: 'mobile', label: 'contacts.fields.mobile', type: 'tel', col: 6 },
    { name: 'tax_number', label: 'contacts.fields.taxNumber', type: 'text', col: 6 },
    { name: 'tax_registration_type', label: 'contacts.fields.taxRegistrationType', type: 'text', col: 6 },
    { name: 'commercial_register', label: 'contacts.fields.commercialRegister', type: 'text', col: 6 },
    { name: 'is_customer', label: 'contacts.fields.isCustomer', type: 'boolean', col: 6, default: true },
    { name: 'is_supplier', label: 'contacts.fields.isSupplier', type: 'boolean', col: 6, default: false },
    { name: 'is_active', label: 'contacts.fields.isActive', type: 'boolean', col: 12, default: true },
    { name: 'notes', label: 'contacts.fields.notes', type: 'textarea', col: 12 },
  ],
};

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

const hasFilters = computed(
  () =>
    search.value.trim() !== '' ||
    statusFilter.value !== 'all' ||
    roleFilter.value !== 'all' ||
    typeFilter.value !== 'all'
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

const modalTitle = computed(
  () => (modalMode.value === 'create' ? t('contacts.addContact') : `${t('common.edit')} ${t('contacts.contactCol')}`)
);

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
    const response = await api.get('/contacts', {
      params: {
        page: page.value,
        per_page: perPage,
        search: search.value.trim() || undefined,
        type: typeFilter.value !== 'all' ? typeFilter.value : undefined,
        is_customer: roleFilter.value === 'customer' ? true : undefined,
        is_supplier: roleFilter.value === 'supplier' ? true : undefined,
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
  phone: null,
  mobile: null,
  email: null,
  tax_number: null,
  tax_registration_type: null,
  commercial_register: null,
  is_customer: true,
  is_supplier: false,
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
    phone: contact.phone ?? null,
    mobile: contact.mobile ?? null,
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
    const response =
      modalMode.value === 'create'
        ? await api.post('/contacts', formData.value)
        : await api.put(`/contacts/${activeRecordId.value}`, formData.value);

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

let searchDebounce = null;
watch([search, statusFilter, roleFilter, typeFilter], () => {
  page.value = 1;
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    loadContacts();
  }, 300);
});

watch(page, () => {
  loadContacts();
});

watch(pageCount, (count) => {
  if (page.value > count) page.value = count;
});

onMounted(loadContacts);
</script>
