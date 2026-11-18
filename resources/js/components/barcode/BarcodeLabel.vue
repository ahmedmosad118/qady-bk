<template>
  <div
    class="barcode-label"
    :style="{
      width: `${settings.width}mm`,
      height: `${settings.height}mm`,
      padding: `${settings.padding}mm`,
      fontSize: `${settings.fontSize}pt`,
    }"
  >
    <div v-if="settings.showName" class="bl-name">{{ label.name }}</div>
    <div v-if="settings.showAuthor && label.author" class="bl-sub">{{ label.author }}</div>
    <div v-if="settings.showPublisher && label.publisher" class="bl-sub">{{ label.publisher }}</div>
    <div class="bl-bars">
      <svg ref="svgRef" />
    </div>
    <div v-if="settings.showBarcodeText" class="bl-code">{{ label.barcode }}</div>
    <div v-if="settings.showPrice && label.selling_price != null" class="bl-price">{{ price }}</div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import JsBarcode from 'jsbarcode';
import { resolveFormat } from '../../utils/barcodeLabel';

const props = defineProps({
  label: { type: Object, required: true },
  settings: { type: Object, required: true },
});

const { t } = useI18n();
const svgRef = ref(null);

const price = computed(
  () => `${Number(props.label.selling_price).toFixed(2)} ${t('common.currency')}`
);

function render() {
  const svg = svgRef.value;
  if (!svg || !props.label.barcode) return;
  try {
    JsBarcode(svg, String(props.label.barcode), {
      format: resolveFormat(props.label.barcode, props.settings.barcodeType),
      width: 2,
      height: 60,
      margin: 0,
      displayValue: false,
    });
    // Make the SVG scale to its container instead of using fixed pixels.
    const w = svg.getAttribute('width');
    const h = svg.getAttribute('height');
    svg.setAttribute('viewBox', `0 0 ${parseFloat(w)} ${parseFloat(h)}`);
    svg.removeAttribute('width');
    svg.removeAttribute('height');
    svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
  } catch {
    svg.innerHTML = '';
  }
}

onMounted(render);
watch(() => [props.label.barcode, props.settings.barcodeType], render);
</script>

<style>
.barcode-label {
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  overflow: hidden;
  background: #fff;
  color: #000;
  text-align: center;
  line-height: 1.15;
  font-family: Tahoma, Arial, sans-serif;
}
.barcode-label .bl-name {
  width: 100%;
  font-weight: 700;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.barcode-label .bl-sub {
  width: 100%;
  font-size: 0.85em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.barcode-label .bl-bars {
  flex: 1 1 0;
  min-height: 0;
  width: 100%;
  display: flex;
  align-items: stretch;
  justify-content: center;
  padding: 0.3mm 0;
}
.barcode-label .bl-bars svg {
  width: 100%;
  height: 100%;
}
.barcode-label .bl-code {
  direction: ltr;
  font-size: 0.85em;
  letter-spacing: 0.05em;
}
.barcode-label .bl-price {
  font-weight: 800;
  font-size: 1.1em;
}
</style>
