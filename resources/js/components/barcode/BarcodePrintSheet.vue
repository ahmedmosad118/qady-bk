<template>
  <Teleport to="body">
    <div id="barcode-print-root">
      <component :is="'style'">{{ css }}</component>
      <div
        v-for="(pageLabels, pageIndex) in pages"
        :key="pageIndex"
        class="bp-page"
        :style="pageStyle"
      >
        <div class="bp-grid" :style="gridStyle">
          <BarcodeLabel
            v-for="(label, i) in pageLabels"
            :key="`${pageIndex}-${i}`"
            :label="label"
            :settings="settings"
          />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue';
import BarcodeLabel from './BarcodeLabel.vue';
import { pageCss, paginate } from '../../utils/barcodeLabel';

const props = defineProps({
  labels: { type: Array, required: true }, // already expanded by copies
  settings: { type: Object, required: true },
});

const pages = computed(() => paginate(props.labels, props.settings));
const css = computed(() => pageCss(props.settings));

const isSheet = computed(() => props.settings.mode === 'sheet');

const pageStyle = computed(() => ({
  width: `${isSheet.value ? props.settings.paperWidth : props.settings.width}mm`,
  height: `${isSheet.value ? props.settings.paperHeight : props.settings.height}mm`,
}));

const gridStyle = computed(() => {
  const s = props.settings;
  if (!isSheet.value) return {};
  return {
    display: 'grid',
    gridTemplateColumns: `repeat(${s.columns}, ${s.width}mm)`,
    gridAutoRows: `${s.height}mm`,
    columnGap: `${s.gapX}mm`,
    rowGap: `${s.gapY}mm`,
    padding: `${s.marginTop}mm 0 0 ${s.marginLeft}mm`,
    direction: 'ltr',
  };
});
</script>

<style>
#barcode-print-root {
  display: none;
}
@media print {
  body > *:not(#barcode-print-root) {
    display: none !important;
  }
  html,
  body {
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }
  #barcode-print-root {
    display: block !important;
    direction: ltr;
  }
  #barcode-print-root .bp-page {
    overflow: hidden;
    break-after: page;
    page-break-after: always;
  }
  #barcode-print-root .bp-page:last-child {
    break-after: auto;
    page-break-after: auto;
  }
  #barcode-print-root .barcode-label {
    direction: rtl;
  }
}
</style>
