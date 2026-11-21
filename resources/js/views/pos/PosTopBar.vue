<template>
  <header class="h-[62px] flex items-center gap-3 px-4 bg-[var(--card)] border-b border-[var(--border)] select-none">
    <!-- Brand / Logo -->
    <div class="flex items-center gap-2.5">
      <svg class="w-9 h-9" viewBox="0 0 200 200">
        <path d="M10 70L100 20L190 70Z" fill="var(--text)"/>
        <circle cx="100" cy="52" r="7" fill="var(--gold)"/>
        <rect x="20" y="78" width="160" height="8" rx="2" fill="var(--gold)"/>
        <g fill="var(--text)">
          <rect x="20" y="96" width="24" height="70" rx="4"/>
          <rect x="54" y="96" width="24" height="70" rx="4"/>
          <rect x="122" y="96" width="24" height="70" rx="4"/>
          <rect x="156" y="96" width="24" height="70" rx="4"/>
        </g>
        <rect x="88" y="96" width="24" height="70" rx="4" fill="var(--crimson)"/>
        <rect x="10" y="174" width="180" height="8" rx="2" fill="var(--text)"/>
      </svg>
      <div class="leading-tight">
        <b class="block text-base font-black text-[var(--text)]">{{ $t('pos.brandTitle') }}</b>
        <span class="text-[11px] font-semibold text-[var(--muted)]">{{ $t('pos.brandSubtitle') }}</span>
      </div>
    </div>

    <!-- Spacer -->
    <div class="flex-1"></div>

    <!-- Top Action Buttons -->
    <div class="flex items-center gap-2">
      <!-- Search Invoice / Returns -->
      <button
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5"
        :title="$t('pos.searchReturnTooltip')"
        @click="$emit('open-returns')"
      >
        <Search class="w-4 h-4 text-[var(--muted)]" />
        <span class="hidden lg:inline">{{ $t('pos.searchReturn') }}</span>
      </button>

      <!-- Shift Cash Movement (Expense / Withdrawal) -->
      <button
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5"
        :title="$t('pos.cashMovementTooltip')"
        :disabled="!posStore.isOpenShift"
        @click="$emit('open-expense')"
      >
        <ArrowLeftRight class="w-4 h-4 text-[var(--muted)]" />
        <span class="hidden lg:inline">{{ $t('pos.cashMovement') }}</span>
      </button>

      <!-- Hold Current Cart -->
      <button
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5 disabled:opacity-50"
        :disabled="!posStore.isOpenShift || posStore.cartLinesCount === 0"
        :title="$t('pos.holdTooltip')"
        @click="posStore.holdCurrentCart()"
      >
        <Pause class="w-4 h-4 text-[var(--crimson)]" />
        <span>{{ $t('pos.hold') }}</span>
        <kbd class="hidden sm:inline font-mono text-[10px] bg-[var(--bar)] px-1 rounded border border-[var(--border)]">F4</kbd>
      </button>

      <!-- Held Sales List -->
      <button
        type="button"
        class="h-9 px-3.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5 relative"
        @click="$emit('open-held')"
      >
        <Archive class="w-4 h-4 text-[var(--muted)]" />
        <span>{{ $t('pos.heldList') }}</span>
        <span
          v-if="posStore.heldCount > 0"
          class="inline-flex items-center justify-center min-w-[20px] h-5 px-1 rounded-full bg-[var(--crimson)] text-white text-[11px] font-black"
        >
          {{ posStore.heldCount }}
        </span>
      </button>

      <!-- Shift Toggle (Close or Open) -->
      <button
        v-if="posStore.isOpenShift"
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-red-400 hover:text-red-600 transition flex items-center gap-1.5"
        @click="$emit('open-close-shift')"
      >
        <LockKeyhole class="w-4 h-4" />
        <span class="hidden sm:inline">{{ $t('pos.closeShift') }}</span>
      </button>
      <button
        v-else
        type="button"
        class="h-9 px-3 rounded-xl bg-[var(--crimson)] text-white text-xs font-bold hover:opacity-90 transition flex items-center gap-1.5"
        @click="$emit('open-open-shift')"
      >
        <LockKeyholeOpen class="w-4 h-4" />
        <span>{{ $t('pos.openShift') }}</span>
      </button>

      <!-- Return to Dashboard -->
      <router-link
        to="/"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--muted)] hover:text-[var(--text)] text-xs font-bold hover:border-[var(--muted)] transition flex items-center gap-1"
        :title="$t('pos.backToDashboardTooltip')"
      >
        <LayoutDashboard class="w-4 h-4" />
        <span class="hidden xl:inline">{{ $t('pos.backToDashboard') }}</span>
      </router-link>
    </div>
  </header>
</template>

<script setup>
import {
  Search,
  ArrowLeftRight,
  Pause,
  Archive,
  LockKeyhole,
  LockKeyholeOpen,
  LayoutDashboard,
} from 'lucide-vue-next';
import { usePosStore } from '../../stores/pos';

const emit = defineEmits([
  'open-returns',
  'open-expense',
  'open-held',
  'open-close-shift',
  'open-open-shift',
]);

const posStore = usePosStore();
</script>
