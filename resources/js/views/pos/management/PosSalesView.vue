<template>
  <div class="space-y-4">
    <!-- Page Header -->
    <PageHeader
      :title="$t('pos.salesPage.title')"
      :subtitle="$t('pos.salesPage.subtitle')"
    >
      <template #actions>
        <router-link
          to="/pos/shifts"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 text-sm font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)]"
        >
          <Clock class="h-[18px] w-[18px] text-amber-600" />
          <span>{{ $t('pos.salesPage.shiftsBtn') }}</span>
        </router-link>

        <router-link
          to="/pos"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)]"
        >
          <Store class="h-[18px] w-[18px]" />
          <span>{{ $t('pos.salesPage.cashierBtn') }}</span>
        </router-link>
      </template>
    </PageHeader>

    <!-- Reusable Sales DataTable -->
    <PosSalesTable
      @open-sale="openSaleDetails"
      @open-shift="handleOpenShiftFromSale"
      @print="openPrintModal"
    />

    <!-- Sale Detail Modal -->
    <PosSaleDetailModal
      v-if="selectedSaleId"
      :sale-id="selectedSaleId"
      :initial-tab="selectedSaleTab"
      @close="selectedSaleId = null"
      @print="openPrintModal"
      @open-shift="handleOpenShiftFromSale"
      @open-return="openReturnDetails"
      @open-inventory-document="handleOpenInventoryDocumentFromSale"
    />

    <!-- Return Detail Modal (Cross-linked) -->
    <PosReturnDetailModal
      v-if="selectedReturnId"
      :return-id="selectedReturnId"
      @close="selectedReturnId = null"
      @open-sale="openSaleDetails"
      @open-shift="handleOpenShiftFromSale"
      @open-inventory-document="handleOpenInventoryDocumentFromSale"
    />

    <!-- Shift Detail Modal (Cross-linked) -->
    <PosShiftDetailModal
      v-if="selectedShiftId"
      :shift-id="selectedShiftId"
      @close="selectedShiftId = null"
      @open-sale="openSaleDetails"
      @print="openPrintModal"
    />

    <!-- Inventory Document Detail Modal (Cross-linked) -->
    <InventoryDocumentDetailModal
      v-if="selectedInvDoc"
      :open="!!selectedInvDoc"
      :document="selectedInvDoc"
      @close="selectedInvDoc = null"
      @open-pos-return="openReturnDetails"
    />

    <!-- Thermal Receipt Modal (Reused directly from POS in Reprint Mode) -->
    <ReceiptModal
      v-if="selectedSaleForReceipt"
      :sale="selectedSaleForReceipt"
      :is-reprint="true"
      @new-sale="selectedSaleForReceipt = null"
      @close="selectedSaleForReceipt = null"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { Store, Clock } from 'lucide-vue-next';
import api from '../../../services/api';
import PageHeader from '../../../components/ui/PageHeader.vue';
import PosSalesTable from './PosSalesTable.vue';
import PosSaleDetailModal from './PosSaleDetailModal.vue';
import PosReturnDetailModal from './PosReturnDetailModal.vue';
import PosShiftDetailModal from './PosShiftDetailModal.vue';
import InventoryDocumentDetailModal from '../../inventory/InventoryDocumentDetailModal.vue';
import ReceiptModal from '../modals/ReceiptModal.vue';

const selectedSaleId = ref(null);
const selectedSaleTab = ref('info');
const selectedReturnId = ref(null);
const selectedShiftId = ref(null);
const selectedInvDoc = ref(null);
const selectedSaleForReceipt = ref(null);

function openSaleDetails(saleId, tab = 'info') {
  selectedInvDoc.value = null;
  selectedSaleTab.value = tab;
  selectedSaleId.value = saleId;
}

function openReturnDetails(returnId) {
  if (!returnId) return;
  selectedInvDoc.value = null;
  selectedReturnId.value = returnId;
}

async function openPrintModal(sale) {
  if (!sale.items || !sale.payment_method) {
    try {
      const res = await api.get(`/pos/sales/${sale.id}`);
      if (res.data?.success) {
        selectedSaleForReceipt.value = res.data.data;
        return;
      }
    } catch {
      // fallback to passing sale as-is
    }
  }
  selectedSaleForReceipt.value = sale;
}

function handleOpenShiftFromSale(shiftId) {
  if (!shiftId) return;
  selectedShiftId.value = shiftId;
}

async function handleOpenInventoryDocumentFromSale(docOrId) {
  const id = typeof docOrId === 'object' ? docOrId?.id : docOrId;
  if (!id) return;
  try {
    const res = await api.get(`/inventory-documents/${id}`);
    if (res.data?.success) {
      selectedInvDoc.value = res.data.data;
    }
  } catch (err) {
    console.error('Failed to open inventory document:', err);
  }
}
</script>
