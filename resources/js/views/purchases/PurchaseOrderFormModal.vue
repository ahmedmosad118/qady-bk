<template>
  <PurchaseDocumentFormModal
    mode="order"
    :open="open"
    :document="order"
    :suppliers="suppliers"
    :products="products"
    @close="$emit('close')"
    @saved="$emit('saved', $event)"
  />
</template>

<script setup>
import PurchaseDocumentFormModal from './PurchaseDocumentFormModal.vue';

defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
  suppliers: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
});

defineEmits(['close', 'saved']);
</script>
