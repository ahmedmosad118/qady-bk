<template>
  <div class="space-y-4">
    <!-- Breadcrumbs -->
    <nav class="flex items-center gap-1.5 text-xs font-bold text-[var(--muted)]" aria-label="Breadcrumb">
      <span>{{ $t('nav.accountingGroup') }}</span>
      <ChevronLeft class="h-3.5 w-3.5 rtl:rotate-0 ltr:rotate-180" />
      <span class="text-[var(--text)]">{{ $t('journals.title') }}</span>
    </nav>

    <!-- Page Header -->
    <PageHeader :title="$t('journals.title')" :subtitle="$t('journals.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="openCreate"
        >
          <Plus class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('journals.addJournal') }}</span>
        </button>
      </template>
    </PageHeader>

    <!-- Main Card -->
    <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
      <FilterToolbar
        v-model:search="filters.search"
        :search-placeholder="$t('journals.searchPlaceholder')"
        :active-filters-count="activeFiltersCount"
        @search="debouncedLoad"
        @reset="resetFilters"
      >
        <select
          v-model="filters.type"
          class="h-10 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          @change="loadJournals(1)"
        >
          <option value="">{{ $t('journals.allTypes') }}</option>
          <option v-for="tOpt in journalTypes" :key="tOpt" :value="tOpt">
            {{ $t(`journals.types.${tOpt}`) }}
          </option>
        </select>

        <select
          v-model="filters.is_active"
          class="h-10 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
          @change="loadJournals(1)"
        >
          <option value="">{{ $t('journals.allStatuses') }}</option>
          <option value="1">{{ $t('common.active') }}</option>
          <option value="0">{{ $t('common.inactive') }}</option>
        </select>
      </FilterToolbar>

      <div v-if="loadError" class="flex flex-col items-center justify-center gap-3 px-6 py-14 text-center" role="alert">
        <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
        <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadJournals(meta.current_page)"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-start text-sm">
          <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
            <tr>
              <th class="px-5 py-3.5 text-start">{{ $t('journals.code') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('journals.name') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('journals.type') }}</th>
              <th class="px-5 py-3.5 text-start">{{ $t('common.status') }}</th>
              <th class="px-5 py-3.5 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-[var(--border)]">
            <tr v-if="loading && journals.length === 0">
              <td colspan="5" class="py-16 text-center">
                <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
                  <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
                  <span>{{ $t('common.loading') }}</span>
                </div>
              </td>
            </tr>

            <tr v-else-if="journals.length === 0">
              <td colspan="5" class="py-16 text-center">
                <div class="mx-auto flex max-w-sm flex-col items-center">
                  <BookOpen class="h-10 w-10 text-[var(--muted)]/50" />
                  <h3 class="mt-3 text-sm font-extrabold text-[var(--text)]">{{ $t('journals.emptyTitle') }}</h3>
                  <p class="mt-1 text-xs text-[var(--muted)]">{{ $t('journals.emptyDesc') }}</p>
                </div>
              </td>
            </tr>

            <tr
              v-for="journal in journals"
              :key="journal.id"
              class="transition-colors hover:bg-[var(--bg)]/50"
            >
              <td class="px-5 py-3.5 font-mono text-xs font-bold text-[var(--text)]">{{ journal.code }}</td>
              <td class="px-5 py-3.5 font-bold text-[var(--text)]">{{ journal.name }}</td>
              <td class="px-5 py-3.5">
                <span class="inline-flex items-center rounded-full bg-blue-500/10 px-2.5 py-0.5 text-xs font-bold text-blue-700 dark:text-blue-300">
                  {{ $t(`journals.types.${journal.type}`) }}
                </span>
              </td>
              <td class="px-5 py-3.5">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-bold"
                  :class="journal.is_active
                    ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                    : 'bg-[var(--bg)] text-[var(--muted)]'"
                >
                  {{ journal.is_active ? $t('common.active') : $t('common.inactive') }}
                </span>
              </td>
              <td class="px-5 py-3.5 text-end">
                <div class="flex items-center justify-end gap-1">
                  <button
                    type="button"
                    :title="journal.is_active ? $t('journals.deactivate') : $t('journals.activate')"
                    :disabled="togglingId === journal.id"
                    class="inline-flex h-8 items-center rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2.5 text-[11px] font-bold text-[var(--text)] hover:border-[var(--crimson)]/40 disabled:opacity-50 cursor-pointer"
                    @click="toggleActive(journal)"
                  >
                    {{ journal.is_active ? $t('journals.deactivate') : $t('journals.activate') }}
                  </button>
                  <button
                    type="button"
                    :title="$t('common.edit')"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                    @click="openEdit(journal)"
                  >
                    <Pencil class="h-4 w-4" />
                  </button>
                  <button
                    type="button"
                    :title="$t('common.delete')"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)] cursor-pointer"
                    @click="handleDelete(journal)"
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
        :items-count="journals.length"
        :per-page="meta.per_page"
        :loading="loading"
        @update:per-page="meta.per_page = $event"
        @change-per-page="loadJournals(1)"
        @change-page="loadJournals"
      />
    </div>

    <!-- Add / Edit Modal -->
    <div
      v-if="modalOpen"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-xs p-4"
      @click.self="modalOpen = false"
    >
      <div class="w-full max-w-lg rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xl">
        <div class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4">
          <h2 class="text-lg font-extrabold text-[var(--text)]">
            {{ editingJournal ? $t('journals.editJournal') : $t('journals.addJournal') }}
          </h2>
          <button
            type="button"
            class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            @click="modalOpen = false"
          >
            <X class="h-5 w-5" />
          </button>
        </div>

        <form class="space-y-4 p-6" @submit.prevent="submitForm">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('journals.code') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.code"
                type="text"
                required
                maxlength="30"
                dir="ltr"
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-mono font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              />
              <p v-if="errors.code" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.code[0] }}</p>
            </div>

            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
                {{ $t('journals.type') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <select
                v-model="form.type"
                required
                :disabled="saving"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
              >
                <option v-for="tOpt in journalTypes" :key="tOpt" :value="tOpt">
                  {{ $t(`journals.types.${tOpt}`) }}
                </option>
              </select>
              <p v-if="errors.type" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.type[0] }}</p>
            </div>
          </div>

          <div>
            <label class="mb-1.5 block text-xs font-bold text-[var(--muted)]">
              {{ $t('journals.name') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <input
              v-model="form.name"
              type="text"
              required
              maxlength="100"
              :disabled="saving"
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3.5 text-sm font-semibold text-[var(--text)] outline-none transition focus:border-[var(--crimson)]"
            />
            <p v-if="errors.name" class="mt-1 text-xs font-semibold text-[var(--crimson)]">{{ errors.name[0] }}</p>
          </div>

          <label class="flex items-center gap-2 text-sm font-semibold text-[var(--text)] cursor-pointer">
            <input v-model="form.is_active" type="checkbox" :disabled="saving" class="h-4 w-4 accent-[var(--crimson)]" />
            <span>{{ $t('common.active') }}</span>
          </label>

          <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] pt-4">
            <button
              type="button"
              class="inline-flex h-10 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--muted)] hover:text-[var(--text)] cursor-pointer"
              :disabled="saving"
              @click="modalOpen = false"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="inline-flex h-10 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
              :disabled="saving"
            >
              <span v-if="saving" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
              <span>{{ $t('common.save') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, BookOpen, ChevronLeft, Pencil, Plus, Trash2, X } from 'lucide-vue-next';
import { createJournal, deleteJournal, fetchJournals, updateJournal } from '../../services/accountingApi';
import { confirmDelete, showError, showSuccess } from '../../services/alert';
import PageHeader from '../../components/ui/PageHeader.vue';
import FilterToolbar from '../../components/ui/FilterToolbar.vue';
import TablePagination from '../../components/ui/TablePagination.vue';

const { t } = useI18n();
const journalTypes = ['Sales', 'Purchase', 'Cash', 'Bank', 'Miscellaneous'];

const journals = ref([]);
const loading = ref(false);
const loadError = ref('');
const togglingId = ref(null);
const filters = reactive({
  search: '',
  type: '',
  is_active: '',
});
const meta = reactive({
  current_page: 1,
  last_page: 1,
  per_page: 10,
  total: 0,
});

const modalOpen = ref(false);
const saving = ref(false);
const errors = ref({});
const editingJournal = ref(null);
const form = reactive({
  code: '',
  name: '',
  type: 'Sales',
  is_active: true,
});

let searchTimer = null;

const activeFiltersCount = computed(() => {
  let count = 0;
  if (filters.type !== '') count++;
  if (filters.is_active !== '') count++;
  return count;
});

async function loadJournals(page = 1) {
  loading.value = true;
  loadError.value = '';
  try {
    const res = await fetchJournals({
      search: filters.search.trim() || undefined,
      type: filters.type || undefined,
      is_active: filters.is_active !== '' ? filters.is_active : undefined,
      page,
      per_page: meta.per_page,
    });
    journals.value = res.data?.data || [];
    const resMeta = res.data?.meta || {};
    meta.current_page = resMeta.current_page || 1;
    meta.last_page = resMeta.last_page || 1;
    meta.per_page = resMeta.per_page || 15;
    meta.total = resMeta.total || 0;
  } catch (err) {
    loadError.value = err.response?.data?.message || t('journals.loadError');
  } finally {
    loading.value = false;
  }
}

function debouncedLoad() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => loadJournals(1), 300);
}

function resetFilters() {
  filters.search = '';
  filters.type = '';
  filters.is_active = '';
  loadJournals(1);
}

function openCreate() {
  editingJournal.value = null;
  errors.value = {};
  form.code = '';
  form.name = '';
  form.type = 'Sales';
  form.is_active = true;
  modalOpen.value = true;
}

function openEdit(journal) {
  editingJournal.value = journal;
  errors.value = {};
  form.code = journal.code ?? '';
  form.name = journal.name ?? '';
  form.type = journal.type ?? 'Sales';
  form.is_active = Boolean(journal.is_active);
  modalOpen.value = true;
}

async function toggleActive(journal) {
  togglingId.value = journal.id;
  try {
    await updateJournal(journal.id, { is_active: !journal.is_active });
    showSuccess({ title: t('common.successTitle'), text: t('journals.updatedSuccess') });
    await loadJournals(meta.current_page);
  } catch (err) {
    showError({ title: t('common.error'), text: err.response?.data?.message || t('common.errorTitle') });
  } finally {
    togglingId.value = null;
  }
}

async function handleDelete(journal) {
  const confirmed = await confirmDelete({
    title: t('journals.confirmDeleteTitle'),
    text: t('journals.confirmDeleteText', { name: journal.name, code: journal.code }),
  });

  if (!confirmed) return;

  try {
    await deleteJournal(journal.id);
    showSuccess({ title: t('common.successTitle'), text: t('journals.deletedSuccess') });
    await loadJournals(meta.current_page);
  } catch (err) {
    const firstErr = Object.values(err.response?.data?.errors || {})[0]?.[0];
    showError({ title: t('common.error'), text: firstErr || err.response?.data?.message || t('common.errorTitle') });
  }
}

async function submitForm() {
  if (saving.value) return;
  saving.value = true;
  errors.value = {};

  const payload = {
    code: form.code,
    name: form.name,
    type: form.type,
    is_active: Boolean(form.is_active),
  };

  try {
    if (editingJournal.value) {
      await updateJournal(editingJournal.value.id, payload);
      showSuccess({ title: t('common.successTitle'), text: t('journals.updatedSuccess') });
    } else {
      await createJournal(payload);
      showSuccess({ title: t('common.successTitle'), text: t('journals.createdSuccess') });
    }
    modalOpen.value = false;
    await loadJournals(meta.current_page);
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
    }
    const firstMsg = Object.values(errors.value)[0]?.[0] || err.response?.data?.message || t('common.errorTitle');
    showError({ title: t('common.error'), text: firstMsg });
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadJournals();
});
</script>
