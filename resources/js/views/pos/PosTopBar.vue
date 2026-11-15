<template>
  <header class="h-[62px] flex items-center gap-3 px-4 bg-[var(--card)] border-b border-[var(--border)] select-none" dir="rtl">
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
        <b class="block text-base font-black text-[var(--text)]">نقطة البيع</b>
        <span class="text-[11px] font-semibold text-[var(--muted)]">مكتبة القاضي</span>
      </div>
    </div>

    <!-- Status Chips -->
    <div class="flex items-center gap-2 mr-2">
      <!-- Shift Status -->
      <button
        type="button"
        :class="[
          'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold transition',
          posStore.isOpenShift
            ? 'bg-[var(--okbg)] text-[var(--ok)]'
            : 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400'
        ]"
        @click="onShiftChipClick"
      >
        <span
          class="w-2 h-2 rounded-full"
          :class="posStore.isOpenShift ? 'bg-[var(--ok)] animate-pulse' : 'bg-red-500'"
        ></span>
        <span>{{ posStore.isOpenShift ? `وردية مفتوحة (${posStore.activeShift?.shift_number})` : 'الوردية مغلقة' }}</span>
      </button>

      <!-- Warehouse & Cashier -->
      <span class="hidden md:inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[var(--bar)] text-[var(--text)]">
        <Store class="w-3.5 h-3.5 text-[var(--muted)]" />
        <span>{{ posStore.currentWarehouse?.name || 'المخزن الرئيسي' }}</span>
        <span class="text-[var(--muted)]">·</span>
        <User class="w-3.5 h-3.5 text-[var(--muted)]" />
        <span>{{ authStore.user?.name || 'كاشير' }}</span>
      </span>

      <!-- Online / Offline -->
      <span
        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-bold"
        :class="posStore.isOnline ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/40'"
      >
        <Wifi v-if="posStore.isOnline" class="w-3 h-3 text-emerald-600" />
        <WifiOff v-else class="w-3 h-3 text-amber-600" />
        <span>{{ posStore.isOnline ? 'متصل' : 'أوفلاين' }}</span>
      </span>
    </div>

    <!-- Spacer -->
    <div class="flex-1"></div>

    <!-- Top Action Buttons -->
    <div class="flex items-center gap-2">
      <!-- Offline Queue Indicator (when has pending or conflict items) -->
      <button
        v-if="posStore.offlineQueue.length > 0"
        type="button"
        class="h-9 px-3 rounded-xl border border-amber-300 bg-amber-50 dark:bg-amber-950/40 text-amber-900 dark:text-amber-200 text-xs font-bold hover:bg-amber-100 transition flex items-center gap-1.5 relative"
        title="عمليات أوفلاين تنتظر المزامنة مع السيرفر"
        @click="$emit('open-offline-queue')"
      >
        <RefreshCw v-if="posStore.syncingQueue" class="w-4 h-4 text-amber-600 animate-spin" />
        <CloudOff v-else class="w-4 h-4 text-amber-600" />
        <span class="hidden sm:inline">طابور الأوفلاين</span>
        <span
          class="inline-flex items-center justify-center min-w-[20px] h-5 px-1 rounded-full text-white text-[11px] font-black"
          :class="posStore.conflictOfflineCount > 0 ? 'bg-red-600' : 'bg-amber-600'"
        >
          {{ posStore.offlineQueue.length }}
        </span>
      </button>

      <!-- Search Invoice / Returns -->
      <button
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5"
        title="البحث عن فاتورة سابقة أو إجراء مرتجع"
        @click="$emit('open-returns')"
      >
        <Search class="w-4 h-4 text-[var(--muted)]" />
        <span class="hidden lg:inline">بحث / مرتجع</span>
      </button>

      <!-- Shift Cash Movement (Expense / Withdrawal) -->
      <button
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5"
        title="تسجيل مصروف نقدي أو سحب عهدة من الدرج"
        :disabled="!posStore.isOpenShift"
        @click="$emit('open-expense')"
      >
        <ArrowLeftRight class="w-4 h-4 text-[var(--muted)]" />
        <span class="hidden lg:inline">حركة نقدية</span>
      </button>

      <!-- Hold Current Cart -->
      <button
        type="button"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5 disabled:opacity-50"
        :disabled="!posStore.isOpenShift || posStore.cartLinesCount === 0"
        title="تعليق الفاتورة الحالية في المتصفح"
        @click="posStore.holdCurrentCart()"
      >
        <Pause class="w-4 h-4 text-[var(--crimson)]" />
        <span>تعليق</span>
        <kbd class="hidden sm:inline font-mono text-[10px] bg-[var(--bar)] px-1 rounded border border-[var(--border)]">F4</kbd>
      </button>

      <!-- Held Sales List -->
      <button
        type="button"
        class="h-9 px-3.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1.5 relative"
        @click="$emit('open-held')"
      >
        <Archive class="w-4 h-4 text-[var(--muted)]" />
        <span>المعلّقة</span>
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
        <span class="hidden sm:inline">إغلاق الوردية</span>
      </button>
      <button
        v-else
        type="button"
        class="h-9 px-3 rounded-xl bg-[var(--crimson)] text-white text-xs font-bold hover:opacity-90 transition flex items-center gap-1.5"
        @click="$emit('open-open-shift')"
      >
        <LockKeyholeOpen class="w-4 h-4" />
        <span>فتح وردية</span>
      </button>

      <!-- Return to Dashboard -->
      <router-link
        to="/"
        class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--muted)] hover:text-[var(--text)] text-xs font-bold hover:border-[var(--muted)] transition flex items-center gap-1"
        title="العودة إلى لوحة التحكم الرئيسية"
      >
        <LayoutDashboard class="w-4 h-4" />
        <span class="hidden xl:inline">لوحة التحكم</span>
      </router-link>
    </div>
  </header>
</template>

<script setup>
import {
  Store,
  User,
  Wifi,
  WifiOff,
  Search,
  ArrowLeftRight,
  Pause,
  Archive,
  LockKeyhole,
  LockKeyholeOpen,
  LayoutDashboard,
  CloudOff,
  RefreshCw,
} from 'lucide-vue-next';
import { usePosStore } from '../../stores/pos';
import { useAuthStore } from '../../stores/auth';

const emit = defineEmits([
  'open-returns',
  'open-expense',
  'open-held',
  'open-close-shift',
  'open-open-shift',
  'open-offline-queue',
]);

const posStore = usePosStore();
const authStore = useAuthStore();

function onShiftChipClick() {
  if (posStore.isOpenShift) {
    emit('open-close-shift');
  } else {
    emit('open-open-shift');
  }
}
</script>
