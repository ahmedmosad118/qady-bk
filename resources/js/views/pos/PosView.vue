<template>
  <div
    class="h-screen w-screen overflow-hidden flex flex-col bg-[var(--bg)] text-[var(--text)] font-sans select-none"
    :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
  >
    <!-- Top Bar -->
    <PosTopBar
      class="flex-none"
      @open-returns="showReturnsModal = true"
      @open-expense="showExpenseModal = true"
      @open-held="showHeldModal = true"
      @open-close-shift="handleOpenCloseShift"
      @open-open-shift="showOpenShiftModal = true"
    />

    <!-- Main Workspace (Catalog + Cart) -->
    <div class="flex-1 grid grid-cols-1 lg:grid-cols-[1fr_420px] min-h-0 overflow-hidden">
      <!-- Products Catalog Area -->
      <PosCatalog ref="catalogRef" class="min-h-0" />

      <!-- Cart Area -->
      <PosCart
        class="min-h-0"
        @open-pay="handleOpenPay"
        @open-add-customer="openAddCustomerModal"
      />
    </div>

    <!-- Modals -->

    <!-- Pay Modal -->
    <PayModal
      v-if="showPayModal"
      :total-amount="posStore.cartGrandTotal"
      :payment-methods="posStore.paymentMethods"
      :loading="posStore.submittingSale"
      @close="showPayModal = false"
      @confirm="handleConfirmCheckout"
    />

    <!-- Receipt Modal -->
    <ReceiptModal
      v-if="showReceiptModal && posStore.lastCompletedSale"
      :sale="posStore.lastCompletedSale"
      @new-sale="handleNewSale"
    />

    <!-- Held Sales Modal (Strictly Local Storage) -->
    <HeldSalesModal
      v-if="showHeldModal"
      :held-sales="posStore.heldSales"
      @close="showHeldModal = false"
      @resume="handleResumeHeld"
      @delete="handleDeleteHeld"
    />

    <!-- Open Shift Modal -->
    <OpenShiftModal
      v-if="showOpenShiftModal"
      :loading="openingShift"
      @open="handleConfirmOpenShift"
      @close="showOpenShiftModal = false"
    />

    <!-- Close Shift Modal -->
    <CloseShiftModal
      v-if="showCloseShiftModal"
      :summary="closingSummary"
      :loading-summary="loadingClosingSummary"
      :submitting="submittingCloseShift"
      @close="showCloseShiftModal = false"
      @confirm="handleConfirmCloseShift"
    />

    <!-- Expense / Withdrawal Modal -->
    <ExpenseModal
      v-if="showExpenseModal"
      :loading="submittingExpense"
      @close="showExpenseModal = false"
      @submit="handleConfirmExpense"
    />

    <!-- Returns & Invoice Lookup Modal -->
    <ReturnsModal
      v-if="showReturnsModal"
      @close="showReturnsModal = false"
      @reprint="handleReprintInvoice"
    />

    <!-- Add Customer Modal -->
    <AddCustomerModal
      v-if="showCustomerModal"
      :loading="submittingCustomer"
      :errors="customerFormErrors"
      @close="closeCustomerModal"
      @clear-error="clearCustomerFieldError"
      @save="handleSaveCustomer"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import PosTopBar from './PosTopBar.vue';
import PosCatalog from './PosCatalog.vue';
import PosCart from './PosCart.vue';

// Modals
import PayModal from './modals/PayModal.vue';
import ReceiptModal from './modals/ReceiptModal.vue';
import HeldSalesModal from './modals/HeldSalesModal.vue';
import OpenShiftModal from './modals/OpenShiftModal.vue';
import CloseShiftModal from './modals/CloseShiftModal.vue';
import ExpenseModal from './modals/ExpenseModal.vue';
import ReturnsModal from './modals/ReturnsModal.vue';
import AddCustomerModal from './modals/AddCustomerModal.vue';

import { usePosStore } from '../../stores/pos';
import { useNotificationStore } from '../../stores/notification';

const { t } = useI18n();
const router = useRouter();
const posStore = usePosStore();
const notify = useNotificationStore();

const catalogRef = ref(null);

// Modal states
const showPayModal = ref(false);
const showReceiptModal = ref(false);
const showHeldModal = ref(false);
const showOpenShiftModal = ref(false);
const showCloseShiftModal = ref(false);
const showExpenseModal = ref(false);
const showReturnsModal = ref(false);
const showCustomerModal = ref(false);

const openingShift = ref(false);
const closingSummary = ref(null);
const loadingClosingSummary = ref(false);
const submittingCloseShift = ref(false);
const submittingExpense = ref(false);
const submittingCustomer = ref(false);
const customerFormErrors = ref({});

function handleOpenPay() {
  if (!posStore.isOpenShift) {
    notify.error(t('pos.shiftClosedOpenFirst'));
    showOpenShiftModal.value = true;
    return;
  }

  if (posStore.cartLinesCount === 0) {
    notify.info(t('pos.cartEmptyToast'));
    return;
  }

  showPayModal.value = true;
}

async function handleConfirmCheckout(paymentData) {
  const res = await posStore.checkout(paymentData);
  if (res.success) {
    showPayModal.value = false;
    if (paymentData.autoPrint !== false) {
      showReceiptModal.value = true;
    } else {
      notify.success(t('pos.saleSavedToast'));
      catalogRef.value?.focusSearch();
    }
  }
}

function handleNewSale() {
  showReceiptModal.value = false;
  catalogRef.value?.focusSearch();
}

function handleReprintInvoice(sale) {
  posStore.lastCompletedSale = sale;
  showReturnsModal.value = false;
  showReceiptModal.value = true;
}

function handleResumeHeld(heldId) {
  const success = posStore.resumeHeldCart(heldId);
  if (success) {
    showHeldModal.value = false;
  }
}

function handleDeleteHeld(heldId) {
  posStore.deleteHeldCart(heldId);
}

async function handleConfirmOpenShift({ opening_balance, notes }) {
  openingShift.value = true;
  try {
    const res = await posStore.openShift(opening_balance, notes);
    if (res.success) {
      showOpenShiftModal.value = false;
    }
  } finally {
    openingShift.value = false;
  }
}

async function handleOpenCloseShift() {
  if (!posStore.activeShift) return;

  loadingClosingSummary.value = true;
  showCloseShiftModal.value = true;

  try {
    const summary = await posStore.getShiftSummary(posStore.activeShift.id);
    closingSummary.value = summary;
  } finally {
    loadingClosingSummary.value = false;
  }
}

async function handleConfirmCloseShift({ actual_cash, notes }) {
  submittingCloseShift.value = true;
  try {
    const res = await posStore.closeShift(actual_cash, notes);
    if (res.success) {
      showCloseShiftModal.value = false;
      await router.push({ name: 'home' });
    }
  } finally {
    submittingCloseShift.value = false;
  }
}

async function handleConfirmExpense({ type, amount, name, description }) {
  submittingExpense.value = true;
  try {
    let res;
    if (type === 'expense') {
      res = await posStore.recordExpense({ amount, name, description });
    } else {
      res = await posStore.recordWithdrawal({ amount, name, description });
    }
    if (res.success) {
      showExpenseModal.value = false;
    }
  } finally {
    submittingExpense.value = false;
  }
}

function openAddCustomerModal() {
  customerFormErrors.value = {};
  showCustomerModal.value = true;
}

function closeCustomerModal() {
  showCustomerModal.value = false;
  customerFormErrors.value = {};
}

function clearCustomerFieldError(field) {
  if (customerFormErrors.value && field in customerFormErrors.value) {
    const next = { ...customerFormErrors.value };
    delete next[field];
    customerFormErrors.value = next;
  }
}

async function handleSaveCustomer({ name, mobile, type }) {
  submittingCustomer.value = true;
  customerFormErrors.value = {};
  try {
    const res = await posStore.createCustomer({ name, mobile, type });
    if (res.success) {
      closeCustomerModal();
    } else if (res.errors && typeof res.errors === 'object') {
      customerFormErrors.value = res.errors;
    }
  } finally {
    submittingCustomer.value = false;
  }
}

// Global Keyboard Shortcuts
function onGlobalKeyDown(e) {
  // F2: Focus Search
  if (e.key === 'F2') {
    e.preventDefault();
    catalogRef.value?.focusSearch();
    return;
  }

  // F4: Hold Current Cart
  if (e.key === 'F4') {
    e.preventDefault();
    if (posStore.cartLinesCount > 0) {
      posStore.holdCurrentCart();
    }
    return;
  }

  // F9: Open Checkout Modal
  if (e.key === 'F9') {
    e.preventDefault();
    handleOpenPay();
    return;
  }

  // Escape: Close topmost modal
  if (e.key === 'Escape') {
    if (showPayModal.value) showPayModal.value = false;
    else if (showReceiptModal.value) showReceiptModal.value = false;
    else if (showHeldModal.value) showHeldModal.value = false;
    else if (showCloseShiftModal.value) showCloseShiftModal.value = false;
    else if (showExpenseModal.value) showExpenseModal.value = false;
    else if (showReturnsModal.value) showReturnsModal.value = false;
    else if (showCustomerModal.value) closeCustomerModal();
  }
}

onMounted(async () => {
  window.addEventListener('keydown', onGlobalKeyDown);

  // Initialize POS
  await posStore.bootstrap();

  // Only prompt cashier if they do not have an open shift
  showOpenShiftModal.value = !posStore.isOpenShift;
});

onUnmounted(() => {
  window.removeEventListener('keydown', onGlobalKeyDown);
});
</script>
