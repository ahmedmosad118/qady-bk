<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader :title="$t('chartOfAccounts.title')" :subtitle="$t('chartOfAccounts.subtitle')">
      <template #actions>
        <button
          v-if="canCreate"
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreateModal"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('chartOfAccounts.addAccount') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Error Banner -->
    <div
      v-if="treeError"
      class="flex items-center justify-between gap-4 rounded-2xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] p-4 text-sm font-semibold text-[var(--crimson)]"
      role="alert"
    >
      <div class="flex items-center gap-2">
        <AlertCircle class="h-5 w-5 shrink-0" />
        <span>{{ treeError }}</span>
      </div>
      <button type="button" class="underline underline-offset-4 cursor-pointer" @click="loadTree">
        {{ $t('common.retry') }}
      </button>
    </div>

    <!-- File Explorer Layout -->
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
      <!-- Sidebar Virtual Tree -->
      <aside class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] lg:col-span-4 xl:col-span-3">
        <div class="border-b border-[var(--border)] p-4 space-y-3">
          <div class="flex items-center justify-between gap-2">
            <span class="text-xs font-extrabold text-[var(--text)]">{{ $t('chartOfAccounts.treeTitle') }}</span>
            <div class="flex items-center gap-1">
              <button
                type="button"
                class="rounded-lg px-2 py-1 text-[11px] font-bold text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                @click="expandAllClasses"
              >
                {{ $t('chartOfAccounts.expandAll') }}
              </button>
              <button
                type="button"
                class="rounded-lg px-2 py-1 text-[11px] font-bold text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                @click="collapseAllClasses"
              >
                {{ $t('chartOfAccounts.collapseAll') }}
              </button>
            </div>
          </div>

          <div class="relative">
            <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]" />
            <input
              v-model="treeSearch"
              type="text"
              :placeholder="$t('chartOfAccounts.treeSearchPlaceholder')"
              class="h-9 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] ps-9 pe-3 text-xs font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
            />
          </div>
        </div>

        <div v-if="treeLoading" class="p-8 text-center">
          <div class="inline-flex items-center gap-2 text-xs text-[var(--muted)]">
            <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
            <span>{{ $t('common.loading') }}</span>
          </div>
        </div>

        <nav v-else class="max-h-[65vh] overflow-y-auto p-2 space-y-1">
          <!-- Root node -->
          <button
            type="button"
            class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-start text-xs font-bold transition-colors cursor-pointer"
            :class="!selectedClass && !selectedType
              ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
              : 'text-[var(--text)] hover:bg-[var(--bg)]'"
            @click="selectRoot"
          >
            <span class="flex items-center gap-2">
              <Landmark class="h-4 w-4 shrink-0" />
              <span>{{ $t('chartOfAccounts.rootLabel') }}</span>
            </span>
          </button>

          <!-- Classes & Types -->
          <div v-for="cls in filteredTree" :key="cls.id" class="space-y-0.5">
            <div
              class="flex items-center gap-1 rounded-xl px-2 py-1.5 transition-colors"
              :class="selectedClass?.id === cls.id && !selectedType
                ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
                : 'text-[var(--text)] hover:bg-[var(--bg)]'"
            >
              <button
                type="button"
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg text-[var(--muted)] hover:text-[var(--text)] cursor-pointer"
                @click.stop="toggleClassExpand(cls.id)"
              >
                <ChevronDown v-if="isClassExpanded(cls.id)" class="h-3.5 w-3.5" />
                <ChevronLeft v-else class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
              </button>

              <button
                type="button"
                class="flex flex-1 items-center justify-between gap-2 text-start text-xs font-extrabold cursor-pointer"
                @click="selectClass(cls)"
              >
                <span class="flex items-center gap-2 truncate">
                  <FolderOpen v-if="isClassExpanded(cls.id)" class="h-4 w-4 shrink-0 text-[var(--crimson)]" />
                  <Folder v-else class="h-4 w-4 shrink-0 text-[var(--muted)]" />
                  <span class="truncate">{{ cls.name }}</span>
                </span>
                <span class="rounded-full bg-[var(--bg)] px-2 py-0.5 text-[10px] font-bold text-[var(--muted)]">
                  {{ cls.account_types.length }}
                </span>
              </button>
            </div>

            <!-- Types inside Class -->
            <div v-if="isClassExpanded(cls.id)" class="ms-5 space-y-0.5 border-s border-[var(--border)] ps-2">
              <button
                v-for="type in cls.account_types"
                :key="type.id"
                type="button"
                class="flex w-full items-center justify-between gap-2 rounded-xl px-2.5 py-1.5 text-start text-xs font-semibold transition-colors cursor-pointer"
                :class="selectedType?.id === type.id
                  ? 'bg-[var(--crimson-soft)] text-[var(--crimson)] font-bold'
                  : 'text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]'"
                @click="selectType(cls, type)"
              >
                <span class="flex items-center gap-2 truncate">
                  <Folder class="h-3.5 w-3.5 shrink-0" />
                  <span class="truncate">{{ type.name }}</span>
                </span>
                <span class="rounded-full bg-[var(--bg)] px-1.5 py-0.5 text-[10px] font-bold">
                  {{ type.accounts_count ?? 0 }}
                </span>
              </button>
            </div>
          </div>
        </nav>
      </aside>

      <!-- Main Workspace -->
      <section class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] lg:col-span-8 xl:col-span-9">
        <!-- Breadcrumbs & Back Bar -->
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-3.5">
          <div class="flex flex-wrap items-center gap-1.5 text-xs font-bold">
            <button
              type="button"
              class="text-[var(--muted)] hover:text-[var(--crimson)] cursor-pointer"
              @click="selectRoot"
            >
              {{ $t('chartOfAccounts.rootLabel') }}
            </button>

            <template v-if="selectedClass">
              <ChevronLeft class="h-3.5 w-3.5 text-[var(--muted)] rtl:rotate-0 ltr:rotate-180" />
              <button
                type="button"
                class="hover:text-[var(--crimson)] cursor-pointer"
                :class="selectedType ? 'text-[var(--muted)]' : 'text-[var(--text)]'"
                @click="selectClass(selectedClass)"
              >
                {{ selectedClass.name }}
              </button>
            </template>

            <template v-if="selectedType">
              <ChevronLeft class="h-3.5 w-3.5 text-[var(--muted)] rtl:rotate-0 ltr:rotate-180" />
              <button
                type="button"
                class="hover:text-[var(--crimson)] cursor-pointer"
                :class="selectedAccount ? 'text-[var(--muted)]' : 'text-[var(--text)]'"
                @click="selectType(selectedClass, selectedType)"
              >
                {{ selectedType.name }}
              </button>
            </template>

            <template v-if="selectedAccount">
              <ChevronLeft class="h-3.5 w-3.5 text-[var(--muted)] rtl:rotate-0 ltr:rotate-180" />
              <span class="text-[var(--text)]">{{ selectedAccount.code }} — {{ selectedAccount.name }}</span>
            </template>
          </div>

          <button
            v-if="selectedClass || selectedType || selectedAccount"
            type="button"
            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] hover:border-[var(--crimson)]/40 cursor-pointer"
            @click="goBack"
          >
            <ArrowRight class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
            <span>{{ $t('chartOfAccounts.backLevel') }}</span>
          </button>
        </div>

        <!-- Level 3: Account Details View -->
        <div v-if="selectedAccount" class="p-6 space-y-6">
          <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <span class="rounded-lg bg-[var(--bg)] px-2.5 py-1 font-mono text-sm font-extrabold text-[var(--text)]">
                  {{ selectedAccount.code }}
                </span>
                <h2 class="text-lg font-extrabold text-[var(--text)]">{{ selectedAccount.name }}</h2>
                <span
                  v-if="selectedAccount.is_system"
                  class="rounded-full bg-amber-500/10 px-2.5 py-0.5 text-[11px] font-bold text-amber-700 dark:text-amber-300"
                >
                  {{ $t('chartOfAccounts.systemBadge') }}
                </span>
              </div>
              <p class="text-xs text-[var(--muted)]">
                {{ selectedAccount.account_type?.account_class?.name }} / {{ selectedAccount.account_type?.name }}
              </p>
            </div>

            <button
              v-if="canUpdate"
              type="button"
              class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
              @click="openEditModal(selectedAccount)"
            >
              <Pencil class="h-3.5 w-3.5" />
              <span>{{ $t('chartOfAccounts.editAccount') }}</span>
            </button>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-4">
              <div class="text-[11px] font-bold text-[var(--muted)]">{{ $t('chartOfAccounts.accountClass') }}</div>
              <div class="mt-1 text-sm font-extrabold text-[var(--text)]">
                {{ selectedAccount.account_type?.account_class?.name || '—' }}
              </div>
            </div>
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-4">
              <div class="text-[11px] font-bold text-[var(--muted)]">{{ $t('chartOfAccounts.accountType') }}</div>
              <div class="mt-1 text-sm font-extrabold text-[var(--text)]">
                {{ selectedAccount.account_type?.name || '—' }}
              </div>
            </div>
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-4">
              <div class="text-[11px] font-bold text-[var(--muted)]">{{ $t('chartOfAccounts.normalBalanceOverride') }}</div>
              <div class="mt-1 text-sm font-extrabold text-[var(--text)]">
                {{ formatNormalBalance(selectedAccount.normal_balance_override) }}
              </div>
            </div>
            <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-4">
              <div class="text-[11px] font-bold text-[var(--muted)]">{{ $t('chartOfAccounts.reconcile') }} / {{ $t('chartOfAccounts.isActive') }}</div>
              <div class="mt-1 flex items-center gap-2">
                <span class="text-xs font-bold text-[var(--text)]">
                  {{ selectedAccount.reconcile ? $t('chartOfAccounts.reconcileYes') : $t('chartOfAccounts.reconcileNo') }}
                </span>
                <span class="text-[var(--muted)]">•</span>
                <span
                  class="text-xs font-bold"
                  :class="selectedAccount.is_active ? 'text-emerald-600 dark:text-emerald-400' : 'text-[var(--muted)]'"
                >
                  {{ selectedAccount.is_active ? $t('common.active') : $t('common.inactive') }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Level 2: Account Type -> Accounts Table -->
        <div v-else-if="selectedType">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-3">
            <div class="relative w-full max-w-xs">
              <Search class="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)]" />
              <input
                v-model="accountSearch"
                type="text"
                :placeholder="$t('chartOfAccounts.searchPlaceholder')"
                class="h-9 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] ps-9 pe-3 text-xs font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
                @input="debouncedLoadAccounts"
              />
            </div>

            <span class="text-xs font-bold text-[var(--muted)]">
              {{ $t('chartOfAccounts.sectionCount', { count: meta.total }) }}
            </span>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
              <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
                <tr>
                  <th class="px-5 py-3.5 text-start">{{ $t('chartOfAccounts.accountCode') }}</th>
                  <th class="px-5 py-3.5 text-start">{{ $t('chartOfAccounts.accountName') }}</th>
                  <th class="px-5 py-3.5 text-start">{{ $t('chartOfAccounts.normalBalanceOverride') }}</th>
                  <th class="px-5 py-3.5 text-start">{{ $t('chartOfAccounts.reconcile') }}</th>
                  <th class="px-5 py-3.5 text-start">{{ $t('chartOfAccounts.isActive') }}</th>
                  <th class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
                </tr>
              </thead>

              <tbody class="divide-y divide-[var(--border)]">
                <tr v-if="accountsLoading">
                  <td colspan="6" class="py-16 text-center">
                    <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                      <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                      <span>{{ $t('common.loading') }}</span>
                    </div>
                  </td>
                </tr>

                <tr v-else-if="accounts.length === 0">
                  <td colspan="6" class="py-16 text-center">
                    <div class="mx-auto flex max-w-sm flex-col items-center">
                      <FileText class="h-10 w-10 text-[var(--muted)]/50" />
                      <h3 class="mt-3 text-sm font-extrabold text-[var(--text)]">
                        {{ $t('chartOfAccounts.emptyTitle') }}
                      </h3>
                      <p class="mt-1 text-xs text-[var(--muted)]">
                        {{ $t('chartOfAccounts.noAccountsInSection') }}
                      </p>
                      <button
                        v-if="canCreate"
                        type="button"
                        class="mt-4 inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
                        @click="openCreateModal"
                      >
                        <Plus class="h-4 w-4" />
                        <span>{{ $t('chartOfAccounts.addAccount') }}</span>
                      </button>
                    </div>
                  </td>
                </tr>

                <tr
                  v-for="acc in accounts"
                  :key="acc.id"
                  class="transition-colors hover:bg-[var(--bg)]/50"
                >
                  <td class="px-5 py-3.5 font-mono text-xs font-bold text-[var(--text)]">
                    {{ acc.code }}
                  </td>

                  <td class="px-5 py-3.5">
                    <div class="flex items-center gap-2">
                      <button
                        type="button"
                        class="font-bold text-[var(--text)] hover:text-[var(--crimson)] hover:underline text-start cursor-pointer"
                        @click="selectAccount(acc)"
                      >
                        {{ acc.name }}
                      </button>
                      <span
                        v-if="acc.is_system"
                        class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-300"
                      >
                        {{ $t('chartOfAccounts.systemBadge') }}
                      </span>
                    </div>
                  </td>

                  <td class="px-5 py-3.5 text-xs font-semibold text-[var(--muted)]">
                    {{ formatNormalBalance(acc.normal_balance_override) }}
                  </td>

                  <td class="px-5 py-3.5 text-xs font-semibold text-[var(--text)]">
                    {{ acc.reconcile ? $t('chartOfAccounts.reconcileYes') : $t('chartOfAccounts.reconcileNo') }}
                  </td>

                  <td class="px-5 py-3.5">
                    <span
                      class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                      :class="acc.is_active
                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                        : 'bg-[var(--bg)] text-[var(--muted)]'"
                    >
                      {{ acc.is_active ? $t('common.active') : $t('common.inactive') }}
                    </span>
                  </td>

                  <td class="px-5 py-3.5 text-end">
                    <div class="flex items-center justify-end gap-1">
                      <button
                        v-if="canUpdate"
                        type="button"
                        :title="$t('common.edit')"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                        @click="openEditModal(acc)"
                      >
                        <Pencil class="h-4 w-4" />
                      </button>
                      <button
                        v-if="canDelete"
                        type="button"
                        :title="acc.can_delete ? $t('common.delete') : $t('chartOfAccounts.cannotDeleteAccount')"
                        :disabled="!acc.can_delete"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] disabled:opacity-40 cursor-pointer"
                        @click="deleteAccount(acc)"
                      >
                        <Trash2 class="h-4 w-4" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <TablePagination
            :meta="meta"
            :items-count="accounts.length"
            :per-page="meta.per_page"
            :loading="accountsLoading"
            @update:per-page="meta.per_page = $event"
            @change-per-page="loadAccounts(1)"
            @change-page="loadAccounts"
          />
        </div>

        <!-- Level 1: Account Class -> Account Type Folders -->
        <div v-else-if="selectedClass" class="p-5">
          <div
            v-if="(selectedClass.account_types || []).length === 0"
            class="py-16 text-center text-sm text-[var(--muted)]"
          >
            {{ $t('chartOfAccounts.noTypesInClass') }}
          </div>

          <div v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <button
              v-for="type in selectedClass.account_types"
              :key="type.id"
              type="button"
              class="group flex items-center justify-between gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 text-start transition-all hover:border-[var(--crimson)]/50 hover:shadow-sm cursor-pointer"
              @click="selectType(selectedClass, type)"
            >
              <div class="flex items-center gap-3 min-w-0">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
                  <Folder class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                  <div class="truncate text-sm font-extrabold text-[var(--text)] group-hover:text-[var(--crimson)]">
                    {{ type.name }}
                  </div>
                  <div class="mt-0.5 text-xs font-semibold text-[var(--muted)]">
                    {{ $t('chartOfAccounts.sectionCount', { count: type.accounts_count ?? 0 }) }}
                  </div>
                </div>
              </div>
              <ChevronLeft class="h-4 w-4 shrink-0 text-[var(--muted)] rtl:rotate-0 ltr:rotate-180" />
            </button>
          </div>
        </div>

        <!-- Level 0: Root -> Account Class Folders -->
        <div v-else class="p-5">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <button
              v-for="cls in tree"
              :key="cls.id"
              type="button"
              class="group flex items-center justify-between gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 text-start transition-all hover:border-[var(--crimson)]/50 hover:shadow-sm cursor-pointer"
              @click="selectClass(cls)"
            >
              <div class="flex items-center gap-3 min-w-0">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
                  <FolderOpen class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                  <div class="truncate text-sm font-extrabold text-[var(--text)] group-hover:text-[var(--crimson)]">
                    {{ cls.name }}
                  </div>
                  <div class="mt-0.5 text-xs font-semibold text-[var(--muted)]">
                    {{ $t('chartOfAccounts.typesCount', { count: (cls.account_types || []).length }) }}
                  </div>
                </div>
              </div>
              <ChevronLeft class="h-4 w-4 shrink-0 text-[var(--muted)] rtl:rotate-0 ltr:rotate-180" />
            </button>
          </div>
        </div>
      </section>
    </div>

    <!-- Add / Edit Account Modal -->
    <ChartOfAccountFormModal
      :open="modalOpen"
      :editing-account="editingAccount"
      :default-account-type-id="selectedType?.id ?? null"
      :account-classes="tree"
      @close="modalOpen = false"
      @saved="handleAccountSaved"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  ArrowRight,
  ChevronDown,
  ChevronLeft,
  FileText,
  Folder,
  FolderOpen,
  Landmark,
  Pencil,
  Plus,
  Search,
  Trash2,
} from 'lucide-vue-next';
import api from '../../services/api';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import { useAuthStore } from '../../stores/auth';
import PageHeader from '../../components/ui/PageHeader.vue';
import TablePagination from '../../components/ui/TablePagination.vue';
import ChartOfAccountFormModal from './ChartOfAccountFormModal.vue';

const { t } = useI18n();
const authStore = useAuthStore();

const canCreate = computed(() => authStore.hasPermission('accounting.accounts.create'));
const canUpdate = computed(() => authStore.hasPermission('accounting.accounts.update'));
const canDelete = computed(() => authStore.hasPermission('accounting.accounts.delete'));

const tree = ref([]);
const treeLoading = ref(false);
const treeError = ref('');
const treeSearch = ref('');
const expandedClassIds = ref([]);

const selectedClass = ref(null);
const selectedType = ref(null);
const selectedAccount = ref(null);

const accounts = ref([]);
const accountsLoading = ref(false);
const accountSearch = ref('');
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const modalOpen = ref(false);
const editingAccount = ref(null);

let searchTimer = null;

const filteredTree = computed(() => {
  const term = treeSearch.value.trim().toLowerCase();
  if (!term) {
    return tree.value;
  }

  return tree.value
    .map((cls) => {
      const classMatches = (cls.name || '').toLowerCase().includes(term);
      const matchingTypes = (cls.account_types || []).filter((type) =>
        (type.name || '').toLowerCase().includes(term)
      );

      if (classMatches) {
        return cls;
      }

      if (matchingTypes.length > 0) {
        return { ...cls, account_types: matchingTypes };
      }

      return null;
    })
    .filter(Boolean);
});

function isClassExpanded(classId) {
  if (treeSearch.value.trim() !== '') {
    return true;
  }
  return expandedClassIds.value.includes(classId);
}

function toggleClassExpand(classId) {
  if (expandedClassIds.value.includes(classId)) {
    expandedClassIds.value = expandedClassIds.value.filter((id) => id !== classId);
  } else {
    expandedClassIds.value = [...expandedClassIds.value, classId];
  }
}

function expandAllClasses() {
  expandedClassIds.value = tree.value.map((cls) => cls.id);
}

function collapseAllClasses() {
  expandedClassIds.value = [];
}

function selectRoot() {
  selectedClass.value = null;
  selectedType.value = null;
  selectedAccount.value = null;
}

function selectClass(cls) {
  selectedClass.value = cls;
  selectedType.value = null;
  selectedAccount.value = null;
  if (!expandedClassIds.value.includes(cls.id)) {
    expandedClassIds.value = [...expandedClassIds.value, cls.id];
  }
}

function selectType(cls, type) {
  selectedClass.value = cls;
  selectedType.value = type;
  selectedAccount.value = null;
  accountSearch.value = '';
  if (!expandedClassIds.value.includes(cls.id)) {
    expandedClassIds.value = [...expandedClassIds.value, cls.id];
  }
  loadAccounts(1);
}

function selectAccount(account) {
  selectedAccount.value = account;
}

function goBack() {
  if (selectedAccount.value) {
    selectedAccount.value = null;
    return;
  }
  if (selectedType.value) {
    selectedType.value = null;
    return;
  }
  if (selectedClass.value) {
    selectedClass.value = null;
  }
}

function formatNormalBalance(val) {
  if (!val) {
    return t('chartOfAccounts.normalBalanceDefault');
  }
  return t(`chartOfAccounts.normalBalances.${val}`);
}

async function loadTree() {
  treeLoading.value = true;
  treeError.value = '';
  try {
    const res = await api.get('/accounting/accounts/tree');
    tree.value = res.data?.data || [];
    if (expandedClassIds.value.length === 0 && tree.value.length > 0) {
      expandedClassIds.value = [tree.value[0].id];
    }

    if (selectedClass.value) {
      const freshClass = tree.value.find((c) => c.id === selectedClass.value.id) || null;
      selectedClass.value = freshClass;
      if (freshClass && selectedType.value) {
        selectedType.value = (freshClass.account_types || []).find((tp) => tp.id === selectedType.value.id) || null;
      }
    }
  } catch (err) {
    treeError.value = err.response?.data?.message || t('chartOfAccounts.loadError');
  } finally {
    treeLoading.value = false;
  }
}

async function loadAccounts(page = 1) {
  if (!selectedType.value) {
    return;
  }

  accountsLoading.value = true;
  try {
    const res = await api.get('/accounting/accounts', {
      params: {
        account_type_id: selectedType.value.id,
        search: accountSearch.value.trim() || undefined,
        page,
        per_page: meta.per_page,
      },
    });

    accounts.value = res.data?.data || [];
    const resMeta = res.data?.meta || {};
    meta.current_page = resMeta.current_page || 1;
    meta.last_page = resMeta.last_page || 1;
    meta.per_page = resMeta.per_page || 15;
    meta.total = resMeta.total || 0;
  } catch (err) {
    showError({
      title: t('common.error'),
      text: err.response?.data?.message || t('chartOfAccounts.loadError'),
    });
  } finally {
    accountsLoading.value = false;
  }
}

function debouncedLoadAccounts() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    loadAccounts(1);
  }, 300);
}

function openCreateModal() {
  editingAccount.value = null;
  modalOpen.value = true;
}

function openEditModal(account) {
  editingAccount.value = account;
  modalOpen.value = true;
}

async function handleAccountSaved(savedAccount) {
  modalOpen.value = false;
  await loadTree();

  if (selectedAccount.value && savedAccount?.id === selectedAccount.value.id) {
    selectedAccount.value = savedAccount;
  }

  if (selectedType.value) {
    await loadAccounts(meta.current_page);
  }
}

async function deleteAccount(account) {
  const confirmed = await confirmDelete({
    title: t('chartOfAccounts.confirmDeleteTitle'),
    text: t('chartOfAccounts.confirmDeleteText', { name: account.name, code: account.code }),
  });

  if (!confirmed) {
    return;
  }

  try {
    await api.delete(`/accounting/accounts/${account.id}`);
    showSuccess({
      title: t('common.successTitle'),
      text: t('chartOfAccounts.deletedSuccess'),
    });
    await loadTree();
    await loadAccounts(meta.current_page);
  } catch (err) {
    showError({
      title: t('common.error'),
      text: err.response?.data?.message || t('common.errorTitle'),
    });
  }
}

onMounted(() => {
  loadTree();
});
</script>
