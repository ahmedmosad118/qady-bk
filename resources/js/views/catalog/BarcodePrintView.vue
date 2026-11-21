<template>
  <div class="space-y-6">
    <PageHeader :title="$t('barcodePrint.title')" :subtitle="$t('barcodePrint.subtitle')">
      <template #actions>
        <button
          type="button"
          class="inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-[var(--crimson-hover)] disabled:cursor-not-allowed disabled:opacity-50"
          :disabled="!selected.length || printing"
          @click="print"
        >
          <Printer class="h-[18px] w-[18px]" aria-hidden="true" />
          <span>{{ $t('barcodePrint.print') }} ({{ totalLabels }})</span>
        </button>
      </template>
    </PageHeader>

    <div class="grid gap-6 lg:grid-cols-5">
      <!-- Products picker + selection -->
      <div class="space-y-6 lg:col-span-3">
        <section class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5">
          <h2 class="mb-3 text-sm font-extrabold text-[var(--text)]">{{ $t('barcodePrint.addProducts') }}</h2>
          <div class="grid gap-3 sm:grid-cols-2">
            <input v-model="search" type="search" :placeholder="$t('barcodePrint.searchPlaceholder')" class="field" />
            <input
              v-model="scanCode"
              type="text"
              :placeholder="$t('barcodePrint.scanPlaceholder')"
              class="field"
              @keydown.enter.prevent="addByBarcode"
            />
          </div>

          <p v-if="loadError" class="mt-3 text-sm font-semibold text-[var(--crimson)]">{{ loadError }}</p>

          <ul class="mt-3 divide-y divide-[var(--border)]">
            <li v-for="p in results" :key="p.id" class="flex items-center justify-between gap-3 py-2">
              <div class="min-w-0">
                <p class="truncate text-sm font-bold text-[var(--text)]">{{ p.name }}</p>
                <p class="text-xs font-mono text-[var(--muted)]">{{ p.barcode || $t('barcodePrint.noBarcode') }}</p>
              </div>
              <button type="button" class="btn-ghost" :disabled="isSelected(p.id)" @click="add(p)">
                <Plus class="h-4 w-4" aria-hidden="true" />
                {{ isSelected(p.id) ? $t('barcodePrint.added') : $t('barcodePrint.add') }}
              </button>
            </li>
            <li v-if="!results.length && !loadingResults" class="py-6 text-center text-sm text-[var(--muted)]">
              {{ $t('barcodePrint.noResults') }}
            </li>
          </ul>

          <div v-if="lastPage > 1" class="mt-3 flex items-center justify-between text-sm">
            <button type="button" class="btn-ghost" :disabled="page <= 1" @click="page--">{{ $t('barcodePrint.prev') }}</button>
            <span class="text-[var(--muted)]">{{ page }} / {{ lastPage }}</span>
            <button type="button" class="btn-ghost" :disabled="page >= lastPage" @click="page++">{{ $t('barcodePrint.next') }}</button>
          </div>
        </section>

        <section class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-extrabold text-[var(--text)]">
              {{ $t('barcodePrint.selected') }} ({{ selected.length }})
            </h2>
            <button v-if="selected.length" type="button" class="btn-ghost" @click="selected = []">
              {{ $t('barcodePrint.clearAll') }}
            </button>
          </div>
          <p v-if="!selected.length" class="py-6 text-center text-sm text-[var(--muted)]">
            {{ $t('barcodePrint.emptySelection') }}
          </p>
          <ul v-else class="divide-y divide-[var(--border)]">
            <li v-for="item in selected" :key="item.id" class="flex items-center gap-3 py-2">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold text-[var(--text)]">{{ item.name }}</p>
                <p class="text-xs font-mono text-[var(--muted)]">
                  {{ item.barcode || $t('barcodePrint.willGenerate') }}
                </p>
              </div>
              <label class="sr-only" :for="`copies-${item.id}`">{{ $t('barcodePrint.copies') }}</label>
              <input
                :id="`copies-${item.id}`"
                v-model.number="item.copies"
                type="number"
                min="1"
                max="500"
                class="field !h-9 w-20 text-center"
                @blur="item.copies = clampCopies(item.copies)"
              />
              <button type="button" class="btn-ghost" :aria-label="$t('common.delete')" @click="remove(item.id)">
                <Trash2 class="h-4 w-4" aria-hidden="true" />
              </button>
            </li>
          </ul>
        </section>
      </div>

      <!-- Settings + preview -->
      <div class="space-y-6 lg:col-span-2">
        <section class="space-y-4 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5">
          <h2 class="text-sm font-extrabold text-[var(--text)]">{{ $t('barcodePrint.labelSize') }}</h2>
          <select class="field" :value="settings.preset" @change="onPreset($event.target.value)">
            <optgroup :label="$t('barcodePrint.groupRoll')">
              <option v-for="p in rollPresets" :key="p.key" :value="p.key">{{ p.width }} × {{ p.height }} mm</option>
            </optgroup>
            <optgroup :label="$t('barcodePrint.groupSheet')">
              <option v-for="p in sheetPresets" :key="p.key" :value="p.key">
                A4 — {{ p.columns }} × {{ sheetRows(p) }} ({{ p.width }} × {{ p.height }} mm)
              </option>
            </optgroup>
            <option value="custom">{{ $t('barcodePrint.custom') }}</option>
          </select>

          <div class="grid grid-cols-2 gap-3">
            <label class="lbl">{{ $t('barcodePrint.width') }}
              <input v-model.number="settings.width" type="number" step="0.1" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.height') }}
              <input v-model.number="settings.height" type="number" step="0.1" class="field" @input="markCustom" />
            </label>
          </div>

          <label class="lbl">{{ $t('barcodePrint.mode') }}
            <select v-model="settings.mode" class="field" @change="markCustom">
              <option value="roll">{{ $t('barcodePrint.modeRoll') }}</option>
              <option value="sheet">{{ $t('barcodePrint.modeSheet') }}</option>
            </select>
          </label>

          <div v-if="settings.mode === 'sheet'" class="grid grid-cols-2 gap-3">
            <label class="lbl">{{ $t('barcodePrint.columns') }}
              <input v-model.number="settings.columns" type="number" min="1" max="12" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.gapX') }}
              <input v-model.number="settings.gapX" type="number" step="0.1" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.gapY') }}
              <input v-model.number="settings.gapY" type="number" step="0.1" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.marginTop') }}
              <input v-model.number="settings.marginTop" type="number" step="0.1" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.marginLeft') }}
              <input v-model.number="settings.marginLeft" type="number" step="0.1" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.paperWidth') }}
              <input v-model.number="settings.paperWidth" type="number" class="field" @input="markCustom" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.paperHeight') }}
              <input v-model.number="settings.paperHeight" type="number" class="field" @input="markCustom" />
            </label>
          </div>
        </section>

        <section class="space-y-4 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5">
          <h2 class="text-sm font-extrabold text-[var(--text)]">{{ $t('barcodePrint.content') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <label v-for="f in toggles" :key="f" class="flex items-center gap-2 text-sm font-semibold text-[var(--text)]">
              <input v-model="settings[f]" type="checkbox" class="h-4 w-4 accent-[var(--crimson)]" />
              {{ $t(`barcodePrint.${f}`) }}
            </label>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <label class="lbl">{{ $t('barcodePrint.fontSize') }}
              <input v-model.number="settings.fontSize" type="number" min="4" max="24" step="0.5" class="field" />
            </label>
            <label class="lbl">{{ $t('barcodePrint.padding') }}
              <input v-model.number="settings.padding" type="number" min="0" max="10" step="0.5" class="field" />
            </label>
          </div>
          <label class="lbl">{{ $t('barcodePrint.barcodeType') }}
            <select v-model="settings.barcodeType" class="field">
              <option value="auto">{{ $t('barcodePrint.typeAuto') }}</option>
              <option value="CODE128">CODE 128</option>
              <option value="EAN13">EAN-13</option>
            </select>
          </label>
          <button type="button" class="btn-ghost" @click="resetSettings">{{ $t('barcodePrint.reset') }}</button>
        </section>

        <section class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 sm:p-5">
          <h2 class="mb-3 text-sm font-extrabold text-[var(--text)]">{{ $t('barcodePrint.preview') }}</h2>
          <div class="flex justify-center overflow-auto rounded-xl bg-[var(--bg)] p-4">
            <div class="shadow ring-1 ring-black/10">
              <BarcodeLabel :label="previewLabel" :settings="cleanSettings" />
            </div>
          </div>
        </section>
      </div>
    </div>

    <BarcodePrintSheet v-if="printLabels.length" :labels="printLabels" :settings="cleanSettings" />
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { Plus, Printer, Trash2 } from 'lucide-vue-next';
import api from '../../services/api';
import { useNotificationStore } from '../../stores/notification';
import PageHeader from '../../components/ui/PageHeader.vue';
import BarcodeLabel from '../../components/barcode/BarcodeLabel.vue';
import BarcodePrintSheet from '../../components/barcode/BarcodePrintSheet.vue';
import {
  DEFAULT_SETTINGS,
  PRESETS,
  applyPreset,
  expandLabels,
  loadSettings,
  rowsPerPage,
  sanitizeSettings,
  saveSettings,
} from '../../utils/barcodeLabel';

const { t } = useI18n();
const route = useRoute();
const notifications = useNotificationStore();

const toggles = ['showName', 'showPrice', 'showAuthor', 'showPublisher', 'showBarcodeText'];
const rollPresets = PRESETS.filter((p) => p.mode === 'roll');
const sheetPresets = PRESETS.filter((p) => p.mode === 'sheet');
const sheetRows = (p) => rowsPerPage(p);

const settings = ref(loadSettings());
const cleanSettings = computed(() => sanitizeSettings(settings.value));
watch(settings, () => saveSettings(cleanSettings.value), { deep: true });

const onPreset = (key) => {
  settings.value = applyPreset(settings.value, key);
};
const markCustom = () => {
  settings.value.preset = 'custom';
};
const resetSettings = () => {
  settings.value = applyPreset({ ...DEFAULT_SETTINGS }, DEFAULT_SETTINGS.preset);
};

// --- product picker (server-side search + pagination) ---
const search = ref('');
const page = ref(1);
const lastPage = ref(1);
const results = ref([]);
const loadingResults = ref(false);
const loadError = ref('');
const scanCode = ref('');

let searchTimer = null;
let requestSeq = 0;

async function loadResults() {
  const seq = ++requestSeq;
  loadingResults.value = true;
  loadError.value = '';
  try {
    const { data } = await api.get('/products', {
      params: { search: search.value || undefined, page: page.value, per_page: 8, is_active: true },
    });
    if (seq !== requestSeq) return;
    results.value = data.data;
    lastPage.value = data.meta?.last_page ?? 1;
  } catch {
    if (seq === requestSeq) loadError.value = t('barcodePrint.loadFailed');
  } finally {
    if (seq === requestSeq) loadingResults.value = false;
  }
}

watch(search, () => {
  page.value = 1;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadResults, 300);
});
watch(page, loadResults);

// --- selection ---
const selected = ref([]);
const isSelected = (id) => selected.value.some((i) => i.id === id);
const clampCopies = (n) => Math.min(Math.max(Math.round(Number(n)) || 1, 1), 500);

function add(p, copies = 1) {
  const existing = selected.value.find((i) => i.id === p.id);
  if (existing) {
    existing.copies = clampCopies(existing.copies + copies);
    return;
  }
  selected.value.push({
    id: p.id,
    name: p.name,
    barcode: p.barcode,
    selling_price: p.selling_price,
    author: p.author,
    publisher: p.publisher,
    copies,
  });
}
const remove = (id) => {
  selected.value = selected.value.filter((i) => i.id !== id);
};

async function addByBarcode() {
  const code = scanCode.value.trim();
  if (!code) return;
  try {
    const { data } = await api.get(`/products/lookup-barcode/${encodeURIComponent(code)}`);
    add(data.data);
    scanCode.value = '';
  } catch {
    notifications.error(t('barcodePrint.notFound'), t('common.errorTitle'));
  }
}

const totalLabels = computed(() => selected.value.reduce((sum, i) => sum + clampCopies(i.copies), 0));

const previewLabel = computed(() => {
  const first = selected.value[0];
  return first
    ? { ...first, barcode: first.barcode || '2000000000015' }
    : {
        name: t('barcodePrint.sampleName'),
        barcode: '2000000000015',
        selling_price: 50,
        author: t('barcodePrint.sampleAuthor'),
        publisher: t('barcodePrint.samplePublisher'),
      };
});

// --- printing ---
const printing = ref(false);
const printLabels = ref([]);

async function print() {
  if (!selected.value.length || printing.value) return;
  printing.value = true;
  try {
    const { data } = await api.post('/products/barcode-labels', {
      items: selected.value.map((i) => ({ product_id: i.id, copies: clampCopies(i.copies) })),
    });

    // Persisted barcodes (auto-generated) are reflected in the selection.
    for (const label of data.data) {
      const item = selected.value.find((i) => i.id === label.product_id);
      if (item) item.barcode = label.barcode;
    }
    if (data.meta?.generated) {
      notifications.success(t('barcodePrint.generated', { count: data.meta.generated }), t('common.successTitle'));
    }

    printLabels.value = expandLabels(data.data);
    await nextTick();
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    window.print();
  } catch (e) {
    const message = e.response?.data?.message || t('barcodePrint.printFailed');
    notifications.error(message, t('common.errorTitle'));
  } finally {
    printLabels.value = [];
    printing.value = false;
  }
}

onMounted(async () => {
  await loadResults();
  const ids = String(route.query.ids || '').split(',').filter(Boolean);
  if (!ids.length) return;
  try {
    const { data } = await api.get('/products', { params: { ids: ids.join(','), per_page: 100 } });
    data.data.forEach((p) => add(p));
  } catch {
    loadError.value = t('barcodePrint.loadFailed');
  }
});
</script>

<style scoped>
.field {
  height: 2.75rem;
  width: 100%;
  border-radius: 0.75rem;
  border: 1px solid var(--border);
  background: var(--card);
  padding: 0 0.75rem;
  font-size: 0.875rem;
  color: var(--text);
}
.field:focus {
  outline: 2px solid color-mix(in srgb, var(--crimson) 25%, transparent);
}
.lbl {
  display: block;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--muted);
}
.lbl .field {
  margin-top: 0.375rem;
}
.btn-ghost {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  height: 2.25rem;
  border-radius: 0.5rem;
  border: 1px solid var(--border);
  padding: 0 0.75rem;
  font-size: 0.8125rem;
  font-weight: 600;
  color: var(--muted);
}
.btn-ghost:hover:not(:disabled) {
  color: var(--text);
}
.btn-ghost:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
