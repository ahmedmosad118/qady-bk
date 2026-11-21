<template>
  <AppDrawer
    :open="show"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl"
    side="end"
    @close="emit('close')"
  >
    <!-- Drawer Header -->
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
          >
            <ReceiptText class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono">
                {{ expense?.expense_number || '—' }}
              </h2>

              <span
                v-if="expense?.status"
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="expense.status === 'posted'
                  ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                  : 'bg-amber-500/15 text-amber-700 dark:text-amber-400'"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ $t(`expenses.statuses.${expense.status}`) }}
              </span>
            </div>

            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span>{{ $t('expenses.category') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ expense?.category?.name || '—' }}</span>
              <span>•</span>
              <span class="font-mono">{{ formatDateOnly(expense?.expense_date) }}</span>
            </div>
          </div>
        </div>

        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
          :title="$t('common.close')"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>
    </template>

    <!-- Top Sticky Action Bar (All actions at the top) -->
    <template #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span class="text-xs font-bold text-[var(--muted)]">
            {{ $t('common.actions') }}:
          </span>

          <span
            v-if="expense?.status === 'posted'"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
          >
            <Lock class="h-3.5 w-3.5" />
            <span>{{ $t('expenses.postedLockedInfo') }}</span>
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-if="expense?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)] cursor-pointer"
            @click="emit('edit', expense)"
          >
            <Pencil class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('common.edit') }}</span>
          </button>

          <button
            v-if="expense?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-50 px-3.5 py-1.5 text-xs font-bold text-rose-700 transition-colors hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400 cursor-pointer"
            @click="emit('delete', expense)"
          >
            <Trash2 class="h-3.5 w-3.5" />
            <span>{{ $t('common.delete') }}</span>
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Content -->
    <div v-if="expense" class="space-y-6 text-sm text-[var(--text)]">
      <!-- Summary Cards -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <!-- Category & Contact Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Tags class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('expenses.category') }}</span>
          </div>
          <p class="mt-2 text-base font-extrabold text-[var(--text)] truncate">
            {{ expense.category?.name || '—' }}
          </p>
          <p v-if="expense.contact" class="mt-1 text-xs text-[var(--muted)] truncate">
            {{ $t('expenses.contact') }}:
            <span class="font-bold text-[var(--text)]">{{ expense.contact.name }}</span>
          </p>
        </div>

        <!-- Dates Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Calendar class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('expenses.expenseDate') }}</span>
          </div>
          <div class="mt-2 space-y-1">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('expenses.expenseDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(expense.expense_date) }}</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('expenses.createdAt') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDate(expense.created_at) }}</span>
            </div>
          </div>
        </div>

        <!-- Creator Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <UserIcon class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('expenses.createdBy') }}</span>
          </div>
          <p class="mt-2 text-base font-extrabold text-[var(--text)] truncate">
            {{ expense.creator?.name || '—' }}
          </p>
          <p class="mt-1 text-xs text-[var(--muted)]">
            {{ $t('expenses.itemsCount') }}:
            <span class="font-bold font-mono text-[var(--text)]">{{ expense.items?.length || 0 }}</span>
          </p>
        </div>
      </div>

      <!-- Items Table (Above Totals) -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-extrabold text-[var(--text)] flex items-center gap-2">
            <span>{{ $t('expenses.items') }}</span>
            <span class="rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">
              {{ expense.items?.length || 0 }}
            </span>
          </h3>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 py-3 px-4 text-center">#</th>
                <th class="py-3 px-4 text-start">{{ $t('expenses.itemName') }}</th>
                <th class="py-3 px-4 text-start">{{ $t('expenses.itemAttachments') }}</th>
                <th class="py-3 px-4 text-end">{{ $t('expenses.amount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr
                v-for="(item, idx) in (expense.items || [])"
                :key="item.id || idx"
                class="transition-colors hover:bg-[var(--bg)]/50 align-top"
              >
                <td class="py-3.5 px-4 text-center text-[var(--muted)] font-mono">{{ idx + 1 }}</td>
                <td class="py-3.5 px-4">
                  <div class="font-bold text-[var(--text)] text-sm">{{ item.name }}</div>
                  <p v-if="item.description" class="mt-1 text-xs text-[var(--muted)] whitespace-pre-line">
                    {{ item.description }}
                  </p>
                </td>
                <td class="py-3.5 px-4">
                  <div v-if="(item.attachments || []).length > 0" class="flex flex-wrap gap-2">
                    <div
                      v-for="att in item.attachments"
                      :key="att.id"
                      class="inline-flex items-center gap-2 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1.5 text-xs"
                    >
                      <FileText v-if="isPdf(att.mime_type)" class="h-3.5 w-3.5 text-[var(--crimson)] shrink-0" />
                      <ImageIcon v-else class="h-3.5 w-3.5 text-amber-500 shrink-0" />
                      <button
                        type="button"
                        class="max-w-[150px] truncate font-semibold text-[var(--text)] hover:text-[var(--crimson)] cursor-pointer"
                        :title="att.original_name"
                        @click="emit('preview-attachment', { expenseId: expense.id, itemId: item.id, attachment: att })"
                      >
                        {{ att.original_name }}
                      </button>
                      <span class="text-[10px] text-[var(--muted)]">({{ formatFileSize(att.size) }})</span>
                      <button
                        type="button"
                        class="rounded p-0.5 text-[var(--muted)] hover:text-[var(--crimson)] cursor-pointer"
                        :title="$t('expenses.viewAttachment')"
                        @click="emit('preview-attachment', { expenseId: expense.id, itemId: item.id, attachment: att })"
                      >
                        <Eye class="h-3.5 w-3.5" />
                      </button>
                      <button
                        type="button"
                        class="rounded p-0.5 text-[var(--muted)] hover:text-[var(--text)] cursor-pointer"
                        :title="$t('expenses.downloadAttachment')"
                        @click="downloadAttachment(item.id, att)"
                      >
                        <Download class="h-3.5 w-3.5" />
                      </button>
                    </div>
                  </div>
                  <span v-else class="text-[var(--muted)]">—</span>
                </td>
                <td class="py-3.5 px-4 text-end font-bold font-mono text-sm text-[var(--text)] whitespace-nowrap">
                  {{ formatAmount(item.amount) }} {{ $t('common.currency') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Notes Banner -->
      <div
        v-if="expense.notes"
        class="rounded-2xl border border-[var(--border)] bg-[var(--bg)]/60 p-4 text-xs leading-relaxed text-[var(--text)]"
      >
        <div class="flex items-center gap-1.5 font-bold text-[var(--muted)] mb-1">
          <FileText class="h-4 w-4" />
          <span>{{ $t('expenses.notes') }}</span>
        </div>
        <p class="whitespace-pre-line text-xs font-medium">{{ expense.notes }}</p>
      </div>

      <!-- Financial Totals Summary (At the bottom) -->
      <div class="flex justify-end">
        <div class="w-full sm:w-96 space-y-2 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center justify-between text-xs">
            <span class="font-bold text-[var(--muted)]">{{ $t('expenses.subtotal') }}:</span>
            <span class="font-bold font-mono text-[var(--text)]">
              {{ formatAmount(expense.subtotal) }} {{ $t('common.currency') }}
            </span>
          </div>

          <div class="border-t border-[var(--border)] my-1"></div>

          <div class="flex items-baseline justify-between pt-1">
            <span class="text-base font-extrabold text-[var(--text)]">{{ $t('expenses.total') }}</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl font-black font-mono text-[var(--text)]">{{ formatAmount(expense.total) }}</span>
              <span class="text-xs font-bold text-[var(--muted)]">{{ $t('common.currency') }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AppDrawer>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import {
  Calendar,
  Download,
  Eye,
  FileText,
  Image as ImageIcon,
  Lock,
  Pencil,
  ReceiptText,
  Tags,
  Trash2,
  User as UserIcon,
  X,
} from 'lucide-vue-next';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import api from '../../services/api';
import { showError } from '../../services/alert';
import { formatDate, formatDateOnly } from '../../utils/date';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  expense: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'edit', 'delete', 'preview-attachment']);

const { t } = useI18n();

function formatAmount(value) {
  const num = Number(value ?? 0);
  return Number.isFinite(num) ? num.toFixed(2) : '0.00';
}

function isPdf(mimeType) {
  return String(mimeType || '').toLowerCase().includes('pdf');
}

function formatFileSize(bytes) {
  if (!bytes) return '0 B';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

async function downloadAttachment(itemId, attachment) {
  try {
    const res = await api.get(
      `/expenses/${props.expense.id}/items/${itemId}/attachments/${attachment.id}/download`,
      { responseType: 'blob' }
    );
    const url = URL.createObjectURL(res.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = attachment.original_name || 'attachment';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  }
}
</script>
