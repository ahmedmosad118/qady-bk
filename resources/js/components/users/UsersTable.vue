<template>
  <div>
    <!-- Mobile list -->
    <div class="md:hidden">
      <div v-if="loading" class="divide-y divide-[var(--border)]" aria-busy="true">
        <div v-for="n in 4" :key="n" class="flex items-start gap-3 px-4 py-4">
          <div class="h-[18px] w-[18px] animate-pulse rounded bg-[var(--bg)]"></div>
          <div class="h-10 w-10 animate-pulse rounded-full bg-[var(--bg)]"></div>
          <div class="flex-1 space-y-2 pt-1">
            <div class="h-3.5 w-36 animate-pulse rounded bg-[var(--bg)]"></div>
            <div class="h-3 w-52 animate-pulse rounded bg-[var(--bg)]"></div>
          </div>
        </div>
      </div>

      <div v-else class="divide-y divide-[var(--border)]">
        <div
          v-for="user in users"
          :key="user.id"
          class="flex items-start gap-3 px-4 py-4 transition-colors"
          :class="isSelected(user) ? 'bg-[var(--crimson-soft)]' : ''"
        >
          <span class="mt-2.5 shrink-0">
            <AppCheckbox
              :checked="isSelected(user)"
              :aria-label="$t('users.selectRow', { name: user.name })"
              @change="$emit('toggle-row', user)"
            />
          </span>

          <UserAvatar :name="user.name" />

          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-bold text-[var(--text)]">{{ user.name }}</p>
            <p class="mt-0.5 truncate text-sm text-[var(--muted)]">{{ user.email }}</p>

            <div class="mt-2 flex flex-wrap items-center gap-2">
              <StatusBadge :active="user.status === 'active'" />
              <span
                v-if="roleLabel(user)"
                class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 text-sm font-medium text-[var(--muted)]"
              >
                {{ roleLabel(user) }}
              </span>
              <span v-if="user.phone" dir="ltr" class="text-sm tabular-nums text-[var(--muted)]">
                {{ user.phone }}
              </span>
            </div>

            <p v-if="lastLogin(user)" class="mt-2 flex items-center gap-1.5 text-sm text-[var(--muted)]">
              <Clock class="h-4 w-4 shrink-0" aria-hidden="true" />
              <span>{{ lastLogin(user) }}</span>
            </p>
          </div>

          <div class="flex shrink-0 flex-col gap-1">
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
              :aria-label="$t('users.editMember', { name: user.name })"
              :title="$t('common.edit')"
              @click="$emit('edit', user)"
            >
              <Pencil class="h-4 w-4" aria-hidden="true" />
            </button>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]"
              :aria-label="$t('users.deleteMember', { name: user.name })"
              :title="$t('common.delete')"
              @click="$emit('delete', user)"
            >
              <Trash2 class="h-4 w-4" aria-hidden="true" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Desktop table -->
    <div class="hidden overflow-x-auto md:block">
      <table class="w-full min-w-[520px] table-auto text-right">
        <thead class="bg-[var(--bg)]">
          <tr>
            <th scope="col" class="w-12 px-4 py-3.5">
              <AppCheckbox
                :checked="allSelected"
                :indeterminate="someSelected && !allSelected"
                :aria-label="$t('users.selectAll')"
                @change="$emit('toggle-all')"
              />
            </th>
            <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
              {{ $t('users.userCol') }}
            </th>
            <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] xl:table-cell">
              {{ $t('users.roleCol') }}
            </th>
            <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] xl:table-cell">
              {{ $t('users.phoneCol') }}
            </th>
            <th scope="col" class="px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
              {{ $t('users.statusCol') }}
            </th>
            <th scope="col" class="hidden px-4 py-3.5 text-sm font-semibold text-[var(--muted)] xl:table-cell">
              {{ $t('users.lastLoginCol') }}
            </th>
            <th scope="col" class="w-24 px-4 py-3.5 text-sm font-semibold text-[var(--muted)]">
              {{ $t('common.actions') }}
            </th>
          </tr>
        </thead>

        <tbody v-if="loading" aria-busy="true">
          <tr v-for="n in 4" :key="n" class="border-t border-[var(--border)]">
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
            v-for="user in users"
            :key="user.id"
            class="border-t border-[var(--border)] transition-colors"
            :class="isSelected(user) ? 'bg-[var(--crimson-soft)]' : 'hover:bg-[var(--bg)]'"
          >
            <td class="px-4 py-3.5">
              <AppCheckbox
                :checked="isSelected(user)"
                :aria-label="$t('users.selectRow', { name: user.name })"
                @change="$emit('toggle-row', user)"
              />
            </td>

            <td class="px-4 py-3.5">
              <div class="flex items-center gap-3">
                <UserAvatar :name="user.name" />
                <div class="min-w-0">
                  <p class="truncate text-sm font-bold text-[var(--text)]">{{ user.name }}</p>
                  <p class="mt-0.5 truncate text-sm text-[var(--muted)]">{{ user.email }}</p>
                </div>
              </div>
            </td>

            <td class="hidden px-4 py-3.5 xl:table-cell">
              <span
                v-if="roleLabel(user)"
                class="inline-flex items-center rounded-full border border-[var(--border)] bg-[var(--bg)] px-2.5 py-1 text-sm font-medium text-[var(--muted)]"
              >
                {{ roleLabel(user) }}
              </span>
              <span v-else class="text-sm text-[var(--muted)]">—</span>
            </td>

            <td class="hidden px-4 py-3.5 xl:table-cell">
              <span v-if="user.phone" dir="ltr" class="inline-block text-sm tabular-nums text-[var(--muted)]">
                {{ user.phone }}
              </span>
              <span v-else class="text-sm text-[var(--muted)]">—</span>
            </td>

            <td class="px-4 py-3.5">
              <StatusBadge :active="user.status === 'active'" />
            </td>

            <td class="hidden px-4 py-3.5 xl:table-cell">
              <span v-if="lastLogin(user)" class="text-sm text-[var(--muted)]">{{ lastLogin(user) }}</span>
              <span
                v-else
                class="text-sm text-[var(--muted)]"
                :title="$t('users.neverLoggedIn')"
              >
                —
              </span>
            </td>

            <td class="px-4 py-3.5">
              <div class="flex items-center gap-1.5">
                <button
                  type="button"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
                  :aria-label="$t('users.editMember', { name: user.name })"
                  :title="$t('common.edit')"
                  @click="$emit('edit', user)"
                >
                  <Pencil class="h-4 w-4" aria-hidden="true" />
                </button>
                <button
                  type="button"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-[var(--muted)] transition-colors hover:bg-[var(--crimson-soft)] hover:text-[var(--crimson)]"
                  :aria-label="$t('users.deleteMember', { name: user.name })"
                  :title="$t('common.delete')"
                  @click="$emit('delete', user)"
                >
                  <Trash2 class="h-4 w-4" aria-hidden="true" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import { Clock, Pencil, Trash2 } from 'lucide-vue-next';
import UserAvatar from './UserAvatar.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import AppCheckbox from '../ui/AppCheckbox.vue';

const props = defineProps({
  users: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  selectedIds: {
    type: Array,
    default: () => [],
  },
  allSelected: {
    type: Boolean,
    default: false,
  },
  someSelected: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['toggle-all', 'toggle-row', 'edit', 'delete']);

const { locale } = useI18n();

const isSelected = (user) => props.selectedIds.includes(user.id);

const roleLabel = (user) => {
  const roles = user.roles ?? user.roles_list;
  const first = Array.isArray(roles) && roles.length ? roles[0] : user.role;

  if (!first) return '';
  if (typeof first === 'string') return first;
  return first.display_name || first.name || '';
};

const lastLogin = (user) => {
  const value = user.last_login_at || user.last_login || user.last_seen_at;
  if (!value) return '';

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';

  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
};
</script>
