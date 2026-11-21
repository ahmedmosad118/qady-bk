<template>
  <div>
    <div
      class="group flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] px-4 py-3 transition-colors hover:bg-[var(--bg)]/60"
      :style="{ paddingInlineStart: `${16 + depth * 22}px` }"
    >
      <!-- Left / Start: Expand toggle, Icon, Code, Name -->
      <div class="flex min-w-0 flex-1 items-center gap-2.5">
        <button
          v-if="hasChildren"
          type="button"
          class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] transition-colors hover:border-[var(--crimson)]/40 hover:text-[var(--text)] cursor-pointer"
          :aria-expanded="isExpanded"
          :aria-label="isExpanded ? $t('chartOfAccounts.collapse') : $t('chartOfAccounts.expand')"
          @click="$emit('toggle', node.id)"
        >
          <ChevronDown
            class="h-4 w-4 transition-transform duration-150"
            :class="isExpanded ? '' : 'rtl:rotate-90 -rotate-90'"
          />
        </button>
        <span
          v-else
          class="inline-flex h-7 w-7 shrink-0 items-center justify-center text-[var(--muted)]/40"
          aria-hidden="true"
        >
          <CornerDownLeft class="h-3.5 w-3.5 rtl:rotate-180" />
        </span>

        <span
          class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl"
          :class="node.is_group
            ? 'bg-[var(--crimson-soft)] text-[var(--crimson)]'
            : 'bg-[var(--bg)] text-[var(--muted)]'"
          aria-hidden="true"
        >
          <FolderTree v-if="node.is_group" class="h-4 w-4" />
          <FileText v-else class="h-4 w-4" />
        </span>

        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span
              class="inline-flex items-center rounded-md border border-[var(--border)] bg-[var(--bg)] px-2 py-0.5 font-mono text-xs font-bold text-[var(--text)]"
            >
              {{ node.code }}
            </span>
            <span
              class="truncate text-sm font-bold"
              :class="node.matches_filter === false ? 'text-[var(--muted)]' : 'text-[var(--text)]'"
            >
              {{ node.name }}
            </span>
          </div>
          <p v-if="node.description" class="mt-0.5 truncate text-xs text-[var(--muted)]">
            {{ node.description }}
          </p>
        </div>
      </div>

      <!-- Right / End: Badges & Actions -->
      <div class="flex flex-wrap items-center gap-2">
        <!-- Type Badge -->
        <span
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold"
          :class="typeBadgeClass"
        >
          {{ node.account_type_label }}
        </span>

        <!-- Group vs Detail Badge -->
        <span
          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold"
          :class="node.is_group
            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
            : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
        >
          {{ node.is_group ? $t('chartOfAccounts.groupAccount') : $t('chartOfAccounts.detailAccount') }}
        </span>

        <!-- Linked Flags -->
        <span
          v-if="node.is_linked_to_settings"
          class="inline-flex items-center gap-1 rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:text-sky-300"
          :title="$t('chartOfAccounts.linkedToSettings')"
        >
          <Settings2 class="h-3 w-3" />
          <span>{{ $t('chartOfAccounts.settingBadge') }}</span>
        </span>

        <span
          v-if="node.is_linked_to_contact"
          class="inline-flex items-center gap-1 rounded-full bg-violet-500/10 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:text-violet-300"
          :title="$t('chartOfAccounts.linkedToContact')"
        >
          <UserCheck class="h-3 w-3" />
          <span>{{ $t('chartOfAccounts.contactBadge') }}</span>
        </span>

        <!-- Row Action Buttons -->
        <div class="flex items-center gap-1 ms-1">
          <button
            type="button"
            :disabled="node.is_linked_to_contact"
            :title="node.is_linked_to_contact
              ? $t('chartOfAccounts.cannotAddChildToContact')
              : $t('chartOfAccounts.addChild')"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition-colors"
            :class="node.is_linked_to_contact
              ? 'cursor-not-allowed text-[var(--muted)]/35'
              : 'cursor-pointer text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]'"
            @click="$emit('add-child', node)"
          >
            <Plus class="h-4 w-4" />
          </button>

          <button
            type="button"
            :title="$t('common.edit')"
            class="inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            @click="$emit('edit', node)"
          >
            <Pencil class="h-4 w-4" />
          </button>

          <button
            type="button"
            :disabled="!node.can_delete"
            :title="deleteDisabledReason || $t('common.delete')"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg transition-colors"
            :class="!node.can_delete
              ? 'cursor-not-allowed text-[var(--muted)]/35'
              : 'cursor-pointer text-[var(--muted)] hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]'"
            @click="$emit('delete', node)"
          >
            <Trash2 class="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>

    <!-- Recursive Children -->
    <div v-if="hasChildren && isExpanded">
      <ChartOfAccountTreeNode
        v-for="child in node.children"
        :key="child.id"
        :node="child"
        :depth="depth + 1"
        :expanded-ids="expandedIds"
        :force-expand="forceExpand"
        @toggle="$emit('toggle', $event)"
        @add-child="$emit('add-child', $event)"
        @edit="$emit('edit', $event)"
        @delete="$emit('delete', $event)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ChevronDown,
  CornerDownLeft,
  FileText,
  FolderTree,
  Pencil,
  Plus,
  Settings2,
  Trash2,
  UserCheck,
} from 'lucide-vue-next';

const props = defineProps({
  node: {
    type: Object,
    required: true,
  },
  depth: {
    type: Number,
    default: 0,
  },
  expandedIds: {
    type: Object,
    required: true,
  },
  forceExpand: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['toggle', 'add-child', 'edit', 'delete']);

const { t } = useI18n();

const hasChildren = computed(() => Array.isArray(props.node.children) && props.node.children.length > 0);

const isExpanded = computed(() => props.forceExpand || props.expandedIds.has(props.node.id));

const typeBadgeClass = computed(() => {
  switch (props.node.account_type) {
    case 'asset':
      return 'bg-blue-500/10 text-blue-700 dark:text-blue-300';
    case 'liability':
      return 'bg-orange-500/10 text-orange-700 dark:text-orange-300';
    case 'equity':
      return 'bg-purple-500/10 text-purple-700 dark:text-purple-300';
    case 'revenue':
      return 'bg-teal-500/10 text-teal-700 dark:text-teal-300';
    case 'expense':
      return 'bg-rose-500/10 text-rose-700 dark:text-rose-300';
    default:
      return 'bg-[var(--bg)] text-[var(--muted)]';
  }
});

const deleteDisabledReason = computed(() => {
  if (props.node.is_group) return t('chartOfAccounts.cannotDeleteGroup');
  if (props.node.is_linked_to_settings) return t('chartOfAccounts.cannotDeleteSetting');
  if (props.node.is_linked_to_contact) return t('chartOfAccounts.cannotDeleteContact');
  return '';
});
</script>
