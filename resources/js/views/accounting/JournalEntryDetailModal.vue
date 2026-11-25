<template>
  <AppDrawer
    :open="show"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-5xl"
    side="end"
    @close="emit('close')"
  >
    <!-- Drawer Header -->
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs">
            <BookOpenCheck class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="font-mono text-lg font-black tracking-tight text-[var(--text)] sm:text-xl">
                {{ entry?.entry_number || '—' }}
              </h2>

              <span
                v-if="entry?.status"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="entry.status === 'posted'
                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                  : 'bg-amber-500/15 text-amber-700 dark:text-amber-400'"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ $t(`journalEntries.statuses.${entry.status}`) }}
              </span>

              <span
                v-if="entry"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                :class="entry.is_balanced
                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                  : 'bg-rose-500/15 text-rose-700 dark:text-rose-400'"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ entry.is_balanced ? $t('journalEntries.balanced') : $t('journalEntries.unbalanced') }}
              </span>
            </div>

            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span>{{ $t('journalEntries.journal') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ entry?.journal?.name || '—' }}</span>
              <span>•</span>
              <span class="font-mono">{{ formatDate(entry?.entry_date) }}</span>
            </div>
          </div>
        </div>

        <button
          type="button"
          class="print:hidden inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-[var(--muted)] transition hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
          :aria-label="$t('common.close')"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>
    </template>

    <!-- Sticky Top Actions Bar -->
    <template v-if="entry" #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
          <template v-if="entry.status === 'draft' && entry.source_type !== 'purchase_invoice'">
            <button
              type="button"
              :disabled="processing"
              class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50 cursor-pointer"
              @click="handlePost"
            >
              <CheckCircle2 class="h-4 w-4" aria-hidden="true" />
              <span>{{ $t('journalEntries.postEntry') }}</span>
            </button>

            <button
              type="button"
              :disabled="processing"
              class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--bg)] disabled:opacity-50 cursor-pointer"
              @click="emit('edit', entry)"
            >
              <Pencil class="h-4 w-4" aria-hidden="true" />
              <span>{{ $t('common.edit') }}</span>
            </button>

            <button
              type="button"
              :disabled="processing"
              class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-500/10 px-3.5 text-xs font-bold text-rose-600 transition hover:bg-rose-500/20 dark:text-rose-400 disabled:opacity-50 cursor-pointer"
              @click="handleDelete"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
              <span>{{ $t('common.delete') }}</span>
            </button>
          </template>
          <router-link
            v-if="entry.source_type === 'purchase_invoice' && entry.source_id"
            :to="{ name: 'purchase-invoices', query: { open: String(entry.source_id) } }"
            class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--bg)] hover:text-[var(--crimson)]"
            @click="emit('close')"
          >
            <span>{{ $t('journalEntries.openPurchaseInvoice') }}</span>
          </router-link>
        </div>

        <button
          type="button"
          class="inline-flex h-9 items-center justify-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--bg)] cursor-pointer"
          @click="handlePrint"
        >
          <Printer class="h-4 w-4" aria-hidden="true" />
          <span>{{ $t('common.print') }}</span>
        </button>
      </div>
    </template>

    <!-- Drawer Body -->
    <div class="space-y-5">
      <!-- Loading -->
      <div v-if="loading" class="flex items-center justify-center py-20">
        <div class="inline-flex items-center gap-2 text-sm text-[var(--muted)]">
          <span class="h-4 w-4 animate-spin rounded-full border-2 border-[var(--crimson)] border-t-transparent"></span>
          <span>{{ $t('common.loading') }}</span>
        </div>
      </div>

      <!-- Error -->
      <div v-else-if="loadError" class="flex flex-col items-center justify-center gap-3 py-14 text-center" role="alert">
        <AlertCircle class="h-8 w-8 text-[var(--crimson)]" />
        <p class="text-sm font-bold text-[var(--text)]">{{ loadError }}</p>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-xl bg-[var(--crimson)] px-4 text-xs font-bold text-white hover:bg-[var(--crimson-hover)] cursor-pointer"
          @click="loadEntry"
        >
          {{ $t('common.retry') }}
        </button>
      </div>

      <template v-else-if="entry">
        <!-- Header Metadata -->
        <div class="grid grid-cols-1 gap-4 rounded-2xl border border-[var(--border)] bg-[var(--bg)]/50 p-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.fiscalYear') }}</span>
            <span class="mt-1 block text-sm font-bold text-[var(--text)]">{{ entry.fiscal_year?.name || '—' }}</span>
          </div>
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.accountingPeriod') }}</span>
            <span class="mt-1 block text-sm font-bold text-[var(--text)]">{{ entry.accounting_period?.name || '—' }}</span>
          </div>
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.reference') }}</span>
            <span class="mt-1 block text-sm font-semibold text-[var(--text)]">{{ entry.reference || '—' }}</span>
          </div>
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.entryDate') }}</span>
            <span class="mt-1 block font-mono text-sm font-bold text-[var(--text)]">{{ formatDate(entry.entry_date) }}</span>
          </div>
          <div class="sm:col-span-2 lg:col-span-4">
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.description') }}</span>
            <span class="mt-1 block text-sm font-semibold text-[var(--text)]">{{ entry.description || '—' }}</span>
          </div>
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.createdBy') }}</span>
            <span class="mt-1 block text-sm font-semibold text-[var(--text)]">{{ entry.created_by?.name || '—' }}</span>
          </div>
          <div>
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.createdAt') }}</span>
            <span class="mt-1 block font-mono text-xs font-semibold text-[var(--text)]">{{ formatDate(entry.created_at) }}</span>
          </div>
          <div v-if="entry.posted_by">
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.postedBy') }}</span>
            <span class="mt-1 block text-sm font-semibold text-[var(--text)]">{{ entry.posted_by?.name || '—' }}</span>
          </div>
          <div v-if="entry.posted_at">
            <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('journalEntries.postedAt') }}</span>
            <span class="mt-1 block font-mono text-xs font-semibold text-[var(--text)]">{{ formatDate(entry.posted_at) }}</span>
          </div>
        </div>

        <!-- Lines Table -->
        <div class="overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)]">
          <div class="border-b border-[var(--border)] px-4 py-3">
            <h3 class="text-sm font-extrabold text-[var(--text)]">{{ $t('journalEntries.linesTitle') }}</h3>
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-start text-sm">
              <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
                <tr>
                  <th class="w-12 px-4 py-3 text-center">{{ $t('journalEntries.lineNumber') }}</th>
                  <th class="px-4 py-3 text-start">{{ $t('journalEntries.accountCode') }}</th>
                  <th class="px-4 py-3 text-start">{{ $t('journalEntries.accountName') }}</th>
                  <th class="px-4 py-3 text-start">{{ $t('journalEntries.description') }}</th>
                  <th class="px-4 py-3 text-end">{{ $t('journalEntries.debit') }}</th>
                  <th class="px-4 py-3 text-end">{{ $t('journalEntries.credit') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)]">
                <tr v-for="line in entry.lines || []" :key="line.id" class="hover:bg-[var(--bg)]/40">
                  <td class="px-4 py-3 text-center font-mono text-xs font-bold text-[var(--muted)]">
                    {{ line.line_number }}
                  </td>
                  <td class="px-4 py-3 font-mono text-xs font-extrabold text-[var(--text)]">
                    {{ line.account?.code || '—' }}
                  </td>
                  <td class="px-4 py-3 font-bold text-[var(--text)]">
                    {{ line.account?.name || '—' }}
                  </td>
                  <td class="px-4 py-3 text-[var(--muted)]">
                    {{ line.label || '—' }}
                  </td>
                  <td class="px-4 py-3 text-end font-mono text-sm font-extrabold text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(line.debit) }}
                    <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                  </td>
                  <td class="px-4 py-3 text-end font-mono text-sm font-extrabold text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(line.credit) }}
                    <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                  </td>
                </tr>
              </tbody>
              <tfoot class="border-t-2 border-[var(--border)] bg-[var(--bg)]/70">
                <tr>
                  <td colspan="4" class="px-4 py-3"></td>
                  <td class="px-4 py-3 text-end font-mono text-base font-black text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(entry.total_debit) }}
                    <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                  </td>
                  <td class="px-4 py-3 text-end font-mono text-base font-black text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(entry.total_credit) }}
                    <span class="text-xs font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </template>
    </div>
  </AppDrawer>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertCircle, BookOpenCheck, CheckCircle2, Pencil, Printer, Trash2, X } from 'lucide-vue-next';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import { deleteJournalEntry, fetchJournalEntry, postJournalEntry } from '../../services/accountingApi';
import { confirmAction, confirmDelete } from '../../services/alert';
import { useNotificationStore } from '../../stores/notification';
import { formatDate } from '../../utils/date';
import { formatMoney } from '../../utils/format';
import { extractApiError } from '../../utils/apiError';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  entryId: {
    type: [Number, String, null],
    default: null,
  },
});

const emit = defineEmits(['close', 'edit', 'posted', 'deleted']);

const { t } = useI18n();
const notify = useNotificationStore();

const entry = ref(null);
const loading = ref(false);
const loadError = ref('');
const processing = ref(false);

async function loadEntry() {
  if (!props.entryId) return;
  loading.value = true;
  loadError.value = '';
  try {
    const res = await fetchJournalEntry(props.entryId);
    entry.value = res.data?.data || null;
  } catch (err) {
    loadError.value = extractApiError(err, t('journalEntries.loadOneError'));
  } finally {
    loading.value = false;
  }
}

function handlePrint() {
  window.print();
}

async function handlePost() {
  if (!entry.value || processing.value) return;

  const confirmed = await confirmAction({
    title: t('journalEntries.postConfirmTitle'),
    text: t('journalEntries.postConfirmText'),
    icon: 'question',
    confirmButtonText: t('journalEntries.postConfirmBtn'),
  });
  if (!confirmed) return;

  processing.value = true;
  try {
    const res = await postJournalEntry(entry.value.id);
    entry.value = res.data?.data || entry.value;
    notify.success(t('journalEntries.postedSuccess'));
    await loadEntry();
    emit('posted', entry.value);
  } catch (err) {
    notify.error(extractApiError(err));
  } finally {
    processing.value = false;
  }
}

async function handleDelete() {
  if (!entry.value || processing.value) return;

  const confirmed = await confirmDelete({
    title: t('journalEntries.deleteConfirmTitle'),
    text: t('journalEntries.deleteConfirmText'),
    confirmButtonText: t('journalEntries.deleteConfirmBtn'),
  });
  if (!confirmed) return;

  processing.value = true;
  try {
    const deletedId = entry.value.id;
    await deleteJournalEntry(deletedId);
    notify.success(t('journalEntries.deletedSuccess'));
    emit('deleted', deletedId);
    emit('close');
  } catch (err) {
    notify.error(extractApiError(err));
  } finally {
    processing.value = false;
  }
}

watch(
  () => [props.show, props.entryId],
  ([visible, id]) => {
    if (visible && id) {
      loadEntry();
    } else if (!visible) {
      entry.value = null;
      loadError.value = '';
    }
  },
  { immediate: true }
);
</script>
