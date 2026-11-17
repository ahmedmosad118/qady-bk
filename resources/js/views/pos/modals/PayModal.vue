<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" dir="rtl" @keydown.esc="$emit('close')">
    <div class="w-full max-w-[560px] max-h-[92vh] overflow-y-auto rounded-3xl bg-[var(--card)] p-6 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Title -->
      <div class="flex items-center justify-between border-b border-[var(--border)] pb-3 mb-4">
        <div class="flex items-center gap-2">
          <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
          <h3 class="text-xl font-black">تحصيل الفاتورة وتأكيد الدفع</h3>
        </div>
        <button
          type="button"
          class="rounded-xl p-1.5 text-[var(--muted)] hover:bg-[var(--border)] hover:text-[var(--text)] transition cursor-pointer"
          @click="$emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Total Required -->
      <div class="mb-4 rounded-2xl bg-[var(--soft)] p-4 text-center text-[var(--crimson)] border border-[var(--crimson)]/20 shadow-sm">
        <span class="block text-sm font-bold text-[var(--muted)] mb-1">المبلغ المطلوب سداده</span>
        <b class="text-3xl font-black tracking-tight">{{ formatNumber(totalAmount) }} <small class="text-base font-normal">ج.م</small></b>
      </div>

      <!-- Payment Method Selector -->
      <div class="grid grid-cols-3 gap-2.5 mb-4">
        <button
          v-for="method in availableMethods"
          :key="method.id"
          type="button"
          :class="[
            'flex flex-col items-center justify-center gap-1.5 py-3 px-2 rounded-2xl border-2 transition font-bold text-sm cursor-pointer',
            selectedMethodId === method.id
              ? 'border-[var(--crimson)] bg-[var(--soft)] text-[var(--crimson)] shadow-sm'
              : 'border-[var(--border)] bg-[var(--card)] text-[var(--text)] hover:border-[var(--muted)]'
          ]"
          @click="selectMethod(method)"
        >
          <Banknote v-if="(method.type || '') === 'cash' || (!method.type && method.name === 'نقدي')" class="w-6 h-6" />
          <CreditCard v-else-if="(method.type || '') === 'visa' || (!method.type && method.name === 'فيزا')" class="w-6 h-6" />
          <Building2 v-else class="w-6 h-6" />
          <span>{{ method.name }}</span>
        </button>
      </div>

      <!-- Cash Payment Details -->
      <div v-if="isCashMethod" class="space-y-3 mb-4">
        <div>
          <label class="block text-sm font-bold mb-1 text-[var(--text)]">المبلغ المدفوع من العميل (ج.م)</label>
          <input
            ref="paidInputRef"
            v-model.number="paidAmount"
            type="number"
            min="0"
            step="any"
            class="w-full h-12 text-center text-2xl font-black rounded-xl border-2 border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)] transition shadow-inner"
            @keyup.enter="confirmPayment(true)"
          />
        </div>

        <!-- Quick Cash Denominations -->
        <div class="flex flex-wrap gap-2">
          <button
            v-for="amt in quickAmounts"
            :key="amt"
            type="button"
            class="px-3 py-1.5 rounded-xl border border-[var(--border)] bg-[var(--bar)] text-[var(--text)] text-sm font-bold hover:bg-[var(--border)] transition cursor-pointer"
            @click="setPaid(amt)"
          >
            {{ amt === totalAmount ? 'المبلغ كاملًا' : `${formatNumber(amt)} ج.م` }}
          </button>
        </div>

        <!-- Change Calculation -->
        <div class="flex items-center justify-between py-3 px-2 rounded-xl bg-[var(--bar)] border border-[var(--border)] font-black text-lg">
          <span class="text-sm font-bold text-[var(--muted)]">الباقي للعميل:</span>
          <span :class="isPaidSufficient ? 'text-emerald-600 dark:text-emerald-400 text-xl font-black' : 'text-red-600 text-sm font-bold'">
            {{ isPaidSufficient ? `${formatNumber(changeAmount)} ج.م` : 'المبلغ المدفوع غير كافٍ' }}
          </span>
        </div>
      </div>

      <!-- Non-Cash Notice -->
      <div v-else class="p-4 rounded-xl bg-[var(--bar)] text-[var(--muted)] text-sm mb-4 border border-[var(--border)]">
        سيتم تسجيل الفاتورة مسددة بالكامل عبر <strong class="text-[var(--text)]">{{ selectedMethodName }}</strong> بمبلغ <strong>{{ formatNumber(totalAmount) }} ج.م</strong>.
      </div>

      <!-- Notes Field -->
      <div class="mb-5">
        <label class="block text-xs font-semibold mb-1 text-[var(--muted)]">ملاحظات على الفاتورة (اختياري)</label>
        <input
          v-model="notes"
          type="text"
          placeholder="مثال: خصم خاص بنقابة المحامين، تم التسليم باليد..."
          class="w-full h-10 px-3 text-sm rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)] transition"
          @keyup.enter="confirmPayment(true)"
        />
      </div>

      <!-- Actions -->
      <div class="flex flex-col sm:flex-row gap-3 pt-2 border-t border-[var(--border)]">
        <!-- Cancel Button -->
        <button
          type="button"
          class="h-12 px-5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] font-bold text-sm hover:bg-[var(--border)] transition cursor-pointer order-3 sm:order-1"
          :disabled="loading"
          @click="$emit('close')"
        >
          إلغاء (Esc)
        </button>

        <!-- Save Only Button (No Print) -->
        <button
          type="button"
          class="h-12 px-4 rounded-xl border-2 border-emerald-600 text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 font-bold text-sm transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 cursor-pointer order-2"
          :disabled="loading || (isCashMethod && !isPaidSufficient)"
          title="حفظ الفاتورة وترحيلها في النظام بدون طباعة إيصال"
          @click="confirmPayment(false)"
        >
          <Check class="w-4 h-4 text-emerald-600" />
          <span>حفظ فقط</span>
        </button>

        <!-- Primary Action: Save & Print Invoice (Prominent Save Button) -->
        <button
          type="button"
          class="flex-1 h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black text-base transition disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2 shadow-lg shadow-emerald-600/30 cursor-pointer order-1 sm:order-3"
          :disabled="loading || (isCashMethod && !isPaidSufficient)"
          @click="confirmPayment(true)"
        >
          <span v-if="loading" class="animate-spin h-5 w-5 border-2 border-white border-t-transparent rounded-full"></span>
          <Printer v-else class="w-5 h-5" />
          <span>حفظ وطباعة الفاتورة (F10)</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { X, Banknote, CreditCard, Building2, Printer, Check } from 'lucide-vue-next';

const props = defineProps({
  totalAmount: {
    type: Number,
    required: true,
  },
  paymentMethods: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['close', 'confirm']);

const paidInputRef = ref(null);
const selectedMethodId = ref(null);
const paidAmount = ref(props.totalAmount);
const notes = ref('');

// Filter available payment methods to strictly cash, visa, bank transfer
const availableMethods = computed(() => {
  const allowedTypes = ['cash', 'visa', 'bank_transfer'];
  const allowedNames = ['نقدي', 'فيزا', 'تحويل بنكي'];
  const list = props.paymentMethods.filter(
    (m) => (m.type && allowedTypes.includes(m.type)) || (!m.type && allowedNames.includes(m.name))
  );
  return list.length > 0 ? list : [
    { id: 1, name: 'نقدي', type: 'cash' },
    { id: 2, name: 'فيزا', type: 'visa' },
    { id: 3, name: 'تحويل بنكي', type: 'bank_transfer' },
  ];
});

const selectedMethod = computed(() => {
  return availableMethods.value.find((m) => m.id === selectedMethodId.value) || availableMethods.value[0];
});

const selectedMethodName = computed(() => selectedMethod.value?.name || 'نقدي');
const isCashMethod = computed(() => {
  if (selectedMethod.value?.type) {
    return selectedMethod.value.type === 'cash';
  }
  return selectedMethodName.value === 'نقدي';
});

const isPaidSufficient = computed(() => {
  if (!isCashMethod.value) return true;
  return Number(paidAmount.value || 0) >= props.totalAmount;
});

const changeAmount = computed(() => {
  const diff = Number(paidAmount.value || 0) - props.totalAmount;
  return Math.max(0, Math.round(diff * 100) / 100);
});

const quickAmounts = computed(() => {
  const T = props.totalAmount;
  const standard = [T, 100, 200, 500, 1000, 2000];
  const unique = Array.from(new Set(standard.filter((v) => v >= T)));
  return unique.slice(0, 5);
});

function selectMethod(method) {
  selectedMethodId.value = method.id;
  const isCash = method.type ? method.type === 'cash' : method.name === 'نقدي';
  if (isCash) {
    paidAmount.value = props.totalAmount;
    nextTick(() => {
      paidInputRef.value?.focus();
      paidInputRef.value?.select();
    });
  } else {
    paidAmount.value = props.totalAmount;
  }
}

function setPaid(val) {
  paidAmount.value = val;
  nextTick(() => paidInputRef.value?.focus());
}

function formatNumber(num) {
  return Number(num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function confirmPayment(autoPrint = true) {
  if (isCashMethod.value && !isPaidSufficient.value) return;

  emit('confirm', {
    payment_method_id: selectedMethod.value.id,
    paid_amount: isCashMethod.value ? Number(paidAmount.value) : props.totalAmount,
    notes: notes.value,
    autoPrint: autoPrint,
  });
}

function onModalKeyDown(e) {
  if (e.key === 'F10') {
    e.preventDefault();
    confirmPayment(true);
  }
}

onMounted(() => {
  window.addEventListener('keydown', onModalKeyDown);
  if (availableMethods.value.length > 0) {
    selectedMethodId.value = availableMethods.value[0].id;
  }
  nextTick(() => {
    paidInputRef.value?.focus();
    paidInputRef.value?.select();
  });
});

onUnmounted(() => {
  window.removeEventListener('keydown', onModalKeyDown);
});
</script>
