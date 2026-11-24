<template>
  <div>
    <!-- Loading Skeleton -->
    <div v-if="loading" class="divide-y divide-[var(--border)]" aria-busy="true">
      <div v-for="n in 6" :key="n" class="flex items-center justify-between gap-4 px-5 py-4">
        <div class="flex-1 space-y-2">
          <div class="h-4 w-56 animate-pulse rounded bg-[var(--bg)]"></div>
          <div class="h-3 w-80 max-w-full animate-pulse rounded bg-[var(--bg)]"></div>
        </div>
        <div class="h-4 w-28 animate-pulse rounded bg-[var(--bg)]"></div>
      </div>
    </div>

    <!-- Desktop Table -->
    <div v-else class="hidden overflow-x-auto md:block">
      <table class="w-full border-collapse text-start">
        <thead>
          <tr class="border-b border-[var(--border)] bg-[var(--bg)]/70 text-xs font-extrabold text-[var(--muted)]">
            <th class="px-5 py-3.5 text-start">{{ $t('activities.colActivity') }}</th>
            <th class="px-5 py-3.5 text-start">{{ $t('activities.colChanges') }}</th>
            <th class="px-5 py-3.5 text-start">{{ $t('activities.colBy') }}</th>
            <th class="px-5 py-3.5 text-start">{{ $t('activities.colCreatedAt') }}</th>
            <th v-if="canDelete" class="px-5 py-3.5 text-end">{{ $t('activities.colActions') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-[var(--border)] text-sm">
          <tr
            v-for="item in items"
            :key="item.id"
            class="transition-colors hover:bg-[var(--bg)]/50"
          >
            <!-- Activity & Subject Link -->
            <td class="px-5 py-3.5 align-top">
              <div class="flex flex-wrap items-center gap-2">
                <span
                  class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs font-bold"
                  :class="eventBadgeClass(item.event_key)"
                >
                  <component :is="eventIcon(item.event_key)" class="h-3.5 w-3.5" aria-hidden="true" />
                  <span>{{ item.event }}</span>
                </span>

                <span class="inline-flex items-center gap-1.5 text-xs text-[var(--muted)]">
                  <component :is="moduleIcon(item.icon)" class="h-4 w-4 text-[var(--crimson)]" aria-hidden="true" />
                </span>

                <router-link
                  v-if="item.link"
                  :to="item.link"
                  class="font-bold text-[var(--text)] transition-colors hover:text-[var(--crimson)]"
                >
                  {{ item.module_label }}
                  <span v-if="item.subject_name" class="font-semibold text-[var(--muted)]">
                    ({{ item.subject_name }})
                  </span>
                </router-link>
                <span v-else class="font-bold text-[var(--text)]">
                  {{ item.module_label }}
                  <span v-if="item.subject_name" class="font-semibold text-[var(--muted)]">
                    ({{ item.subject_name }})
                  </span>
                </span>
              </div>
            </td>

            <!-- Row Changes Diff -->
            <td class="px-5 py-3.5 align-top">
              <div v-if="item.row_changes?.length" class="flex flex-col items-start gap-1.5">
                <div
                  v-for="change in item.row_changes"
                  :key="`${item.id}-${change.key}`"
                  class="inline-flex max-w-full flex-wrap items-center gap-1.5 rounded-lg border border-sky-500/20 bg-sky-500/10 px-2.5 py-1 text-xs text-[var(--text)]"
                >
                  <ArrowLeftRight class="h-3 w-3 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden="true" />
                  <strong class="font-extrabold">{{ change.label }}:</strong>
                  <span>{{ formatChangeSummary(item.id, change) }}</span>
                  <button
                    v-if="isLongChange(change)"
                    type="button"
                    class="text-[11px] font-bold text-[var(--crimson)] underline underline-offset-2 cursor-pointer"
                    @click="toggleExpand(item.id, change.key)"
                  >
                    {{ isExpanded(item.id, change.key) ? $t('activities.showLess') : $t('activities.showMore') }}
                  </button>
                </div>
              </div>
              <span v-else class="text-xs text-[var(--muted)]">—</span>
            </td>

            <!-- Causer -->
            <td class="px-5 py-3.5 align-top">
              <div v-if="item.causer" class="flex items-center gap-2">
                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-xs font-extrabold text-[var(--crimson)]">
                  {{ item.causer.initial }}
                </span>
                <span class="font-semibold text-[var(--text)]">{{ item.causer.name }}</span>
              </div>
              <span
                v-else
                class="inline-flex items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2 py-0.5 text-xs font-semibold text-[var(--muted)]"
              >
                <Bot class="h-3.5 w-3.5" aria-hidden="true" />
                {{ $t('activities.system') }}
              </span>
            </td>

            <!-- Created At -->
            <td class="whitespace-nowrap px-5 py-3.5 align-top text-xs font-medium text-[var(--muted)]">
              {{ formatDateTime(item.created_at, locale) }}
            </td>

            <!-- Delete Action -->
            <td v-if="canDelete" class="whitespace-nowrap px-5 py-3.5 align-top text-end">
              <button
                type="button"
                :disabled="deletingId === item.id"
                class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-rose-500/10 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-45 dark:hover:text-rose-400 cursor-pointer"
                :title="$t('activities.deleteOne')"
                :aria-label="$t('activities.deleteOne')"
                @click="$emit('delete', item)"
              >
                <Trash2 class="h-4 w-4" aria-hidden="true" />
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Mobile Card List -->
    <div v-if="!loading" class="divide-y divide-[var(--border)] md:hidden">
      <div v-for="item in items" :key="`mobile-${item.id}`" class="space-y-2.5 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span
            class="inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-xs font-bold"
            :class="eventBadgeClass(item.event_key)"
          >
            <component :is="eventIcon(item.event_key)" class="h-3.5 w-3.5" aria-hidden="true" />
            <span>{{ item.event }}</span>
          </span>
          <span class="text-xs text-[var(--muted)]">{{ formatDateTime(item.created_at, locale) }}</span>
        </div>

        <div class="flex items-center gap-2 text-sm">
          <component :is="moduleIcon(item.icon)" class="h-4 w-4 shrink-0 text-[var(--crimson)]" aria-hidden="true" />
          <router-link
            v-if="item.link"
            :to="item.link"
            class="font-bold text-[var(--text)] hover:text-[var(--crimson)]"
          >
            {{ item.module_label }}
            <span v-if="item.subject_name" class="font-semibold text-[var(--muted)]">
              ({{ item.subject_name }})
            </span>
          </router-link>
          <span v-else class="font-bold text-[var(--text)]">
            {{ item.module_label }}
            <span v-if="item.subject_name" class="font-semibold text-[var(--muted)]">
              ({{ item.subject_name }})
            </span>
          </span>
        </div>

        <div v-if="item.row_changes?.length" class="space-y-1">
          <div
            v-for="change in item.row_changes"
            :key="`m-${item.id}-${change.key}`"
            class="rounded-lg border border-sky-500/20 bg-sky-500/10 px-2.5 py-1 text-xs text-[var(--text)]"
          >
            <strong>{{ change.label }}:</strong>
            {{ formatChangeSummary(item.id, change) }}
          </div>
        </div>

        <div class="flex items-center justify-between gap-2 pt-1 text-xs text-[var(--muted)]">
          <div class="flex items-center gap-2">
            <template v-if="item.causer">
              <span class="flex h-6 w-6 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[11px] font-bold text-[var(--crimson)]">
                {{ item.causer.initial }}
              </span>
              <span class="font-semibold text-[var(--text)]">{{ item.causer.name }}</span>
            </template>
            <span v-else class="inline-flex items-center gap-1">
              <Bot class="h-3.5 w-3.5" aria-hidden="true" />
              {{ $t('activities.system') }}
            </span>
          </div>

          <button
            v-if="canDelete"
            type="button"
            :disabled="deletingId === item.id"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-rose-500/10 hover:text-rose-600 disabled:cursor-not-allowed disabled:opacity-45 dark:hover:text-rose-400 cursor-pointer"
            :title="$t('activities.deleteOne')"
            :aria-label="$t('activities.deleteOne')"
            @click="$emit('delete', item)"
          >
            <Trash2 class="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Activity,
  ArrowLeftRight,
  BookMarked,
  BookOpen,
  Bot,
  Boxes,
  CalendarDays,
  CalendarRange,
  ClipboardCheck,
  ClipboardList,
  Clock,
  Contact,
  CreditCard,
  FileSpreadsheet,
  FolderTree,
  Landmark,
  MapPin,
  Pencil,
  PlusCircle,
  Receipt,
  RotateCcw,
  Settings,
  ShieldCheck,
  Tags,
  Trash2,
  Users,
  Wallet,
  Warehouse,
} from 'lucide-vue-next';
import { formatDateTime } from '../../utils/date';

defineProps({
  items: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  canDelete: {
    type: Boolean,
    default: false,
  },
  deletingId: {
    type: [Number, String],
    default: null,
  },
});

defineEmits(['delete']);

const { locale, t } = useI18n();
const expandedKeys = ref(new Set());

const ICON_MAP = {
  users: Users,
  'shield-check': ShieldCheck,
  settings: Settings,
  'book-open': BookOpen,
  'folder-tree': FolderTree,
  contact: Contact,
  'map-pin': MapPin,
  'clipboard-list': ClipboardList,
  'file-spreadsheet': FileSpreadsheet,
  warehouse: Warehouse,
  boxes: Boxes,
  'clipboard-check': ClipboardCheck,
  clock: Clock,
  'arrow-left-right': ArrowLeftRight,
  receipt: Receipt,
  'rotate-ccw': RotateCcw,
  tags: Tags,
  wallet: Wallet,
  landmark: Landmark,
  'book-marked': BookMarked,
  'calendar-range': CalendarRange,
  'calendar-days': CalendarDays,
  'credit-card': CreditCard,
};

function moduleIcon(key) {
  return ICON_MAP[key] || Activity;
}

function eventIcon(eventKey) {
  if (eventKey === 'created') return PlusCircle;
  if (eventKey === 'updated') return Pencil;
  if (eventKey === 'deleted') return Trash2;
  return Activity;
}

function eventBadgeClass(eventKey) {
  if (eventKey === 'created') {
    return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300';
  }
  if (eventKey === 'updated') {
    return 'bg-sky-500/15 text-sky-700 dark:text-sky-300';
  }
  if (eventKey === 'deleted') {
    return 'bg-rose-500/15 text-rose-700 dark:text-rose-300';
  }
  return 'bg-[var(--bg)] text-[var(--muted)]';
}

function buildDiffText(change) {
  return `${t('activities.fromLabel')} ( ${change.old} ) ${t('activities.toLabel')} ( ${change.new} )`;
}

function isLongChange(change) {
  return buildDiffText(change).trim().split(/\s+/).length > 14;
}

function isExpanded(activityId, key) {
  return expandedKeys.value.has(`${activityId}:${key}`);
}

function toggleExpand(activityId, key) {
  const composite = `${activityId}:${key}`;
  const next = new Set(expandedKeys.value);
  if (next.has(composite)) {
    next.delete(composite);
  } else {
    next.add(composite);
  }
  expandedKeys.value = next;
}

function formatChangeSummary(activityId, change) {
  const full = buildDiffText(change);
  if (isExpanded(activityId, change.key)) {
    return full;
  }
  const words = full.trim().split(/\s+/);
  if (words.length <= 14) {
    return full;
  }
  return `${words.slice(0, 14).join(' ')}…`;
}
</script>
