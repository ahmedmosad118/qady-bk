<template>
  <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
    <div
      class="relative w-full max-w-2xl rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]"
    >
      <!-- Header -->
      <div class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4 bg-[var(--bg)]">
        <div>
          <h2 class="text-base font-extrabold text-[var(--text)] sm:text-lg">
            {{ $t('stocktaking.createTitle') }}
          </h2>
          <p class="text-xs text-[var(--muted)]">
            {{ $t('stocktaking.createSubtitle') }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] hover:bg-[var(--card)] hover:text-[var(--text)]"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <!-- Form Body -->
      <form id="stocktake-form" class="overflow-y-auto p-6 space-y-5" @submit.prevent="handleSubmit">
        <div v-if="submitError" class="rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-xs font-bold text-red-600">
          {{ submitError }}
        </div>

        <!-- Warehouse Selection (Only displayed when more than 1 warehouse exists) -->
        <div v-if="warehouses.length > 1">
          <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
            {{ $t('stocktaking.warehouse') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <SearchableSelect
            v-model="form.warehouse_id"
            :options="warehouseOptions"
            placeholder="-- اختر المخزن المراد جرده --"
            search-placeholder="ابحث باسم المخزن أو الكود..."
            :clearable="false"
          />
          <p v-if="errors.warehouse_id" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.warehouse_id[0] }}</p>
        </div>

        <!-- Scope Selection (Full vs Selected) -->
        <div>
          <label class="block text-xs font-bold text-[var(--text)] mb-2">
            {{ $t('stocktaking.scope') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <label
              class="flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all"
              :class="form.scope === 'full'
                ? 'border-[var(--crimson)] bg-[var(--crimson-soft)] text-[var(--crimson)] shadow-xs font-bold'
                : 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:border-[var(--border-strong)]'"
            >
              <input v-model="form.scope" type="radio" value="full" class="mt-0.5" />
              <div>
                <span class="text-sm font-extrabold block text-[var(--text)]">{{ $t('stocktaking.scopeFull') }}</span>
                <span class="text-xs text-[var(--muted)] block mt-0.5">{{ $t('stocktaking.scopeFullHint') }}</span>
              </div>
            </label>

            <label
              class="flex items-start gap-3 p-3.5 rounded-xl border cursor-pointer transition-all"
              :class="form.scope === 'selected'
                ? 'border-[var(--crimson)] bg-[var(--crimson-soft)] text-[var(--crimson)] shadow-xs font-bold'
                : 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:border-[var(--border-strong)]'"
            >
              <input v-model="form.scope" type="radio" value="selected" class="mt-0.5" />
              <div>
                <span class="text-sm font-extrabold block text-[var(--text)]">{{ $t('stocktaking.scopeSelected') }}</span>
                <span class="text-xs text-[var(--muted)] block mt-0.5">{{ $t('stocktaking.scopeSelectedHint') }}</span>
              </div>
            </label>
          </div>
          <p v-if="errors.scope" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.scope[0] }}</p>
        </div>

        <!-- Products Multi-Selector (Only for Selected Scope) -->
        <div v-if="form.scope === 'selected'" class="space-y-4">
          <div class="flex items-center justify-between border-b border-[var(--border)] pb-2">
            <div>
              <label class="block text-xs font-bold text-[var(--text)]">
                {{ $t('stocktaking.selectProducts') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <span class="text-[var(--muted)] text-[11px]">
                يمكنك المسح المباشر بالباركود أو الاختيار والبحث اليدوي.
              </span>
            </div>
            <span class="inline-flex items-center rounded-full bg-[var(--crimson-soft)] px-2.5 py-0.5 text-xs font-bold text-[var(--crimson)]">
              {{ selectedProducts.length }} كتاب محدد
            </span>
          </div>

          <!-- 1. Dedicated Barcode Scanner Box -->
          <div class="rounded-2xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)]/20 p-3.5 space-y-2">
            <div class="flex items-center justify-between">
              <span class="flex items-center gap-1.5 text-xs font-black text-[var(--crimson)]">
                <Barcode class="h-4 w-4" />
                <span>المسح والبحث السريع بالباركود:</span>
              </span>
              <span class="text-[10px] text-[var(--muted)] font-medium">يدعم أجهزة الباركود والضغط على Enter</span>
            </div>

            <div class="flex gap-2">
              <div class="relative flex-1">
                <input
                  ref="barcodeInputRef"
                  v-model="barcodeInput"
                  type="text"
                  placeholder="امسح الباركود بجهاز المسح أو اكتبه واضغط Enter..."
                  class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 pl-8 text-xs font-mono font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
                  dir="ltr"
                  @keydown.enter.prevent="handleBarcodeSubmit"
                />
                <Barcode class="absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--muted)] pointer-events-none" />
              </div>

              <button
                type="button"
                :disabled="!barcodeInput.trim() || isSearchingBarcode"
                class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-[var(--crimson-hover)] disabled:opacity-40 cursor-pointer"
                @click="handleBarcodeSubmit"
              >
                <Loader2 v-if="isSearchingBarcode" class="h-3.5 w-3.5 animate-spin" />
                <Plus v-else class="h-3.5 w-3.5" />
                <span>إضافة</span>
              </button>
            </div>

            <!-- Barcode Feedback Banner -->
            <div
              v-if="barcodeFeedback"
              class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-xs font-bold transition-all"
              :class="{
                'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20': barcodeFeedback.type === 'success',
                'bg-red-500/10 text-red-600 border border-red-500/20': barcodeFeedback.type === 'error',
                'bg-amber-500/10 text-amber-600 border border-amber-500/20': barcodeFeedback.type === 'warning',
              }"
            >
              <CheckCircle2 v-if="barcodeFeedback.type === 'success'" class="h-4 w-4 shrink-0" />
              <AlertCircle v-else class="h-4 w-4 shrink-0" />
              <span>{{ barcodeFeedback.message }}</span>
            </div>
          </div>

          <!-- 2. Manual Dropdown Selection -->
          <div class="space-y-1.5">
            <span class="block text-xs font-bold text-[var(--muted)]">أو اختر كتاباً يدوياً من القائمة:</span>
            <div class="flex gap-2">
              <div class="flex-1">
                <SearchableSelect
                  v-model="productToAdd"
                  :options="unselectedProductOptions"
                  :paginate="10"
                  placeholder="-- اختر كتاباً أو صنفاً لإضافته لنطاق الجرد --"
                  search-placeholder="ابحث بالاسم أو الباركود..."
                  :clearable="false"
                />
              </div>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 py-2 text-xs font-bold text-[var(--text)] hover:border-[var(--crimson)] hover:text-[var(--crimson)] disabled:opacity-50 cursor-pointer"
                :disabled="!productToAdd"
                @click="addProductToScope"
              >
                <Plus class="h-4 w-4" />
                <span>إضافة</span>
              </button>
            </div>
          </div>
          <p v-if="errors.product_ids" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.product_ids[0] }}</p>

          <!-- 3. List of selected products -->
          <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] overflow-hidden">
            <div class="border-b border-[var(--border)] bg-[var(--card)] px-3.5 py-2 text-xs font-bold text-[var(--muted)] flex items-center justify-between">
              <span>الكتب المختارة للجرد ({{ selectedProducts.length }}):</span>
              <button
                v-if="selectedProducts.length > 0"
                type="button"
                class="text-[11px] text-red-500 hover:underline cursor-pointer"
                @click="form.product_ids = []"
              >
                مسح الكل
              </button>
            </div>
            <div class="max-h-48 overflow-y-auto divide-y divide-[var(--border)]">
              <div v-if="selectedProducts.length === 0" class="p-4 text-center text-xs text-[var(--muted)]">
                لم تقم بإضافة أية كتب بعد. امسح الباركود أعلاه أو اختر من القائمة.
              </div>
              <div
                v-for="p in selectedProducts"
                :key="p.id"
                class="flex items-center justify-between px-3.5 py-2.5 text-xs hover:bg-[var(--card)] transition-colors"
              >
                <div>
                  <span class="font-extrabold text-[var(--text)]">{{ p.name }}</span>
                </div>
                <button
                  type="button"
                  class="rounded-lg p-1.5 text-red-500 hover:bg-red-500/10 transition-colors cursor-pointer"
                  title="إزالة من القائمة"
                  @click="removeProductFromScope(p.id)"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Date & Notes -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
              {{ $t('stocktaking.stocktakeDate') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <input
              v-model="form.stocktake_date"
              type="date"
              required
              class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-sm text-[var(--text)] outline-none focus:border-[var(--crimson)]"
            />
            <p v-if="errors.stocktake_date" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.stocktake_date[0] }}</p>
          </div>

          <div>
            <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
              {{ $t('stocktaking.notes') }}
            </label>
            <textarea
              v-model="form.notes"
              rows="2"
              class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-2.5 text-xs text-[var(--text)] outline-none focus:border-[var(--crimson)]"
              placeholder="أية ملاحظات إضافية حول عملية الجرد..."
            />
          </div>
        </div>
      </form>

      <!-- Footer Actions -->
      <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] bg-[var(--bg)] px-6 py-4">
        <button
          type="button"
          class="rounded-xl border border-[var(--border)] px-4 py-2 text-xs font-bold text-[var(--muted)] hover:bg-[var(--card)]"
          @click="emit('close')"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          type="submit"
          form="stocktake-form"
          :disabled="isSubmitting"
          class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--crimson)] bg-[var(--crimson)] px-5 py-2 text-xs font-bold text-white shadow-xs hover:opacity-90 disabled:opacity-50"
        >
          <span v-if="isSubmitting" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
          <span>{{ $t('stocktaking.saveAsDraft') }}</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import {
  AlertCircle,
  Barcode,
  CheckCircle2,
  Loader2,
  Plus,
  Trash2,
  X,
} from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import api from '../../services/api';

const props = defineProps({
  open: { type: Boolean, default: false },
  warehouses: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'created']);

const isSubmitting = ref(false);
const submitError = ref('');
const errors = ref({});
const productToAdd = ref(null);

// Barcode scanner state
const barcodeInput = ref('');
const barcodeFeedback = ref(null);
const barcodeInputRef = ref(null);
const isSearchingBarcode = ref(false);

// Local fallback products cache
const localProducts = ref([]);
const loadingProducts = ref(false);

const allProducts = computed(() => {
  if (localProducts.value.length > 0) return localProducts.value;
  return props.products || [];
});

async function loadProductsIfNeeded() {
  if (allProducts.value.length > 0) return;
  loadingProducts.value = true;
  try {
    const res = await api.get('/products', { params: { per_page: 500 } });
    localProducts.value = res.data?.data || [];
  } catch (e) {
    console.error('Failed to load products in modal:', e);
  } finally {
    loadingProducts.value = false;
  }
}

const form = reactive({
  warehouse_id: null,
  scope: 'full',
  stocktake_date: new Date().toISOString().substring(0, 10),
  product_ids: [],
  notes: '',
});

const warehouseOptions = computed(() =>
  props.warehouses.map(w => ({
    value: w.id,
    label: `${w.name} (${w.code})${w.is_default ? ' ★ الافتراضي' : ''}`,
  }))
);

function syncDefaultWarehouse() {
  const def = props.warehouses.find(w => w.is_default);
  if (def && (!form.warehouse_id || props.warehouses.length <= 1)) {
    form.warehouse_id = def.id;
  }
}

const selectedProducts = computed(() =>
  allProducts.value.filter(p => form.product_ids.includes(p.id))
);

const unselectedProductOptions = computed(() =>
  allProducts.value
    .filter(p => !form.product_ids.includes(p.id))
    .map(p => ({
      value: p.id,
      label: p.name,
      barcode: p.barcode,
    }))
);

function addProductToScope() {
  if (productToAdd.value && !form.product_ids.includes(productToAdd.value)) {
    form.product_ids.push(productToAdd.value);
    productToAdd.value = null;
  }
}

function removeProductFromScope(id) {
  form.product_ids = form.product_ids.filter(pId => pId !== id);
}

async function handleBarcodeSubmit() {
  const code = barcodeInput.value?.trim();
  if (!code) return;

  barcodeFeedback.value = null;

  // 1. Check if already added
  const alreadyAdded = allProducts.value.find(
    p => form.product_ids.includes(p.id) && p.barcode === code
  );
  if (alreadyAdded) {
    barcodeFeedback.value = {
      type: 'warning',
      message: `الصنف «${alreadyAdded.name}» مضاف بالفعل في قائمة الجرد.`,
    };
    barcodeInput.value = '';
    return;
  }

  // 2. Check local loaded products list
  let product = allProducts.value.find(p => p.barcode === code);

  // 3. If not found locally, query backend lookup endpoint
  if (!product) {
    isSearchingBarcode.value = true;
    try {
      const res = await api.get(`/products/lookup-barcode/${encodeURIComponent(code)}`);
      if (res.data?.data) {
        product = res.data.data;
        if (!allProducts.value.some(p => p.id === product.id)) {
          localProducts.value.push(product);
        }
      }
    } catch (err) {
      // not found
    } finally {
      isSearchingBarcode.value = false;
    }
  }

  // 4. Handle result
  if (product) {
    if (!form.product_ids.includes(product.id)) {
      form.product_ids.push(product.id);
      barcodeFeedback.value = {
        type: 'success',
        message: `تم العثور على: «${product.name}» وإضافته بنجاح!`,
      };
    }
    barcodeInput.value = '';
    setTimeout(() => {
      if (barcodeFeedback.value?.type === 'success') {
        barcodeFeedback.value = null;
      }
    }, 3500);
  } else {
    barcodeFeedback.value = {
      type: 'error',
      message: `لم يتم العثور على أي كتاب مسجل بالباركود: ${code}`,
    };
  }

  nextTick(() => {
    barcodeInputRef.value?.focus();
  });
}

watch(
  [() => props.open, () => props.warehouses],
  ([isOpen]) => {
    if (isOpen) {
      errors.value = {};
      submitError.value = '';
      productToAdd.value = null;
      barcodeInput.value = '';
      barcodeFeedback.value = null;
      form.scope = 'full';
      form.stocktake_date = new Date().toISOString().substring(0, 10);
      form.product_ids = [];
      form.notes = '';
      syncDefaultWarehouse();
      loadProductsIfNeeded();
    }
  },
  { immediate: true }
);

async function handleSubmit() {
  errors.value = {};
  submitError.value = '';

  if (!form.warehouse_id) {
    syncDefaultWarehouse();
  }

  if (form.scope === 'selected' && form.product_ids.length === 0) {
    errors.value = { product_ids: ['يجب اختيار كتاب أو صنف واحد على الأقل للجرد المحدد.'] };
    return;
  }

  isSubmitting.value = true;
  try {
    const payload = {
      warehouse_id: form.warehouse_id,
      scope: form.scope,
      stocktake_date: form.stocktake_date,
      notes: form.notes || null,
      product_ids: form.scope === 'selected' ? form.product_ids : undefined,
    };

    const res = await api.post('/stocktakes', payload);
    emit('created', res.data.data);
    emit('close');
  } catch (err) {
    if (err.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
    } else {
      submitError.value = err.response?.data?.message || 'حدث خطأ أثناء إنشاء مستند الجرد.';
    }
  } finally {
    isSubmitting.value = false;
  }
}
</script>
