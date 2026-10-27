<template>
  <div class="space-y-6">
    <!-- Greeting + quick actions -->
    <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <h1 class="text-2xl font-extrabold leading-tight text-[var(--text)] sm:text-[28px]">
          {{ greeting }}
        </h1>
        <p class="mt-1.5 text-sm leading-6 text-[var(--muted)]">
          {{ formattedDate }} — {{ $t('dashboard.summary') }}
        </p>
      </div>

      <div class="grid grid-cols-3 gap-2 sm:flex sm:flex-wrap sm:items-center">
        <router-link
          to="/invoices"
          class="inline-flex h-11 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl bg-[var(--crimson)] px-2 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] sm:gap-2 sm:px-4"
        >
          <Plus class="h-4 w-4" aria-hidden="true" />
          {{ $t('dashboard.newInvoice') }}
        </router-link>
        <router-link
          to="/publications"
          class="inline-flex h-11 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-[var(--border)] bg-[var(--card)] px-2 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/35 sm:gap-2 sm:px-4"
        >
          <BookPlus class="h-4 w-4 text-[var(--muted)]" aria-hidden="true" />
          {{ $t('dashboard.addPublication') }}
        </router-link>
        <router-link
          to="/publications"
          class="inline-flex h-11 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-[var(--border)] bg-[var(--card)] px-2 text-sm font-semibold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/35 sm:gap-2 sm:px-4"
        >
          <ScanLine class="h-4 w-4 text-[var(--muted)]" aria-hidden="true" />
          {{ $t('dashboard.scanIsbn') }}
        </router-link>
      </div>
    </div>

    <!-- KPI cards -->
    <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
      <StatTrendCard
        v-for="stat in stats"
        :key="stat.key"
        :label="stat.label"
        :value="stat.value"
        :unit="stat.unit"
        :delta="stat.delta"
        :hint="stat.hint"
        :tone="stat.tone"
        :icon="stat.icon"
        :points="stat.points"
      />
    </div>

    <!-- Detail panels -->
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
      <!-- Low stock -->
      <section class="flex h-full flex-col rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5">
        <header class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-bold text-[var(--text)]">{{ $t('dashboard.lowStockTitle') }}</h2>
            <p class="mt-1 text-sm text-[var(--muted)]">{{ $t('dashboard.lowStockSubtitle') }}</p>
          </div>
          <router-link
            to="/inventory"
            class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-[var(--crimson)] hover:underline"
          >
            {{ $t('dashboard.viewAll') }}
            <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          </router-link>
        </header>

        <ul class="mt-4 divide-y divide-[var(--border)]">
          <li
            v-for="book in lowStockBooks"
            :key="book.title"
            class="flex items-center justify-between gap-4 py-3.5 first:pt-1 last:pb-0"
          >
            <div class="min-w-0">
              <p class="truncate text-sm font-bold text-[var(--text)]">{{ book.title }}</p>
              <p class="mt-0.5 truncate text-sm text-[var(--muted)]">{{ book.author }}</p>
            </div>

            <div class="w-28 shrink-0">
              <div class="h-1.5 w-full overflow-hidden rounded-full bg-[var(--bg)]">
                <div
                  class="h-full rounded-full bg-[var(--crimson)]"
                  :style="{ width: Math.max(8, Math.round((book.stock / book.total) * 100)) + '%' }"
                ></div>
              </div>
              <p
                class="mt-1.5 text-sm"
                :class="book.stock <= 1 ? 'font-semibold text-[var(--crimson)]' : 'text-[var(--muted)]'"
              >
                {{ stockLabel(book) }}
              </p>
            </div>
          </li>
        </ul>
      </section>

      <!-- Latest invoices -->
      <section class="flex h-full flex-col rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5">
        <header class="flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-bold text-[var(--text)]">{{ $t('dashboard.latestInvoices') }}</h2>
            <p class="mt-1 text-sm text-[var(--muted)]">{{ $t('dashboard.latestInvoicesSubtitle') }}</p>
          </div>
          <router-link
            to="/invoices"
            class="inline-flex shrink-0 items-center gap-1 text-sm font-semibold text-[var(--crimson)] hover:underline"
          >
            {{ $t('dashboard.viewAll') }}
            <ArrowLeft class="h-4 w-4" aria-hidden="true" />
          </router-link>
        </header>

        <ul class="mt-4 divide-y divide-[var(--border)]">
          <li
            v-for="invoice in invoices"
            :key="invoice.number"
            class="flex items-center justify-between gap-3 py-3.5 first:pt-1 last:pb-0"
          >
            <div class="min-w-0">
              <p class="text-sm font-bold text-[var(--text)]" dir="ltr">{{ invoice.number }}</p>
              <p class="mt-0.5 truncate text-sm text-[var(--muted)]">{{ invoice.customer }}</p>
            </div>

            <div class="flex shrink-0 items-center gap-3">
              <p class="text-sm font-bold tabular-nums text-[var(--text)]">
                {{ invoice.amount }}
                <span class="text-sm font-semibold text-[var(--muted)]">{{ $t('common.currency') }}</span>
              </p>
              <span
                class="inline-flex items-center rounded-full border px-2.5 py-1 text-sm font-semibold leading-5"
                :class="invoiceTone[invoice.status]"
              >
                {{ $t(`dashboard.${invoice.status}`) }}
              </span>
            </div>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeft,
  BookOpen,
  BookPlus,
  FileWarning,
  Plus,
  Receipt,
  ScanLine,
  TriangleAlert,
} from 'lucide-vue-next';
import { useAuthStore } from '../stores/auth';
import StatTrendCard from '../components/dashboard/StatTrendCard.vue';

const { t, locale } = useI18n();
const authStore = useAuthStore();

const greeting = computed(() => {
  const key = new Date().getHours() < 12 ? 'dashboard.greetingMorning' : 'dashboard.greetingEvening';
  return t(key, { name: authStore.user?.name || t('header.user') });
});

const formattedDate = computed(() =>
  new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date())
);

const stats = computed(() => [
  {
    key: 'sales',
    label: t('dashboard.salesToday'),
    value: '4,820',
    unit: t('common.currency'),
    delta: '+12%',
    hint: t('dashboard.vsYesterday'),
    tone: 'success',
    icon: Receipt,
    points: [5, 8, 6, 10, 9, 13, 12, 15],
  },
  {
    key: 'unpaid',
    label: t('dashboard.unpaidInvoices'),
    value: '7',
    unit: '',
    delta: t('dashboard.overdueCount', { count: 3 }),
    hint: t('dashboard.needsFollowUp'),
    tone: 'warning',
    icon: FileWarning,
    points: [4, 6, 5, 7, 6, 9, 7, 8],
  },
  {
    key: 'stock',
    label: t('dashboard.stockTitles'),
    value: '1,284',
    unit: t('dashboard.titleUnit'),
    delta: '+4%',
    hint: t('dashboard.thisWeek'),
    tone: 'success',
    icon: BookOpen,
    points: [8, 8.4, 8.1, 8.8, 8.6, 9.1, 8.9, 9.4],
  },
  {
    key: 'low',
    label: t('dashboard.lowStockBooks'),
    value: '9',
    unit: t('dashboard.titleUnit'),
    delta: t('dashboard.needsOrder'),
    hint: t('dashboard.thisWeek'),
    tone: 'danger',
    icon: TriangleAlert,
    points: [10, 9, 9.4, 8.6, 8.8, 8, 8.3, 7.6],
  },
]);

const lowStockBooks = [
  {
    title: 'الوسيط في شرح القانون المدني',
    author: 'عبد الرزاق السنهوري — الجزء الأول',
    stock: 1,
    total: 6,
  },
  {
    title: 'قانون العقوبات معلقًا عليه',
    author: 'دار النهضة العربية — الطبعة الأخيرة',
    stock: 2,
    total: 9,
  },
  {
    title: 'شرح قانون المرافعات',
    author: 'المستشار عادل عبد الحميد',
    stock: 3,
    total: 12,
  },
  {
    title: 'ملخص الإجراءات الجنائية',
    author: 'د. أحمد فتحي سرور',
    stock: 4,
    total: 15,
  },
];

const invoices = [
  { number: '#1048', customer: 'مكتب النور للمحاماة', amount: '2,150', status: 'paid' },
  { number: '#1047', customer: 'أ. محمد عبد الله', amount: '640', status: 'partial' },
  { number: '#1046', customer: 'كلية الحقوق — جامعة القاهرة', amount: '1,320', status: 'paid' },
  { number: '#1045', customer: 'د. هبة سمير', amount: '3,900', status: 'overdue' },
];

const invoiceTone = {
  paid: 'border-[var(--success-border)] bg-[var(--success-soft)] text-[var(--success)]',
  partial: 'border-[var(--gold)]/25 bg-[var(--gold-soft)] text-[var(--gold-strong)]',
  overdue: 'border-[var(--crimson)]/25 bg-[var(--crimson-soft)] text-[var(--crimson)]',
  unpaid: 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)]',
};

const stockLabel = (book) =>
  book.stock <= 1 ? t('dashboard.lastCopy') : t('dashboard.copiesAvailable', { count: book.stock });
</script>
