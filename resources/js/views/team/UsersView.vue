<template>
  <div class="space-y-6">
    <CrudIndex
      ref="crudRef"
      endpoint="/users"
      :title="$t('users.title')"
      :custom-columns="columns"
      @loaded="onDataLoaded"
    >
      <!-- Top Stats Slot -->
      <template #top-stats>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-800">
            <span class="text-xs font-bold text-gray-500">{{ $t('users.totalUsers') }}</span>
            <p class="mt-1 font-mono text-xl font-black text-gray-900 dark:text-white">{{ stats.total }}</p>
          </div>
          <div class="rounded-2xl border border-emerald-100 bg-emerald-50/50 p-4 dark:border-emerald-900/30 dark:bg-emerald-950/20">
            <span class="text-xs font-bold text-emerald-700 dark:text-emerald-400">{{ $t('users.activeUsers') }}</span>
            <p class="mt-1 font-mono text-xl font-black text-emerald-700 dark:text-emerald-400">{{ stats.active }}</p>
          </div>
          <div class="rounded-2xl border border-rose-100 bg-rose-50/50 p-4 dark:border-rose-900/30 dark:bg-rose-950/20">
            <span class="text-xs font-bold text-rose-700 dark:text-rose-400">{{ $t('users.inactiveUsers') }}</span>
            <p class="mt-1 font-mono text-xl font-black text-rose-700 dark:text-rose-400">{{ stats.inactive }}</p>
          </div>
        </div>
      </template>

      <!-- Avatar + Name / Email column -->
      <template #col-name="{ item }">
        <div class="flex items-center gap-3">
          <img
            :src="item.avatar_url"
            :alt="item.name"
            class="h-9 w-9 rounded-xl object-cover ring-2 ring-gray-100 dark:ring-gray-700"
          />
          <div>
            <span class="block font-bold text-gray-900 dark:text-white">{{ item.name }}</span>
            <span class="block font-mono text-[11px] text-gray-400">{{ item.email }}</span>
          </div>
        </div>
      </template>

      <!-- Status column -->
      <template #col-status="{ item }">
        <span
          class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold"
          :class="item.status === 'active'
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
            : 'bg-rose-50 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400'"
        >
          <span
            class="h-1.5 w-1.5 rounded-full"
            :class="item.status === 'active' ? 'bg-emerald-500' : 'bg-rose-500'"
          ></span>
          <span>{{ item.status === 'active' ? $t('common.active') : $t('common.inactive') }}</span>
        </span>
      </template>
    </CrudIndex>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CrudIndex from '../../components/crud/CrudIndex.vue';

const { t } = useI18n();
const crudRef = ref(null);

const stats = ref({ total: 0, active: 0, inactive: 0 });

const columns = computed(() => [
  { name: 'name', label: t('users.userCol') },
  { name: 'phone', label: t('users.phoneCol') },
  { name: 'status', label: t('users.statusCol') },
]);

const onDataLoaded = (res) => {
  if (res.stats) {
    stats.value = { total: 0, active: 0, inactive: 0, ...res.stats };
  }
};
</script>
