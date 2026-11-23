<template>
  <PurchaseDocumentFormModal
    mode="invoice"
    :open="open"
    :document="invoice"
    :suppliers="suppliers"
    :products="products"
    :purchase-orders="purchaseOrders"
    @close="$emit('close')"
    @saved="$emit('saved', $event)"
  />
</template>

<script setup>
import PurchaseDocumentFormModal from './PurchaseDocumentFormModal.vue';

defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
  suppliers: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  purchaseOrders: { type: Array, default: () => [] },
});

defineEmits(['close', 'saved']);
</script>
