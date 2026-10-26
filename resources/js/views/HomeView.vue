<template>
  <div class="space-y-6" :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'">
    <BreadcrumbDefault :page-title="$t('nav.dashboard')" />

    <!-- Welcome card -->
    <div class="rounded-3xl border border-gray-100 bg-white p-8 shadow-sm dark:border-gray-800 dark:bg-gray-900">
      <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
        <div class="space-y-2">
          <span class="inline-flex items-center gap-1.5 rounded-full bg-[#A3162B]/10 px-3 py-1 text-[11px] font-bold text-[#8A1226] dark:text-[#E0344B]">
            <CheckCircle2 class="h-3.5 w-3.5" />
            {{ $t('common.system') }}
          </span>
          <h1 class="text-2xl font-black text-gray-900 dark:text-white">
            {{ $t('nav.dashboard') }}
          </h1>
          <p class="max-w-xl text-sm font-medium text-gray-500 dark:text-gray-400">
            {{ $t('auth.brandDesc') }}
          </p>
        </div>

        <BrandLogo size="lg" />
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <CardDataStats
        v-for="stat in stats"
        :key="stat.title"
        :title="stat.title"
        :total="stat.total"
      >
        <template #icon>
          <component :is="stat.icon" class="h-6 w-6" />
        </template>
      </CardDataStats>
    </div>

    <!-- UI kit preview -->
    <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
      <h2 class="mb-4 text-sm font-black text-gray-900 dark:text-white">UI kit</h2>

      <div class="flex flex-wrap items-center gap-3">
        <button class="rounded-xl bg-[#A3162B] px-4 py-2 text-xs font-bold text-white hover:bg-[#8A1226] transition-colors">
          Primary
        </button>
        <button class="rounded-xl border border-gray-200 px-4 py-2 text-xs font-bold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800 transition-colors">
          Secondary
        </button>
        <button
          class="rounded-xl bg-rose-50 px-4 py-2 text-xs font-bold text-rose-600 hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400 transition-colors"
          @click="notify"
        >
          Test Toast
        </button>
        <button
          class="rounded-xl bg-amber-50 px-4 py-2 text-xs font-bold text-amber-600 hover:bg-amber-100 dark:bg-amber-950/30 dark:text-amber-400 transition-colors"
          @click="confirmBox"
        >
          Test Alert
        </button>
      </div>

      <div class="mt-6">
        <TailAdminDataTable
          :columns="columns"
          :items="rows"
          :loading="false"
          :meta="{ current_page: 1, last_page: 1, per_page: rows.length, total: rows.length }"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import { CheckCircle2, LayoutDashboard, Users, Receipt, Target } from 'lucide-vue-next';
import BreadcrumbDefault from '../components/tailadmin/BreadcrumbDefault.vue';
import BrandLogo from '../components/common/BrandLogo.vue';
import CardDataStats from '../components/tailadmin/CardDataStats.vue';
import TailAdminDataTable from '../components/tailadmin/TailAdminDataTable.vue';
import { useNotificationStore } from '../stores/notification';
import alertService from '../services/alert';

const { t } = useI18n();
const notificationStore = useNotificationStore();

const stats = [
  { title: t('common.system'), total: '—', rate: '—', icon: LayoutDashboard },
  { title: t('nav.userManagement'), total: '—', rate: '—', icon: Users },
  { title: t('nav.commercial'), total: '—', rate: '—', icon: Target },
  { title: t('nav.technicalOperations'), total: '—', rate: '—', icon: Receipt },
];

const columns = [
  { name: 'name', label: t('common.name') },
  { name: 'status', label: t('common.status') },
];

const rows = [
  { id: 1, name: 'Row #1', status: '—' },
  { id: 2, name: 'Row #2', status: '—' },
];

const notify = () => {
  notificationStore.success('Toast notifications are working.');
};

const confirmBox = () => {
  alertService.confirm({ title: t('common.confirmDelete'), text: '' });
};
</script>
