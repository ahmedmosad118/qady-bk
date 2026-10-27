<template>
  <div class="space-y-6">
    <!-- Page header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-sm font-semibold text-[var(--crimson)]">{{ $t('nav.userManagement') }}</p>
        <h1 class="mt-1 text-2xl font-extrabold leading-tight text-[var(--text)] sm:text-[28px]">
          {{ $t('users.title') }}
        </h1>
        <p class="mt-1.5 text-sm leading-6 text-[var(--muted)]">{{ $t('users.subtitle') }}</p>
      </div>

      <button
        type="button"
        class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] disabled:cursor-not-allowed disabled:opacity-60"
        :disabled="!schema"
        @click="openCreate"
      >
        <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
        <span>{{ $t('users.addMember') }}</span>
      </button>
    </div>

    <!-- Neutral stats -->
    <div class="grid grid-cols-3 gap-2 sm:gap-4">
      <StatCard
        :label="$t('users.totalUsers')"
        :short-label="$t('users.totalUsersShort')"
        :value="stats.total"
        :loading="loading"
      />
      <StatCard
        :label="$t('users.activeUsers')"
        :short-label="$t('users.activeUsersShort')"
        :value="stats.active"
        :loading="loading"
      />
      <StatCard
        :label="$t('users.inactiveUsers')"
        :short-label="$t('users.inactiveUsersShort')"
        :value="stats.inactive"
        :loading="loading"
      />
    </div>

    <!-- Refresh error (stale data still shown) -->
    <div
      v-if="loadError && users.length"
      class="flex flex-col gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)] sm:flex-row sm:items-center sm:justify-between"
      role="alert"
    >
      <span class="flex items-center gap-2">
        <AlertCircle class="h-[18px] w-[18px] shrink-0" aria-hidden="true" />
        {{ loadError }}
      </span>
      <button type="button" class="self-start underline underline-offset-4 sm:self-auto" @click="loadUsers">
        {{ $t('users.retry') }}
      </button>
    </div>

    <!-- Users card -->
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
              :placeholder="$t('users.searchPlaceholder')"
              :aria-label="$t('common.search')"
            />
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <FilterChip
              :label="$t('users.statusAll')"
              :count="stats.total"
              :active="statusFilter === 'all'"
              @click="statusFilter = 'all'"
            />
            <FilterChip
              :label="$t('users.statusActive')"
              :count="stats.active"
              :active="statusFilter === 'active'"
              @click="statusFilter = 'active'"
            />
            <FilterChip
              :label="$t('users.statusInactive')"
              :count="stats.inactive"
              :active="statusFilter === 'inactive'"
              @click="statusFilter = 'inactive'"
            />

            <div class="relative">
              <select
                v-model="roleFilter"
                class="h-10 min-w-[150px] cursor-pointer appearance-none rounded-xl border border-[var(--border)] bg-[var(--card)] pl-9 pr-3.5 text-sm font-semibold text-[var(--muted)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
                :aria-label="$t('users.roleFilter')"
              >
                <option value="all">{{ $t('users.allRoles') }}</option>
                <option v-for="role in roleOptions" :key="role" :value="role">{{ role }}</option>
                <option v-if="hasUnassignedRoles" value="__none">{{ $t('users.noRole') }}</option>
              </select>
              <ChevronDown
                class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- Bulk actions -->
      <div
        v-if="selectedIds.length"
        class="flex flex-col gap-3 border-b border-[var(--border)] bg-[var(--crimson-soft)] px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5"
      >
        <p class="text-sm font-bold text-[var(--text)]">
          {{ $t('users.selectedCount', { count: selectedIds.length }) }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-[var(--crimson)] px-3.5 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
            @click="deleteSelected"
          >
            <Trash2 class="h-4 w-4" aria-hidden="true" />
            {{ $t('users.bulkDelete') }}
          </button>
          <button
            type="button"
            class="inline-flex h-9 items-center rounded-lg border border-[var(--border)] bg-[var(--card)] px-3.5 text-sm font-semibold text-[var(--muted)] transition-colors hover:text-[var(--text)]"
            @click="selectedIds = []"
          >
            {{ $t('users.clearSelection') }}
          </button>
        </div>
      </div>

      <!-- Table / empty state -->
      <UsersTable
        v-if="loading || pagedUsers.length"
        :users="pagedUsers"
        :loading="loading"
        :selected-ids="selectedIds"
        :all-selected="allPageSelected"
        :some-selected="somePageSelected"
        @toggle-all="toggleAllOnPage"
        @toggle-row="toggleRow"
        @edit="openEdit"
        @delete="deleteUser"
      />

      <div v-else-if="loadError" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <AlertCircle class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">
          {{ $t('users.loadErrorTitle') }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ loadError }}
        </p>
        <button
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="loadUsers"
        >
          <RotateCw class="h-4 w-4" aria-hidden="true" />
          {{ $t('users.retry') }}
        </button>
      </div>

      <div v-else class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg)] text-[var(--muted)]">
          <Users v-if="!users.length" class="h-6 w-6" aria-hidden="true" />
          <SearchX v-else class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">
          {{ users.length ? $t('users.noResultsTitle') : $t('users.emptyTitle') }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ users.length ? $t('users.noResultsDesc') : $t('users.emptyDesc') }}
        </p>
        <button
          v-if="users.length"
          type="button"
          class="mt-5 inline-flex h-10 items-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40"
          @click="resetFilters"
        >
          {{ $t('users.resetFilters') }}
        </button>
        <button
          v-else
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          {{ $t('users.addMember') }}
        </button>
      </div>

      <!-- Pagination -->
      <div
        v-if="!loading && filteredUsers.length"
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
            {{ $t('users.previous') }}
          </button>

          <span class="px-1 text-sm font-medium text-[var(--muted)]">{{ pageOfLabel }}</span>

          <button
            type="button"
            class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 disabled:cursor-not-allowed disabled:opacity-40"
            :disabled="page >= pageCount"
            @click="page += 1"
          >
            {{ $t('users.next') }}
            <component :is="nextIcon" class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>

    <!-- Create / edit modal (same CRUD engine + endpoints) -->
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
        v-if="schema"
        :schema="schema"
        :form-data="formData"
        :errors="formErrors"
        :related-options="{}"
      />
      <div v-else class="flex flex-col items-center justify-center gap-3 py-12 text-[var(--muted)]">
        <span class="h-8 w-8 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
        <p class="text-sm font-semibold">{{ $t('common.loading') }}</p>
      </div>
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
  Plus,
  RotateCw,
  Search,
  SearchX,
  Trash2,
  Users,
} from 'lucide-vue-next';
import api from '../../services/api';
import alertService from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import { useNotificationStore } from '../../stores/notification';
import StatCard from '../../components/ui/StatCard.vue';
import FilterChip from '../../components/ui/FilterChip.vue';
import UsersTable from '../../components/users/UsersTable.vue';
import CrudModal from '../../components/crud/CrudModal.vue';
import CrudForm from '../../components/crud/CrudForm.vue';

const { t, locale } = useI18n();
const authStore = useAuthStore();
const notificationStore = useNotificationStore();

const users = ref([]);
const schema = ref(null);
const stats = ref({ total: 0, active: 0, inactive: 0 });
const loading = ref(true);
const loadError = ref('');

const search = ref('');
const statusFilter = ref('all');
const roleFilter = ref('all');

const page = ref(1);
const perPage = 10;
const selectedIds = ref([]);

const showModal = ref(false);
const modalMode = ref('create');
const modalLoading = ref(false);
const activeRecordId = ref(null);
const formData = ref({});
const formErrors = ref({});

const roleLabel = (user) => {
  const roles = user.roles ?? user.roles_list;
  const first = Array.isArray(roles) && roles.length ? roles[0] : user.role;

  if (!first) return '';
  if (typeof first === 'string') return first;
  return first.display_name || first.name || '';
};

const roleOptions = computed(() => {
  const set = new Set();

  users.value.forEach((user) => {
    const role = roleLabel(user);
    if (role) set.add(role);
  });

  return Array.from(set).sort((a, b) => a.localeCompare(b, 'ar'));
});

const hasUnassignedRoles = computed(() => users.value.some((user) => !roleLabel(user)));

const totalRecords = ref(0);
const lastPage = ref(1);

const pageCount = computed(() => Math.max(1, lastPage.value));

const pagedUsers = computed(() => users.value);

const allPageSelected = computed(
  () => pagedUsers.value.length > 0 && pagedUsers.value.every((user) => selectedIds.value.includes(user.id))
);
const somePageSelected = computed(() => pagedUsers.value.some((user) => selectedIds.value.includes(user.id)));

const rangeLabel = computed(() => {
  const total = totalRecords.value;
  const from = total === 0 ? 0 : (page.value - 1) * perPage + 1;
  const to = Math.min(page.value * perPage, total);
  return t('users.showingRange', { from, to, total });
});

const pageOfLabel = computed(() => t('users.pageOf', { page: page.value, pages: pageCount.value }));

const previousIcon = computed(() => (locale.value === 'ar' ? ArrowRight : ArrowLeft));
const nextIcon = computed(() => (locale.value === 'ar' ? ArrowLeft : ArrowRight));

const modalTitle = computed(
  () => `${modalMode.value === 'create' ? t('common.create') : t('common.edit')} ${t('header.member')}`
);

const loadUsers = async () => {
  loading.value = true;
  loadError.value = '';

  try {
    const response = await api.get('/users', {
      params: {
        page: page.value,
        per_page: perPage,
        search: search.value.trim() || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
      },
    });

    const payload = response.data;
    users.value = payload.data || [];
    totalRecords.value = Number(payload.meta?.total ?? users.value.length);
    lastPage.value = Number(payload.meta?.last_page ?? 1);

    if (payload.stats) {
      stats.value = payload.stats;
    }

    if (payload.schema) {
      schema.value = payload.schema;
    }

    if (roleFilter.value !== 'all' && roleFilter.value !== '__none' && !roleOptions.value.includes(roleFilter.value)) {
      roleFilter.value = 'all';
    }

    if (page.value > pageCount.value) {
      page.value = pageCount.value;
    }
  } catch {
    loadError.value = t('users.loadError');
  } finally {
    loading.value = false;
  }
};

const toggleAllOnPage = () => {
  const pageIds = pagedUsers.value.map((user) => user.id);

  if (allPageSelected.value) {
    selectedIds.value = selectedIds.value.filter((id) => !pageIds.includes(id));
    return;
  }

  selectedIds.value = Array.from(new Set([...selectedIds.value, ...pageIds]));
};

const toggleRow = (user) => {
  if (selectedIds.value.includes(user.id)) {
    selectedIds.value = selectedIds.value.filter((id) => id !== user.id);
    return;
  }

  selectedIds.value = [...selectedIds.value, user.id];
};

const resetFilters = () => {
  search.value = '';
  statusFilter.value = 'all';
  roleFilter.value = 'all';
  page.value = 1;
};

const openCreate = () => {
  if (!schema.value) return;

  modalMode.value = 'create';
  activeRecordId.value = null;
  formErrors.value = {};

  const initial = {};
  schema.value.fields?.forEach((field) => {
    initial[field.name] = field.default !== undefined ? field.default : null;
  });

  formData.value = initial;
  showModal.value = true;
};

const openEdit = (user) => {
  modalMode.value = 'edit';
  activeRecordId.value = user.id;
  formErrors.value = {};
  formData.value = { ...user };
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
        ? await api.post('/users', formData.value)
        : await api.put(`/users/${activeRecordId.value}`, formData.value);

    notificationStore.success(response.data.message || t('common.successSave'), t('common.successTitle'));
    closeModal();
    await loadUsers();
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

const deleteUser = async (user) => {
  const confirmed = await alertService.confirmDelete({
    title: t('users.deleteTitle'),
    text: t('users.deleteConfirm', { name: user.name }),
    confirmButtonText: t('common.delete'),
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    const response = await api.delete(`/users/${user.id}`);
    selectedIds.value = selectedIds.value.filter((id) => id !== user.id);
    notificationStore.success(response.data.message || t('common.successDelete'), t('common.successTitle'));
    await loadUsers();
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }
};

const deleteSelected = async () => {
  const currentUserId = authStore.user?.id;
  const ids = selectedIds.value.filter((id) => id !== currentUserId);
  const skippedSelf = ids.length !== selectedIds.value.length;

  if (!ids.length) {
    notificationStore.info(t('users.selfDeleteSkipped'), t('common.errorTitle'));
    return;
  }

  const confirmed = await alertService.confirmDelete({
    title: t('users.bulkDeleteTitle'),
    text: t('users.bulkDeleteConfirm', { count: ids.length }),
    confirmButtonText: `${t('common.delete')} (${ids.length})`,
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    await api.delete(`/users/${ids.join(',')}`);
    notificationStore.success(t('users.bulkDeleted', { count: ids.length }), t('common.successTitle'));
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }

  if (skippedSelf) {
    notificationStore.info(t('users.selfDeleteSkipped'));
  }

  selectedIds.value = [];
  await loadUsers();
};

let searchDebounce = null;
watch([search, statusFilter, roleFilter], () => {
  page.value = 1;
  selectedIds.value = [];
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    loadUsers();
  }, 300);
});

watch(page, () => {
  loadUsers();
});

watch(pageCount, (count) => {
  if (page.value > count) page.value = count;
});

onMounted(loadUsers);
</script>
