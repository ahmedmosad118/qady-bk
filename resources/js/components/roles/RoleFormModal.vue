<template>
  <CrudModal
    :show="show"
    :title="modalTitle"
    :mode="mode === 'create' ? 'create' : 'edit'"
    :loading="saving"
    :show-save-button="!readonly"
    size="5xl"
    @close="$emit('close')"
    @save="handleSave"
  >
    <div class="space-y-6">
      <!-- Super Admin read-only info banner -->
      <div
        v-if="isSuperAdmin"
        class="flex items-start gap-3 rounded-xl border border-[var(--crimson)]/25 bg-[var(--crimson-soft)] px-4 py-3 text-sm font-semibold text-[var(--crimson)]"
      >
        <ShieldAlert class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
        <span>{{ $t('roles.cannotEditSuperAdmin') }}</span>
      </div>

      <!-- Role basic info -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('roles.roleNameLabel') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="localName"
            type="text"
            :disabled="readonly || isSystem"
            :placeholder="$t('roles.roleNamePlaceholder')"
            class="h-11 w-full rounded-xl border bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:cursor-not-allowed disabled:opacity-60"
            :class="errors.name ? 'border-[var(--crimson)]' : 'border-[var(--border)]'"
          />
          <p v-if="errors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ firstError(errors.name) }}
          </p>
        </div>

        <div>
          <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
            {{ $t('roles.roleDesc') }}
          </label>
          <input
            v-model="localDescription"
            type="text"
            :disabled="readonly"
            :placeholder="$t('roles.roleDescPlaceholder')"
            class="h-11 w-full rounded-xl border bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)] disabled:cursor-not-allowed disabled:opacity-60"
            :class="errors.description ? 'border-[var(--crimson)]' : 'border-[var(--border)]'"
          />
          <p v-if="errors.description" class="mt-1 text-xs font-semibold text-[var(--crimson)]">
            {{ firstError(errors.description) }}
          </p>
        </div>
      </div>

      <!-- Super Admin Toggle (Only visible to Super Admin when creating a new role) -->
      <label
        v-if="mode === 'create' && canGrantSuperAdmin"
        class="flex cursor-pointer items-start gap-3 rounded-xl border p-3.5 transition-colors"
        :class="localIsSuperAdmin
          ? 'border-[var(--crimson)]/40 bg-[var(--crimson-soft)]'
          : 'border-[var(--border)] bg-[var(--bg)] hover:border-[var(--crimson)]/30'"
      >
        <input
          v-model="localIsSuperAdmin"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-[var(--border)] accent-[var(--crimson)]"
        />
        <div class="min-w-0 flex-1">
          <span class="block text-sm font-extrabold text-[var(--text)]">
            {{ $t('roles.grantSuperAdminLabel') }}
          </span>
          <span class="mt-0.5 block text-xs text-[var(--muted)]">
            {{ $t('roles.grantSuperAdminDesc') }}
          </span>
        </div>
      </label>

      <!-- Permissions Matrix Section (Hidden when Super Admin is enabled) -->
      <div v-if="!effectiveSuperAdmin" class="space-y-4">
        <div class="flex flex-col gap-3 rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3.5 sm:flex-row sm:items-center sm:justify-between">
          <div class="flex flex-wrap items-center gap-2.5">
            <h4 class="text-sm font-extrabold text-[var(--text)]">
              {{ $t('roles.selectPermissionsForRole') }}
            </h4>
            <span class="inline-flex items-center rounded-full bg-[var(--crimson-soft)] px-2.5 py-0.5 text-xs font-bold text-[var(--crimson)]">
              {{ $t('roles.selectedPermissionsCount', { selected: selectedCount, total: totalPermissions }) }}
            </span>
          </div>

          <div class="flex flex-wrap items-center gap-2">
            <!-- Quick filter inside matrix -->
            <div class="relative flex-1 sm:w-56 sm:flex-initial">
              <Search
                class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]"
                aria-hidden="true"
              />
              <input
                v-model="permSearch"
                type="text"
                :placeholder="$t('roles.permissionsSearchPlaceholder')"
                class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] pe-3 ps-9 text-xs font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              />
            </div>

            <template v-if="!permissionsLocked">
              <button
                type="button"
                class="inline-flex h-9 items-center rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-xs font-bold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 hover:text-[var(--crimson)]"
                @click="selectAll"
              >
                {{ $t('roles.selectAll') }}
              </button>
              <button
                type="button"
                class="inline-flex h-9 items-center rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-xs font-bold text-[var(--muted)] transition-colors hover:text-[var(--text)]"
                @click="clearAll"
              >
                {{ $t('roles.clearAll') }}
              </button>
            </template>
          </div>
        </div>

        <p v-if="errors.permissions || errors.role" class="text-xs font-semibold text-[var(--crimson)]">
          {{ firstError(errors.permissions || errors.role) }}
        </p>

        <!-- Loading Matrix -->
        <div v-if="matrixLoading" class="flex flex-col items-center justify-center gap-3 py-12 text-[var(--muted)]">
          <span class="h-8 w-8 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <p class="text-sm font-semibold">{{ $t('common.loading') }}</p>
        </div>

        <!-- Category Accordion Cards -->
        <div v-else class="space-y-3">
          <div
            v-for="category in filteredCategories"
            :key="category.key"
            class="overflow-hidden rounded-xl border border-[var(--border)] bg-[var(--card)]"
          >
            <!-- Category Header -->
            <div class="flex items-center justify-between gap-3 bg-[var(--bg)] px-4 py-3">
              <div class="flex items-center gap-3">
                <AppCheckbox
                  v-if="!permissionsLocked"
                  :checked="isCategoryFullySelected(category)"
                  :indeterminate="isCategoryPartiallySelected(category)"
                  :aria-label="`${$t('roles.categorySelectAll')} - ${category.label}`"
                  @change="toggleCategory(category)"
                />
                <button
                  type="button"
                  class="flex items-center gap-2 text-start text-sm font-extrabold text-[var(--text)] transition-colors hover:text-[var(--crimson)]"
                  @click="toggleCollapse(category.key)"
                >
                  <span>{{ category.label }}</span>
                  <span class="rounded-full border border-[var(--border)] bg-[var(--card)] px-2 py-0.5 text-[11px] font-bold text-[var(--muted)]">
                    {{ categorySelectedCount(category) }} / {{ categoryTotalCount(category) }}
                  </span>
                </button>
              </div>

              <button
                type="button"
                class="rounded-lg p-1 text-[var(--muted)] transition-colors hover:bg-[var(--card)] hover:text-[var(--text)]"
                :aria-expanded="!collapsedKeys.has(category.key)"
                @click="toggleCollapse(category.key)"
              >
                <ChevronDown
                  class="h-4 w-4 transition-transform duration-200"
                  :class="collapsedKeys.has(category.key) ? '-rotate-90' : ''"
                  aria-hidden="true"
                />
              </button>
            </div>

            <!-- Modules inside Category -->
            <div
              v-show="!collapsedKeys.has(category.key)"
              class="divide-y divide-[var(--border)]"
            >
              <div
                v-for="mod in category.modules"
                :key="mod.name"
                class="flex flex-col gap-3 px-4 py-3.5 lg:flex-row lg:items-start lg:gap-6"
              >
                <!-- Module Title + Module Toggle -->
                <div class="flex shrink-0 items-center gap-2.5 lg:w-56">
                  <AppCheckbox
                    v-if="!permissionsLocked"
                    :checked="isModuleFullySelected(mod)"
                    :indeterminate="isModulePartiallySelected(mod)"
                    :aria-label="`${$t('roles.toggleModule')} - ${mod.label}`"
                    @change="toggleModule(mod)"
                  />
                  <span class="text-sm font-bold text-[var(--text)]">
                    {{ mod.label }}
                  </span>
                </div>

                <!-- Permission Pills -->
                <div class="flex flex-1 flex-wrap gap-2">
                  <label
                    v-for="perm in mod.permissions"
                    :key="perm.name"
                    class="inline-flex select-none items-center gap-2 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors"
                    :class="[
                      permissionsLocked ? 'cursor-default' : 'cursor-pointer',
                      isSelected(perm.name)
                        ? 'border-[var(--crimson)]/40 bg-[var(--crimson-soft)] text-[var(--crimson)]'
                        : 'border-[var(--border)] bg-[var(--bg)] text-[var(--text)] hover:border-[var(--crimson)]/30',
                    ]"
                  >
                    <input
                      type="checkbox"
                      class="sr-only"
                      :checked="isSelected(perm.name)"
                      :disabled="permissionsLocked"
                      @change="togglePermission(perm.name)"
                    />
                    <span
                      class="flex h-4 w-4 items-center justify-center rounded border text-white transition-colors"
                      :class="isSelected(perm.name)
                        ? 'border-[var(--crimson)] bg-[var(--crimson)]'
                        : 'border-[var(--border)] bg-[var(--card)]'"
                    >
                      <Check v-if="isSelected(perm.name)" class="h-3 w-3" aria-hidden="true" />
                    </span>
                    <span>{{ perm.label }}</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </CrudModal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Check, ChevronDown, Search, ShieldAlert } from 'lucide-vue-next';
import { useAuthStore } from '../../stores/auth';
import CrudModal from '../crud/CrudModal.vue';
import AppCheckbox from '../ui/AppCheckbox.vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  mode: { type: String, default: 'create' }, // 'create' | 'edit' | 'view'
  role: { type: Object, default: null },
  categories: { type: Array, default: () => [] },
  totalPermissions: { type: Number, default: 0 },
  matrixLoading: { type: Boolean, default: false },
  saving: { type: Boolean, default: false },
  errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'save']);

const { t } = useI18n();
const authStore = useAuthStore();

const localName = ref('');
const localDescription = ref('');
const localIsSuperAdmin = ref(false);
const selectedSet = ref(new Set());
const permSearch = ref('');
const collapsedKeys = ref(new Set());

const canGrantSuperAdmin = computed(() => authStore.isSuperAdmin);
const isSuperAdmin = computed(() => Boolean(props.role?.is_super_admin));
const effectiveSuperAdmin = computed(() => isSuperAdmin.value || localIsSuperAdmin.value);
const isSystem = computed(() => Boolean(props.role?.is_system));
const readonly = computed(() => props.mode === 'view' || isSuperAdmin.value);
const permissionsLocked = computed(() => readonly.value || localIsSuperAdmin.value);

const modalTitle = computed(() => {
  if (props.mode === 'create') return t('roles.createRoleTitle');
  if (readonly.value) return t('roles.viewRoleTitle', { name: props.role?.name || '' });
  return t('roles.editRoleTitle', { name: props.role?.name || '' });
});

const allPermissionNames = computed(() =>
  props.categories.flatMap((cat) => cat.modules.flatMap((mod) => mod.permissions.map((p) => p.name)))
);

const selectedCount = computed(() =>
  effectiveSuperAdmin.value ? props.totalPermissions : selectedSet.value.size
);

const filteredCategories = computed(() => {
  const q = permSearch.value.trim().toLowerCase();
  if (!q) return props.categories;

  return props.categories
    .map((cat) => {
      if (cat.label.toLowerCase().includes(q) || cat.key.toLowerCase().includes(q)) {
        return cat;
      }

      const modules = cat.modules
        .map((mod) => {
          if (mod.label.toLowerCase().includes(q) || mod.name.toLowerCase().includes(q)) {
            return mod;
          }

          const permissions = mod.permissions.filter(
            (p) => p.label.toLowerCase().includes(q) || p.name.toLowerCase().includes(q)
          );

          return permissions.length ? { ...mod, permissions } : null;
        })
        .filter(Boolean);

      return modules.length ? { ...cat, modules } : null;
    })
    .filter(Boolean);
});

const firstError = (val) => (Array.isArray(val) ? val[0] : val);

const isSelected = (permName) => effectiveSuperAdmin.value || selectedSet.value.has(permName);

const togglePermission = (permName) => {
  if (permissionsLocked.value) return;
  const next = new Set(selectedSet.value);
  if (next.has(permName)) {
    next.delete(permName);
  } else {
    next.add(permName);
  }
  selectedSet.value = next;
};

const modulePermissionNames = (mod) => mod.permissions.map((p) => p.name);
const categoryPermissionNames = (cat) => cat.modules.flatMap((m) => m.permissions.map((p) => p.name));

const isModuleFullySelected = (mod) => {
  const names = modulePermissionNames(mod);
  return names.length > 0 && names.every((n) => isSelected(n));
};

const isModulePartiallySelected = (mod) => {
  const names = modulePermissionNames(mod);
  const count = names.filter((n) => isSelected(n)).length;
  return count > 0 && count < names.length;
};

const toggleModule = (mod) => {
  if (readonly.value) return;
  const names = modulePermissionNames(mod);
  const next = new Set(selectedSet.value);
  const allOn = names.every((n) => next.has(n));
  names.forEach((n) => (allOn ? next.delete(n) : next.add(n)));
  selectedSet.value = next;
};

const categoryTotalCount = (cat) => categoryPermissionNames(cat).length;
const categorySelectedCount = (cat) =>
  effectiveSuperAdmin.value
    ? categoryTotalCount(cat)
    : categoryPermissionNames(cat).filter((n) => selectedSet.value.has(n)).length;

const isCategoryFullySelected = (cat) => {
  const total = categoryTotalCount(cat);
  return total > 0 && categorySelectedCount(cat) === total;
};

const isCategoryPartiallySelected = (cat) => {
  const count = categorySelectedCount(cat);
  return count > 0 && count < categoryTotalCount(cat);
};

const toggleCategory = (cat) => {
  if (readonly.value) return;
  const names = categoryPermissionNames(cat);
  const next = new Set(selectedSet.value);
  const allOn = names.every((n) => next.has(n));
  names.forEach((n) => (allOn ? next.delete(n) : next.add(n)));
  selectedSet.value = next;
};

const selectAll = () => {
  if (readonly.value) return;
  selectedSet.value = new Set(allPermissionNames.value);
};

const clearAll = () => {
  if (readonly.value) return;
  selectedSet.value = new Set();
};

const toggleCollapse = (key) => {
  const next = new Set(collapsedKeys.value);
  if (next.has(key)) {
    next.delete(key);
  } else {
    next.add(key);
  }
  collapsedKeys.value = next;
};

const syncFromProps = () => {
  permSearch.value = '';
  collapsedKeys.value = new Set();

  if (props.mode === 'create' || !props.role) {
    localName.value = '';
    localDescription.value = '';
    localIsSuperAdmin.value = false;
    selectedSet.value = new Set();
    return;
  }

  localName.value = props.role.name || '';
  localDescription.value = props.role.description || '';
  localIsSuperAdmin.value = Boolean(props.role.is_super_admin);
  const perms = (props.role.permissions || []).map((p) => (typeof p === 'string' ? p : p.name));
  selectedSet.value = new Set(perms);
};

watch(() => [props.show, props.role, props.mode], syncFromProps, { immediate: true });

const handleSave = () => {
  if (readonly.value) return;
  emit('save', {
    name: localName.value.trim(),
    description: localDescription.value.trim() || null,
    is_super_admin: props.mode === 'create' ? localIsSuperAdmin.value : undefined,
    permissions: localIsSuperAdmin.value ? [] : Array.from(selectedSet.value),
  });
};
</script>
