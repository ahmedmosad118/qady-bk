<template>
  <AppDrawer
    :open="!!shiftId"
    size="xl"
    @close="emit('close')"
  >
    <!-- Drawer Header -->
    <template #header>
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)] flex items-center justify-center font-bold shrink-0">
            <Clock class="w-5 h-5" />
          </div>
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-extrabold text-base sm:text-lg leading-tight text-[var(--text)]">{{ $t('pos.shiftDetail.title') }}</h3>
              <span v-if="shift" class="font-mono text-sm px-2.5 py-0.5 rounded-lg bg-[var(--bg)] border border-[var(--border)] font-black text-[var(--text)]" dir="ltr">
                {{ shift.shift_number }}
              </span>
              <span
                v-if="shift"
                class="px-2.5 py-0.5 rounded-full text-xs font-bold"
                :class="shift.closed_at ? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30'"
              >
                {{ shift.closed_at ? $t('pos.shiftsPage.closedBadge') : $t('pos.shiftsPage.openBadge') }}
              </span>
            </div>
            <p class="text-xs text-[var(--muted)] mt-0.5">
              {{ $t('common.system') }}
            </p>
          </div>
        </div>

        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
          @click="emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>
    </template>

    <!-- Sticky Navigation Tabs -->
    <template #actions>
      <div class="flex items-center gap-1 overflow-x-auto">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          :class="[
            'px-3.5 py-2 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 whitespace-nowrap cursor-pointer',
            activeTab === tab.id
              ? 'bg-[var(--card)] text-[var(--text)] border border-[var(--border)] shadow-xs'
              : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--card)] border border-transparent'
          ]"
          @click="activeTab = tab.id"
        >
          <component :is="tab.icon" class="w-3.5 h-3.5" />
          <span>{{ tab.label }}</span>
          <span
            v-if="tab.badge !== undefined"
            class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none"
            :class="activeTab === tab.id ? 'bg-[var(--text)] text-[var(--card)]' : 'bg-[var(--border)] text-[var(--muted)]'"
          >
            {{ tab.badge }}
          </span>
        </button>
      </div>
    </template>

    <!-- Drawer Body Content -->
    <div>
      <!-- Loading State -->
      <div v-if="loading" class="py-16 text-center">
        <div class="inline-block w-8 h-8 border-3 border-[var(--muted)] border-t-transparent rounded-full animate-spin"></div>
        <p class="text-xs font-bold text-[var(--muted)] mt-3">{{ $t('pos.shiftDetail.loading') }}</p>
      </div>

      <div v-else-if="summary" class="space-y-6">
          <!-- Tab 1: Shift Information & Summary -->
          <div v-if="activeTab === 'info'" class="space-y-5">
            <!-- Key Financial Highlights Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.shiftDetail.openingBalance') }}</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block font-mono">
                  {{ Number(summary.opening_balance || 0).toFixed(2) }} <span class="text-xs font-sans font-medium">{{ $t('common.currency') }}</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.shiftDetail.cashSales') }}</span>
                <b class="text-base font-extrabold text-emerald-600 mt-1 block font-mono">
                  +{{ Number(summary.cash_sales || 0).toFixed(2) }} <span class="text-xs font-sans font-medium">{{ $t('common.currency') }}</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.shiftDetail.expensesWithdrawals') }}</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block font-mono">
                  -{{ (Number(summary.expenses || 0) + Number(summary.withdrawals || 0)).toFixed(2) }} <span class="text-xs font-sans font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.shiftDetail.cashReturns') }}</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block font-mono">
                  -{{ Number(summary.cash_returns || 0).toFixed(2) }} <span class="text-xs font-sans font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </b>
              </div>
            </div>

            <!-- Cash Drawer Balancing Box (Only for closed shifts, or preview for open) -->
            <div class="p-5 rounded-2xl border bg-[var(--card)] space-y-4" :class="shift.closed_at ? 'border-[var(--border)]' : 'border-dashed border-[var(--border)] bg-[var(--bg)]/50'">
              <div class="flex items-center justify-between pb-3 border-b border-[var(--border)]">
                <div class="flex items-center gap-2">
                  <Coins class="w-5 h-5 text-[var(--muted)]" />
                  <h4 class="font-extrabold text-sm text-[var(--text)]">{{ $t('pos.shiftDetail.balancingTitle') }}</h4>
                </div>
                <span v-if="!shift.closed_at" class="text-xs font-bold text-amber-700 dark:text-amber-300 bg-amber-500/10 border border-amber-500/20 px-2.5 py-0.5 rounded-full">
                  {{ $t('pos.shiftDetail.runningEstimated') }}
                </span>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-3 rounded-xl bg-[var(--bar)]/50">
                  <span class="text-xs text-[var(--muted)] block">{{ $t('pos.shiftDetail.expectedCash') }}</span>
                  <b class="text-lg font-black font-mono text-[var(--text)] mt-1 block">
                    {{ Number(summary.expected_cash || 0).toFixed(2) }} {{ $t('common.currency') }}
                  </b>
                </div>

                <div class="p-3 rounded-xl bg-[var(--bar)]/50">
                  <span class="text-xs text-[var(--muted)] block">{{ $t('pos.shiftDetail.actualCashAtClose') }}</span>
                  <b v-if="shift.closed_at" class="text-lg font-black font-mono text-[var(--text)] mt-1 block">
                    {{ Number(summary.actual_cash || 0).toFixed(2) }} {{ $t('common.currency') }}
                  </b>
                  <span v-else class="text-xs font-bold text-[var(--muted)] mt-1 block">
                    {{ $t('pos.shiftDetail.notClosedYet') }}
                  </span>
                </div>

                <!-- Difference Presentation -->
                <div
                  v-if="shift.closed_at"
                  class="p-3 rounded-xl"
                  :class="diffClass"
                >
                  <span class="text-xs font-bold block">{{ $t('pos.shiftDetail.diffTitle') }}</span>
                  <div class="flex items-center gap-1.5 mt-1">
                    <b class="text-lg font-black font-mono">
                      {{ diffFormatted }}
                    </b>
                    <span class="text-xs font-bold">({{ diffLabel }})</span>
                  </div>
                </div>

                <div v-else class="p-3 rounded-xl bg-[var(--bar)]/50 text-[var(--muted)]">
                  <span class="text-xs block">{{ $t('pos.shiftDetail.finalDiffTitle') }}</span>
                  <span class="text-xs font-medium mt-1 block">{{ $t('pos.shiftDetail.calculatedAtClose') }}</span>
                </div>
              </div>
            </div>

            <!-- Detailed Shift Metadata Table -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <div class="px-4 py-3 bg-[var(--bar)]/50 border-b border-[var(--border)]">
                <h4 class="text-xs font-black text-[var(--text)]">{{ $t('pos.shiftDetail.metadataTitle') }}</h4>
              </div>
              <div class="divide-y divide-[var(--border)] text-xs">
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.shiftNumber') }}</span>
                  <span class="col-span-2 font-mono font-bold">{{ shift.shift_number }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.openedBy') }}</span>
                  <span class="col-span-2 font-bold">{{ shift.opened_by?.name || shift.opened_by_user?.name || $t('pos.saleDetail.unknownUser') }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.openedAt') }}</span>
                  <span class="col-span-2 font-mono font-bold" dir="ltr">{{ formatDateTime(shift.opened_at, locale) }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.closedBy') }}</span>
                  <span class="col-span-2 font-bold">
                    {{ shift.closed_at ? (shift.closed_by?.name || shift.closed_by_user?.name || $t('pos.shiftDetail.sameCashier')) : '—' }}
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.closedAt') }}</span>
                  <span class="col-span-2 font-mono font-bold" dir="ltr">
                    {{ shift.closed_at ? formatDateTime(shift.closed_at, locale) : $t('pos.shiftDetail.stillOpen') }}
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.electronicSales') }}</span>
                  <span class="col-span-2 font-mono text-[var(--text)]">
                    {{ $t('pos.shiftDetail.visaLabel') }} <b>{{ Number(summary.visa_sales || 0).toFixed(2) }} {{ $t('common.currency') }}</b> · {{ $t('pos.shiftDetail.bankTransferLabel') }} <b>{{ Number(summary.bank_transfer_sales || 0).toFixed(2) }} {{ $t('common.currency') }}</b>
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.shiftDetail.notes') }}</span>
                  <span class="col-span-2 text-[var(--text)]">{{ shift.notes || $t('pos.shiftDetail.noNotes') }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Tab 2: Shift Transactions -->
          <div v-else-if="activeTab === 'transactions'" class="space-y-3">
            <div
              v-if="shift.transactions && shift.transactions.length > 0"
              class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
            >
              <table class="w-full text-xs text-start">
                <thead>
                  <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                    <th class="px-3.5 py-2.5 text-start">#</th>
                    <th class="px-3.5 py-2.5 text-start">{{ $t('pos.shiftDetail.txTypeCol') }}</th>
                    <th class="px-3.5 py-2.5 text-start">{{ $t('pos.shiftDetail.txDescCol') }}</th>
                    <th class="px-3.5 py-2.5 text-start">{{ $t('pos.shiftDetail.txRefCol') }}</th>
                    <th class="px-3.5 py-2.5 text-start">{{ $t('pos.shiftDetail.txUserCol') }}</th>
                    <th class="px-3.5 py-2.5 text-start">{{ $t('pos.shiftDetail.txDateCol') }}</th>
                    <th class="px-3.5 py-2.5 text-end">{{ $t('pos.shiftDetail.txAmountCol') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr
                    v-for="(tx, idx) in shift.transactions"
                    :key="tx.id || idx"
                    class="hover:bg-[var(--bg)]/50 transition-colors"
                  >
                    <td class="px-3.5 py-3 font-mono text-[var(--muted)]">{{ idx + 1 }}</td>
                    <td class="px-3.5 py-3">
                      <span
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-bold"
                        :class="txTypeBadge(tx.type)"
                      >
                        {{ txTypeLabel(tx.type) }}
                      </span>
                    </td>
                    <td class="px-3.5 py-3">
                      <b class="text-[var(--text)] block">{{ tx.name || $t('pos.shiftDetail.cashMovementFallback') }}</b>
                      <span v-if="tx.description" class="text-[11px] text-[var(--muted)] block">{{ tx.description }}</span>
                    </td>
                    <td class="px-3.5 py-3 font-mono text-[11px]">
                      <span v-if="tx.sale?.invoice_number" class="text-[var(--text)] font-bold">
                        {{ $t('pos.shiftDetail.invoiceRef', { number: tx.sale.invoice_number }) }}
                      </span>
                      <span v-else-if="tx.return?.return_number" class="text-[var(--text)] font-bold">
                        {{ $t('pos.shiftDetail.returnRef', { number: tx.return.return_number }) }}
                      </span>
                      <span v-else class="text-[var(--muted)]">—</span>
                    </td>
                    <td class="px-3.5 py-3 text-[var(--muted)]">{{ tx.creator?.name || $t('pos.salesPage.cashier') }}</td>
                    <td class="px-3.5 py-3 font-mono text-[11px] text-[var(--muted)]" dir="ltr">
                      {{ formatDateTime(tx.created_at, locale) }}
                    </td>
                    <td class="px-3.5 py-3 text-end font-mono font-black" :class="txAmountClass(tx.type)">
                      {{ txSign(tx.type) }}{{ Number(tx.amount || 0).toFixed(2) }} {{ $t('common.currency') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center text-xs text-[var(--muted)]">
              {{ $t('pos.shiftDetail.noTransactions') }}
            </div>
          </div>

          <!-- Tab 3: Shift Sales (Server-side paginated DataTable filtered by shiftId) -->
          <div v-else-if="activeTab === 'sales'" class="space-y-3">
            <PosSalesTable
              :shift-id="shiftId"
              :per-page="10"
              @open-sale="(id, tab) => emit('open-sale', id, tab)"
              @print="(sale) => emit('print', sale)"
              @total-updated="(total) => { shiftSalesTotalCount = total; }"
            />
          </div>
        </div>
      </div>

    <!-- Drawer Footer -->
    <template #footer>
      <div class="flex items-center justify-between gap-3">
        <span class="text-xs text-[var(--muted)]">
          {{ $t('pos.shiftDetail.footerBrand') }}
        </span>

        <button
          type="button"
          class="h-9 px-4 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:bg-[var(--bg)] transition cursor-pointer"
          @click="emit('close')"
        >
          {{ $t('common.close') }}
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  Clock,
  X,
  FileText,
  ArrowLeftRight,
  Receipt,
  Coins,
} from 'lucide-vue-next';
import api from '../../../services/api';
import { formatDateTime } from '../../../utils/date';
import AppDrawer from '../../../components/ui/AppDrawer.vue';
import PosSalesTable from './PosSalesTable.vue';

const { t, te, locale } = useI18n();

const props = defineProps({
  shiftId: {
    type: [Number, String],
    required: true,
  },
  initialShift: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'open-sale', 'print']);

const summary = ref(null);
const shift = computed(() => summary.value?.shift || props.initialShift);
const loading = ref(true);
const activeTab = ref('info');
const shiftSalesTotalCount = ref(null);

const tabs = computed(() => [
  { id: 'info', label: t('pos.shiftDetail.tabs.info'), icon: FileText },
  { id: 'transactions', label: t('pos.shiftDetail.tabs.transactions'), icon: ArrowLeftRight, badge: shift.value?.transactions?.length },
  { id: 'sales', label: t('pos.shiftDetail.tabs.sales'), icon: Receipt, badge: shiftSalesTotalCount.value ?? shift.value?.sales?.length },
]);

const diffVal = computed(() => {
  if (!shift.value?.closed_at) return null;
  return Number(summary.value?.difference ?? shift.value?.difference ?? 0);
});

const diffLabel = computed(() => {
  const d = diffVal.value;
  if (d === null) return '';
  if (d > 0.001) return t('pos.shiftDetail.surplusLabel');
  if (d < -0.001) return t('pos.shiftDetail.shortageLabel');
  return t('pos.shiftDetail.balancedLabel');
});

const diffFormatted = computed(() => {
  const d = diffVal.value;
  if (d === null) return '—';
  const prefix = d > 0 ? '+' : '';
  return `${prefix}${d.toFixed(2)} ${t('common.currency')}`;
});

const diffClass = computed(() => {
  const d = diffVal.value;
  if (d === null) return 'bg-[var(--bar)] text-[var(--muted)]';
  if (d > 0.001) return 'bg-emerald-50 text-emerald-800 border border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-200';
  if (d < -0.001) return 'bg-red-50 text-red-800 border border-red-300 dark:bg-red-950/40 dark:text-red-200';
  return 'bg-zinc-100 text-zinc-800 border border-zinc-300 dark:bg-zinc-800 dark:text-zinc-200';
});

function txTypeLabel(type) {
  if (type && te(`pos.shiftDetail.txTypes.${type}`)) {
    return t(`pos.shiftDetail.txTypes.${type}`);
  }
  return type;
}

function txTypeBadge(type) {
  switch (type) {
    case 'cash_sale': return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    case 'cash_return': return 'bg-rose-500/10 text-rose-700 dark:text-rose-400';
    case 'expense': return 'bg-amber-500/10 text-amber-700 dark:text-amber-400';
    case 'withdrawal': return 'bg-blue-500/10 text-blue-700 dark:text-blue-400';
    default: return 'bg-[var(--bar)] text-[var(--muted)]';
  }
}

function txSign(type) {
  return type === 'cash_sale' ? '+' : '-';
}

function txAmountClass(type) {
  return type === 'cash_sale' ? 'text-emerald-700 dark:text-emerald-400' : 'text-[var(--text)]';
}

async function fetchShiftDetails() {
  loading.value = true;
  try {
    const res = await api.get(`/pos/shifts/${props.shiftId}`);
    if (res.data?.success) {
      summary.value = res.data.data;
    }
  } catch (err) {
    console.error('Failed to load shift details:', err);
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  fetchShiftDetails();
});
</script>
