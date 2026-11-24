<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('roles.title')" :subtitle="$t('roles.subtitle')">
      <template #actions>
        <button
          v-if="canCreate"
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('roles.createRoleTitle') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <!-- Search Toolbar -->
      <FilterToolbar
        v-model:search="search"
        :search-placeholder="$t('roles.searchPlaceholder')"
        :show-filter-button="false"
      />

      <!-- Loading State -->
      <div v-if="loading" class="divide-y divide-[var(--border)]" aria-busy="true">
        <div v-for="n in 4" :key="n" class="flex items-center justify-between gap-4 px-5 py-4">
          <div class="flex-1 space-y-2">
            <div class="h-4 w-44 animate-pulse rounded bg-[var(--bg)]"></div>
            <div class="h-3 w-72 max-w-full animate-pulse rounded bg-[var(--bg)]"></div>
          </div>
          <div class="h-8 w-24 animate-pulse rounded-lg bg-[var(--bg)]"></div>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="loadError" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)]">
          <AlertCircle class="h-6 w-6" aria-hidden="true" />
        </span>
        <p class="mt-4 text-sm font-semibold text-[var(--muted)]">{{ loadError }}</p>
        <button
          type="button"
          class="mt-5 inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-sm font-bold text-white transition-colors hover:bg-[var(--crimson-hover)]"
          @click="loadRoles"
        >
          <RotateCw class="h-4 w-4" aria-hidden="true" />
          {{ $t('roles.retry') }}
        </button>
      </div>

      <!-- Empty State -->
      <div v-else-if="roles.length === 0" class="flex flex-col items-center justify-center px-6 py-16 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg)] text-[var(--muted)]">
          <ShieldCheck class="h-6 w-6" aria-hidden="true" />
        </span>
        <h2 class="mt-4 text-base font-bold text-[var(--text)]">
          {{ search.trim() ? $t('roles.noResultsTitle') : $t('roles.noRolesTitle') }}
        </h2>
        <p class="mt-1.5 max-w-sm text-sm leading-7 text-[var(--muted)]">
          {{ search.trim() ? $t('roles.noResultsDesc') : $t('roles.noRolesDesc') }}
        </p>
      </div>

      <!-- Roles Table & Mobile List -->
      <template v-else>
        <!-- Mobile List -->
        <div class="divide-y divide-[var(--border)] md:hidden">
          <div v-for="role in roles" :key="role.id" class="space-y-3 px-4 py-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <p class="text-sm font-extrabold text-[var(--text)]">{{ role.name }}</p>
                  <span
                    v-if="role.is_super_admin"
                    class="inline-flex items-center rounded-full border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-2.5 py-0.5 text-xs font-bold text-[var(--crimson)]"
                  >
                    {{ $t('roles.superAdminBadge') }}
                  </span>
                  <span
                    v-else-if="role.is_system"
                    class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-0.5 text-xs font-semibold text-[var(--muted)]"
                  >
                    {{ $t('roles.systemRole') }}
                  </span>
                </div>
                <p v-if="role.description" class="mt-1 text-xs text-[var(--muted)]">
                  {{ role.description }}
                </p>
              </div>

              <div class="flex shrink-0 items-center gap-1">
                <button
                  type="button"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                  :title="canEditRole(role) ? $t('roles.editPermissions') : $t('common.view')"
                  @click="openRole(role)"
                >
                  <Pencil v-if="canEditRole(role)" class="h-4 w-4" aria-hidden="true" />
                  <Eye v-else class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                  v-if="canDelete && !role.is_system && !role.is_super_admin"
                  type="button"
                  :disabled="role.users_count > 0"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] disabled:cursor-not-allowed disabled:opacity-40"
                  :title="role.users_count > 0 ? $t('roles.cannotDeleteAssigned') : $t('roles.deleteCustomRole')"
                  @click="deleteRole(role)"
                >
                  <Trash2 class="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 text-xs font-semibold">
              <span class="rounded-lg bg-[var(--bg)] px-2.5 py-1 text-[var(--text)]">
                {{ role.users_count ?? 0 }} {{ $t('roles.employees') }}
              </span>
              <span class="rounded-lg bg-[var(--bg)] px-2.5 py-1 text-[var(--text)]">
                {{ role.is_super_admin ? $t('roles.allPermissionsGranted') : `${role.permissions_count ?? 0} ${$t('roles.permissions')}` }}
              </span>
            </div>
          </div>
        </div>

        <!-- Desktop Table -->
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[640px] table-auto text-start">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
              <tr>
                <th scope="col" class="px-5 py-3.5 text-start">{{ $t('roles.roleCol') }}</th>
                <th scope="col" class="px-4 py-3.5 text-center">{{ $t('roles.typeCol') }}</th>
                <th scope="col" class="px-4 py-3.5 text-center">{{ $t('roles.usersCountCol') }}</th>
                <th scope="col" class="px-4 py-3.5 text-center">{{ $t('roles.permissionsCountCol') }}</th>
                <th scope="col" class="w-28 px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr
                v-for="role in roles"
                :key="role.id"
                class="transition-colors hover:bg-[var(--bg)]"
              >
                <td class="px-5 py-4">
                  <div class="flex items-center gap-3">
                    <span
                      class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl"
                      :class="role.is_super_admin
                        ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
                        : 'bg-[var(--bg)] text-[var(--muted)]'"
                    >
                      <ShieldCheck class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                      <p class="truncate text-sm font-extrabold text-[var(--text)]">{{ role.name }}</p>
                      <p v-if="role.description" class="mt-0.5 truncate text-xs text-[var(--muted)]">
                        {{ role.description }}
                      </p>
                    </div>
                  </div>
                </td>

                <td class="px-4 py-4 text-center">
                  <span
                    v-if="role.is_super_admin"
                    class="inline-flex items-center rounded-full border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-2.5 py-1 text-xs font-bold text-[var(--crimson)]"
                  >
                    {{ $t('roles.superAdminBadge') }}
                  </span>
                  <span
                    v-else-if="role.is_system"
                    class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 text-xs font-semibold text-[var(--muted)]"
                  >
                    {{ $t('roles.systemRole') }}
                  </span>
                  <span
                    v-else
                    class="inline-flex items-center rounded-full border border-emerald-500/25 bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400"
                  >
                    {{ $t('roles.customRole') }}
                  </span>
                </td>

                <td class="px-4 py-4 text-center text-sm font-bold tabular-nums text-[var(--text)]">
                  {{ role.users_count ?? 0 }}
                </td>

                <td class="px-4 py-4 text-center">
                  <span
                    v-if="role.is_super_admin"
                    class="inline-flex items-center rounded-full bg-[var(--crimson-soft)] px-2.5 py-1 text-xs font-bold text-[var(--crimson)]"
                  >
                    {{ $t('roles.allPermissionsGranted') }}
                  </span>
                  <span v-else class="text-sm font-bold tabular-nums text-[var(--text)]">
                    {{ role.permissions_count ?? 0 }}
                  </span>
                </td>

                <td class="px-5 py-4 text-end">
                  <div class="inline-flex items-center justify-end gap-1">
                    <button
                      type="button"
                      class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--card)] hover:text-[var(--text)]"
                      :title="canEditRole(role) ? $t('roles.editPermissions') : $t('common.view')"
                      @click="openRole(role)"
                    >
                      <Pencil v-if="canEditRole(role)" class="h-4 w-4" aria-hidden="true" />
                      <Eye v-else class="h-4 w-4" aria-hidden="true" />
                    </button>
                    <button
                      v-if="canDelete && !role.is_system && !role.is_super_admin"
                      type="button"
                      :disabled="role.users_count > 0"
                      class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] disabled:cursor-not-allowed disabled:opacity-40"
                      :title="role.users_count > 0 ? $t('roles.cannotDeleteAssigned') : $t('roles.deleteCustomRole')"
                      @click="deleteRole(role)"
                    >
                      <Trash2 class="h-4 w-4" aria-hidden="true" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TablePagination
          :meta="{ current_page: page, last_page: lastPage, per_page: perPage, total: totalRecords }"
          :items-count="roles.length"
          :per-page="perPage"
          :loading="loading"
          @update:per-page="perPage = $event"
          @change-per-page="page = 1; loadRoles()"
          @change-page="page = $event"
        />
      </template>
    </div>

    <!-- Create / Edit / View Role Modal -->
    <RoleFormModal
      :show="showModal"
      :mode="modalMode"
      :role="activeRole"
      :categories="matrixCategories"
      :total-permissions="matrixTotal"
      :matrix-loading="matrixLoading"
      :saving="saving"
      :errors="formErrors"
      @close="closeModal"
      @save="saveRole"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Eye,
  Pencil,
  Plus,
  RotateCw,
  ShieldCheck,
  Trash2,
} from 'lucide-vue-next';
import api from '../../services/api';
import alertService from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import { useNotificationStore } from '../../stores/notification';
import PageHeader from '../../components/ui/PageHeader.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import RoleFormModal from '../../components/roles/RoleFormModal.vue';

const { t, locale } = useI18n();
const authStore = useAuthStore();
const notificationStore = useNotificationStore();

const roles = ref([]);
const loading = ref(true);
const loadError = ref('');
const search = ref('');
const page = ref(1);
const perPage = ref(10);
const totalRecords = ref(0);
const lastPage = ref(1);

const matrixCategories = ref([]);
const matrixTotal = ref(0);
const matrixLoading = ref(false);
const matrixLocale = ref(null);

const showModal = ref(false);
const modalMode = ref('create');
const activeRole = ref(null);
const saving = ref(false);
const formErrors = ref({});

const canCreate = computed(() => authStore.hasPermission('roles.create'));
const canUpdate = computed(() => authStore.hasPermission('roles.update'));
const canDelete = computed(() => authStore.hasPermission('roles.delete'));

const canEditRole = (role) => canUpdate.value && !role.is_super_admin;

const previousIcon = computed(() => (locale.value === 'ar' ? ArrowRight : ArrowLeft));
const nextIcon = computed(() => (locale.value === 'ar' ? ArrowLeft : ArrowRight));

const rangeLabel = computed(() => {
  const total = totalRecords.value;
  const from = total === 0 ? 0 : (page.value - 1) * perPage + 1;
  const to = Math.min(page.value * perPage, total);
  return t('users.showingRange', { from, to, total });
});

const loadRoles = async () => {
  loading.value = true;
  loadError.value = '';

  try {
    const response = await api.get('/roles', {
      params: {
        page: page.value,
        per_page: perPage.value,
        search: search.value.trim() || undefined,
      },
    });
    roles.value = response.data.data || [];
    totalRecords.value = Number(response.data.meta?.total ?? roles.value.length);
    lastPage.value = Math.max(1, Number(response.data.meta?.last_page ?? 1));
  } catch {
    loadError.value = t('roles.loadError');
  } finally {
    loading.value = false;
  }
};

const ensureMatrixLoaded = async () => {
  if (matrixCategories.value.length > 0 && matrixLocale.value === locale.value) {
    return;
  }

  matrixLoading.value = true;
  try {
    const response = await api.get('/permissions');
    matrixCategories.value = response.data.data || [];
    matrixTotal.value = Number(response.data.meta?.total ?? 0);
    matrixLocale.value = locale.value;
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  } finally {
    matrixLoading.value = false;
  }
};

const openCreate = async () => {
  modalMode.value = 'create';
  activeRole.value = null;
  formErrors.value = {};
  showModal.value = true;
  await ensureMatrixLoaded();
};

const openRole = async (role) => {
  modalMode.value = canEditRole(role) ? 'edit' : 'view';
  activeRole.value = role;
  formErrors.value = {};
  showModal.value = true;

  await ensureMatrixLoaded();

  try {
    const response = await api.get(`/roles/${role.id}`);
    activeRole.value = response.data.data;
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
  }
};

const closeModal = () => {
  showModal.value = false;
  formErrors.value = {};
};

const saveRole = async (payload) => {
  saving.value = true;
  formErrors.value = {};

  try {
    const response =
      modalMode.value === 'create'
        ? await api.post('/roles', payload)
        : await api.put(`/roles/${activeRole.value.id}`, payload);

    notificationStore.success(
      response.data.message || (modalMode.value === 'create' ? t('roles.createdSuccess') : t('roles.savedSuccess')),
      t('common.successTitle')
    );
    closeModal();
    await loadRoles();
    await authStore.fetchMe();
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      formErrors.value = err.response.data.errors;
      const firstMsg = Object.values(err.response.data.errors).flat()[0];
      notificationStore.error(firstMsg || t('common.validationError'), t('common.errorTitle'));
    } else {
      notificationStore.error(err.response?.data?.message || t('common.errorTitle'), t('common.errorTitle'));
    }
  } finally {
    saving.value = false;
  }
};

const deleteRole = async (role) => {
  const confirmed = await alertService.confirmDelete({
    title: t('roles.deleteCustomRole'),
    text: t('roles.confirmDelete', { name: role.name }),
    confirmButtonText: t('common.delete'),
    cancelButtonText: t('common.cancel'),
  });

  if (!confirmed) return;

  try {
    const response = await api.delete(`/roles/${role.id}`);
    notificationStore.success(response.data.message || t('roles.deletedSuccess'), t('common.successTitle'));
    await loadRoles();
  } catch (err) {
    const firstMsg = err.response?.data?.errors
      ? Object.values(err.response.data.errors).flat()[0]
      : err.response?.data?.message;
    notificationStore.error(firstMsg || t('common.errorTitle'), t('common.errorTitle'));
  }
};

let searchTimer = null;
watch(search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    if (page.value !== 1) {
      page.value = 1;
    } else {
      loadRoles();
    }
  }, 300);
});

watch(page, loadRoles);

onMounted(loadRoles);
</script>
