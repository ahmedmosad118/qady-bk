<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6"
    role="dialog"
    aria-modal="true"
  >
    <!-- Backdrop -->
    <div
      class="fixed inset-0 bg-black/60 backdrop-blur-sm transition-opacity"
      @click="emit('close')"
    />

    <!-- Modal Box -->
    <div
      class="relative flex max-h-[92vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl"
    >
      <!-- Header -->
      <div class="flex items-center justify-between border-b border-[var(--border)] px-6 py-4">
        <div>
          <h2 class="text-lg font-extrabold text-[var(--text)]">
            {{ isEdit ? $t('inventory.editDocument') : (fixedType ? 'إنشاء ' + typeLabel(fixedType) + ' جديد' : $t('inventory.createDocument')) }}
          </h2>
          <p class="text-xs text-[var(--muted)]">
            {{ isEdit ? document?.document_number : (fixedType ? 'إدخال بيانات وأصناف ' + typeLabel(fixedType) : $t('inventory.createSubtitle')) }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-lg p-2 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <!-- Form Body -->
      <form class="flex flex-1 flex-col overflow-hidden" @submit.prevent="submitForm">
        <div class="flex-1 space-y-6 overflow-y-auto p-6 text-sm text-[var(--text)]">
          <!-- Top alert error if any -->
          <div
            v-if="submitError"
            class="flex items-center gap-2 rounded-xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] p-3 text-sm text-[var(--crimson)]"
          >
            <AlertCircle class="h-5 w-5 shrink-0" />
            <span>{{ submitError }}</span>
          </div>

          <!-- Document Header Fields Grid -->
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- Document Type -->
            <div v-if="!normalizedFixedType && !isEdit">
              <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
                {{ $t('inventory.fields.type') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.document_type"
                :options="documentTypeOptions"
                :disabled="isEdit"
                :clearable="false"
                placeholder="-- اختر نوع المستند --"
                search-placeholder="بحث في أنواع المستندات..."
                @change="onTypeChanged"
              />
              <p v-if="errors.document_type" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.document_type[0] }}</p>
            </div>

            <!-- When fixedType or isEdit: No select, no dropdown, no multiple choices! -->
            <div v-else>
              <label class="block text-xs font-bold text-[var(--muted)] mb-1.5">
                {{ $t('inventory.fields.type') }}
              </label>
              <div
                class="flex h-10 items-center justify-between rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] select-none cursor-default"
              >
                <div class="flex items-center gap-2">
                  <span class="h-2 w-2 rounded-full bg-[var(--crimson)]"></span>
                  <span>{{ typeLabel(form.document_type) }}</span>
                </div>
                <span class="rounded-md bg-[var(--crimson-soft)] px-2 py-0.5 text-[11px] font-bold text-[var(--crimson)]">
                  محدد تلقائياً
                </span>
              </div>
            </div>

            <!-- Document Date -->
            <div>
              <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
                {{ $t('inventory.fields.date') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.document_date"
                type="date"
                required
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs text-[var(--text)] outline-none focus:border-[var(--crimson)]"
              />
              <p v-if="errors.document_date" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.document_date[0] }}</p>
            </div>

            <!-- Direction for Stock Adjustment (SearchableSelect) -->
            <div v-if="form.document_type === 'stock_adjustment'">
              <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
                {{ $t('inventory.fields.adjustmentDirection') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.adjustment_direction"
                :options="adjustmentDirectionOptions"
                :clearable="false"
                placeholder="-- اختر اتجاه التسوية --"
                @change="onDirectionChanged"
              />
              <p v-if="errors.adjustment_direction" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.adjustment_direction[0] }}</p>
            </div>

            <!-- Direction for Inventory Return (SearchableSelect) -->
            <div v-if="form.document_type === 'inventory_return'">
              <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
                {{ $t('inventory.fields.returnDirection') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.return_direction"
                :options="returnDirectionOptions"
                :clearable="false"
                placeholder="-- اختر اتجاه المرتجع --"
                @change="onDirectionChanged"
              />
              <p v-if="errors.return_direction" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.return_direction[0] }}</p>
            </div>
          </div>

          <!-- Warehouses Selectors Row (Only shown if more than 1 active warehouse exists) -->
          <div
            v-if="warehouses.length > 1 && (isSourceRequired || isDestinationRequired)"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2"
          >
            <!-- Source Warehouse (SearchableSelect) -->
            <div v-if="isSourceRequired">
              <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
                {{ $t('inventory.fields.sourceWarehouse') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.source_warehouse_id"
                :options="warehouseOptions"
                placeholder="-- اختر المخزن المصدر --"
                search-placeholder="بحث باسم المخزن أو الكود..."
                :clearable="false"
              />
              <p v-if="errors.source_warehouse_id" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.source_warehouse_id[0] }}</p>
            </div>

            <!-- Destination Warehouse (SearchableSelect) -->
            <div v-if="isDestinationRequired">
              <label class="block text-xs font-bold text-[var(--text)] mb-1.5">
                {{ $t('inventory.fields.destinationWarehouse') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.destination_warehouse_id"
                :options="warehouseOptions"
                placeholder="-- اختر المخزن المستهدف --"
                search-placeholder="بحث باسم المخزن أو الكود..."
                :clearable="false"
              />
              <p v-if="errors.destination_warehouse_id" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.destination_warehouse_id[0] }}</p>
            </div>
          </div>

          <!-- Write-off Reason (Mandatory for stock_write_off) -->
          <div v-if="form.document_type === 'stock_write_off'">
            <label class="block text-xs font-bold text-[var(--text)]">
              {{ $t('inventory.fields.writeOffReason') }} <span class="text-[var(--crimson)]">*</span>
            </label>
            <textarea
              v-model="form.write_off_reason"
              rows="2"
              required
              class="mt-1.5 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-sm outline-none focus:border-[var(--crimson)]"
              :placeholder="$t('inventory.fields.writeOffReasonPlaceholder')"
            />
            <p v-if="errors.write_off_reason" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.write_off_reason[0] }}</p>
          </div>

          <!-- General Notes -->
          <div>
            <label class="block text-xs font-bold text-[var(--text)]">
              {{ $t('inventory.fields.notes') }}
            </label>
            <textarea
              v-model="form.notes"
              rows="2"
              class="mt-1.5 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-sm outline-none focus:border-[var(--crimson)]"
              :placeholder="$t('inventory.fields.notesPlaceholder')"
            />
          </div>

          <!-- Items Section -->
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <div>
                <h3 class="text-sm font-extrabold text-[var(--text)]">
                  {{ $t('inventory.fields.itemsList') }} <span class="text-[var(--crimson)]">*</span>
                </h3>
                <p class="text-xs text-[var(--muted)]">{{ $t('inventory.fields.itemsListHint') }}</p>
              </div>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] px-3 py-1.5 text-xs font-bold text-[var(--crimson)] hover:bg-[var(--crimson-soft)]/80"
                @click="addItemRow"
              >
                <Plus class="h-3.5 w-3.5" />
                <span>{{ $t('inventory.fields.addItem') }}</span>
              </button>
            </div>

            <p v-if="errors.items" class="text-xs text-[var(--crimson)]">{{ errors.items[0] }}</p>

            <div class="overflow-x-auto rounded-xl border border-[var(--border)] min-h-[280px]">
              <table class="w-full text-right text-sm">
                <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
                  <tr>
                    <th class="w-10 px-3 py-2.5 text-center">#</th>
                    <th class="w-[42%] min-w-[240px] px-3 py-2.5">{{ $t('inventory.fields.product') }}</th>
                    <th class="w-36 min-w-[140px] px-3 py-2.5 text-center">{{ $t('inventory.fields.quantity') }}</th>
                    <th class="min-w-[160px] px-3 py-2.5">{{ $t('inventory.fields.lineNotes') }}</th>
                    <th class="w-14 px-3 py-2.5 text-center">حذف</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr v-for="(item, idx) in form.items" :key="idx" class="align-top">
                    <td class="px-3 py-2.5 pt-4 text-center text-xs font-bold text-[var(--muted)]">{{ idx + 1 }}</td>
                    <td class="px-3 py-2.5">
                      <!-- SearchableSelect for Line Item Products with 10 pagination -->
                      <SearchableSelect
                        v-model="item.product_id"
                        :options="availableProductOptions(item.product_id)"
                        :paginate="10"
                        placeholder="-- ابحث أو اختر منتج أو إصدار --"
                        search-placeholder="ابحث باسم الكتاب، المؤلف أو الـ SKU..."
                        :clearable="false"
                      />
                      <p v-if="errors[`items.${idx}.product_id`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                        {{ errors[`items.${idx}.product_id`][0] }}
                      </p>
                    </td>
                    <td class="px-3 py-2.5 text-center">
                      <input
                        v-model.number="item.quantity"
                        type="number"
                        step="any"
                        min="0.001"
                        required
                        class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 text-center text-sm font-black text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)] shadow-xs"
                      />
                      <p v-if="errors[`items.${idx}.quantity`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                        {{ errors[`items.${idx}.quantity`][0] }}
                      </p>
                    </td>
                    <td class="px-3 py-2.5">
                      <input
                        v-model="item.notes"
                        type="text"
                        class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                        :placeholder="$t('inventory.fields.lineNotesPlaceholder')"
                      />
                    </td>
                    <td class="px-2 py-2.5 pt-3.5 text-center">
                      <button
                        type="button"
                        class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-red-500/30 bg-red-500/10 text-red-600 transition-colors hover:bg-red-500/20 hover:text-red-700 shadow-xs cursor-pointer"
                        :title="form.items.length > 1 ? 'حذف هذا السطر' : 'تفريغ السطر'"
                        @click="removeItemRow(idx)"
                      >
                        <Trash2 class="h-4 w-4 text-red-600" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Footer -->
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
            :disabled="submitting"
            class="inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-[var(--crimson-hover)] disabled:opacity-60"
          >
            <Loader2 v-if="submitting" class="h-4 w-4 animate-spin" />
            <Save v-else class="h-4 w-4" />
            <span>{{ isEdit ? $t('common.save') : $t('inventory.saveDraft') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue';
import {
  AlertCircle,
  Building2,
  Loader2,
  Plus,
  Save,
  Trash2,
  X,
} from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import api from '../../services/api';

const props = defineProps({
  open: { type: Boolean, default: false },
  document: { type: Object, default: null },
  fixedType: { type: String, default: '' },
  warehouses: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
});

const normalizedFixedType = computed(() => {
  if (!props.fixedType) return '';
  return props.fixedType.replace(/-/g, '_');
});

function typeLabel(type) {
  if (!type) return 'مستند مخزني';
  const clean = String(type).replace(/-/g, '_');
  const labels = {
    goods_receipt: 'إذن استلام مخزني',
    goods_delivery: 'إذن تسليم مخزني',
    stock_issue: 'إذن صرف داخلي',
    warehouse_transfer: 'إذن تحويل مخزني',
    stock_adjustment: 'إذن تسوية جردية',
    inventory_return: 'إذن مرتجع مخزني',
    stock_write_off: 'إذن إهلاك',
    opening_balance: 'رصيد افتتاحي',
  };
  return labels[clean] || 'مستند مخزني';
}

const emit = defineEmits(['close', 'saved']);

const isEdit = computed(() => !!props.document?.id);
const submitting = ref(false);
const submitError = ref('');
const errors = ref({});

const form = reactive({
  document_type: 'goods_receipt',
  document_date: new Date().toISOString().substring(0, 10),
  source_warehouse_id: null,
  destination_warehouse_id: null,
  adjustment_direction: 'increase',
  return_direction: 'inbound',
  write_off_reason: '',
  notes: '',
  items: [
    { product_id: null, quantity: 1, notes: '' },
  ],
});

// Document Types Options (Only show warehouse_transfer if more than 1 active warehouse!)
const documentTypeOptions = computed(() => {
  const options = [
    { value: 'goods_receipt', label: 'إذن استلام مخزني' },
    { value: 'goods_delivery', label: 'إذن تسليم مخزني' },
    { value: 'stock_issue', label: 'إذن صرف داخلي' },
  ];

  if (props.warehouses.length > 1) {
    options.push({ value: 'warehouse_transfer', label: 'إذن تحويل مخزني' });
  }

  options.push(
    { value: 'stock_adjustment', label: 'إذن تسوية جردية' },
    { value: 'inventory_return', label: 'إذن مرتجع مخزني' },
    { value: 'stock_write_off', label: 'إذن إتلاف / هالك' },
    { value: 'opening_balance', label: 'رصيد افتتاحي' },
  );

  return options;
});

// Warehouse Options for SearchableSelect
const warehouseOptions = computed(() => {
  return props.warehouses.map(w => ({
    value: w.id,
    label: `${w.name} (${w.code})${w.is_default ? ' ★ الافتراضي' : ''}`,
  }));
});

// Directions Options
const adjustmentDirectionOptions = [
  { value: 'increase', label: 'تسوية بالزيادة (إضافة للمخزن)' },
  { value: 'decrease', label: 'تسوية بالعجز (خصم من المخزن)' },
];

const returnDirectionOptions = [
  { value: 'inbound', label: 'مرتجع وارد (إلى المخزن)' },
  { value: 'outbound', label: 'مرتجع صادر (من المخزن)' },
];

// Computed properties for conditional warehouse fields
const isSourceRequired = computed(() => {
  const t = form.document_type;
  if (['goods_delivery', 'stock_issue', 'warehouse_transfer', 'stock_write_off'].includes(t)) {
    return true;
  }
  if (t === 'stock_adjustment' && form.adjustment_direction === 'decrease') {
    return true;
  }
  if (t === 'inventory_return' && form.return_direction === 'outbound') {
    return true;
  }
  return false;
});

const isDestinationRequired = computed(() => {
  const t = form.document_type;
  if (['goods_receipt', 'warehouse_transfer', 'opening_balance'].includes(t)) {
    return true;
  }
  if (t === 'stock_adjustment' && form.adjustment_direction === 'increase') {
    return true;
  }
  if (t === 'inventory_return' && form.return_direction === 'inbound') {
    return true;
  }
  return false;
});

function onTypeChanged() {
  // If warehouse transfer is selected but only 1 warehouse exists, fallback
  if (form.document_type === 'warehouse_transfer' && props.warehouses.length <= 1) {
    form.document_type = 'goods_receipt';
  }

  form.source_warehouse_id = null;
  form.destination_warehouse_id = null;

  // Auto-set default warehouse where applicable
  const def = props.warehouses.find(w => w.is_default) || props.warehouses[0];
  if (def) {
    if (isDestinationRequired.value && !isSourceRequired.value) {
      form.destination_warehouse_id = def.id;
    } else if (isSourceRequired.value && !isDestinationRequired.value) {
      form.source_warehouse_id = def.id;
    } else if (props.warehouses.length <= 1) {
      if (isDestinationRequired.value) form.destination_warehouse_id = def.id;
      if (isSourceRequired.value) form.source_warehouse_id = def.id;
    }
  }
}

function onDirectionChanged() {
  if (form.document_type === 'stock_adjustment') {
    if (form.adjustment_direction === 'increase') {
      form.source_warehouse_id = null;
      if (props.warehouses[0]) form.destination_warehouse_id = props.warehouses[0].id;
    } else {
      form.destination_warehouse_id = null;
      if (props.warehouses[0]) form.source_warehouse_id = props.warehouses[0].id;
    }
  } else if (form.document_type === 'inventory_return') {
    if (form.return_direction === 'inbound') {
      form.source_warehouse_id = null;
      if (props.warehouses[0]) form.destination_warehouse_id = props.warehouses[0].id;
    } else {
      form.destination_warehouse_id = null;
      if (props.warehouses[0]) form.source_warehouse_id = props.warehouses[0].id;
    }
  }
}

function addItemRow() {
  form.items.push({ product_id: null, quantity: 1, notes: '' });
}

function removeItemRow(index) {
  if (form.items.length > 1) {
    form.items.splice(index, 1);
  } else {
    form.items[0] = { product_id: null, quantity: 1, notes: '' };
  }
}

function availableProductOptions(currentProductId) {
  const selectedOtherIds = form.items
    .map(i => i.product_id)
    .filter(id => id && id !== currentProductId);

  return props.products
    .filter(p => !selectedOtherIds.includes(p.id))
    .map(p => ({
      value: p.id,
      label: `${p.name} — ${p.author ? p.author + ' ' : ''}(${p.sku})`,
    }));
}

// Watch when modal opens or document changes
watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      errors.value = {};
      submitError.value = '';

      if (props.document) {
        form.document_type = props.document.document_type;
        form.document_date = props.document.document_date ? props.document.document_date.substring(0, 10) : new Date().toISOString().substring(0, 10);
        form.source_warehouse_id = props.document.source_warehouse_id || null;
        form.destination_warehouse_id = props.document.destination_warehouse_id || null;
        form.adjustment_direction = props.document.adjustment_direction || 'increase';
        form.return_direction = props.document.return_direction || 'inbound';
        form.write_off_reason = props.document.write_off_reason || '';
        form.notes = props.document.notes || '';
        form.items = props.document.items && props.document.items.length > 0
          ? props.document.items.map(item => ({
              product_id: item.product_id,
              quantity: Number(item.quantity),
              notes: item.notes || '',
            }))
          : [{ product_id: null, quantity: 1, notes: '' }];
      } else {
        // Reset to initial new document
        form.document_type = normalizedFixedType.value || 'goods_receipt';
        form.document_date = new Date().toISOString().substring(0, 10);
        form.adjustment_direction = 'increase';
        form.return_direction = 'inbound';
        form.write_off_reason = '';
        form.notes = '';
        form.items = [{ product_id: null, quantity: 1, notes: '' }];
        onTypeChanged();
      }
    }
  },
  { immediate: true }
);

async function submitForm() {
  submitting.value = true;
  submitError.value = '';
  errors.value = {};

  const defWarehouse = props.warehouses[0]?.id || null;

  const payload = {
    document_type: form.document_type,
    document_date: form.document_date,
    notes: form.notes || null,
    items: form.items.map(item => ({
      product_id: item.product_id,
      quantity: Number(item.quantity),
      notes: item.notes || null,
    })),
  };

  if (isSourceRequired.value) {
    payload.source_warehouse_id = (props.warehouses.length <= 1) ? defWarehouse : form.source_warehouse_id;
  }
  if (isDestinationRequired.value) {
    payload.destination_warehouse_id = (props.warehouses.length <= 1) ? defWarehouse : form.destination_warehouse_id;
  }
  if (form.document_type === 'stock_adjustment') {
    payload.adjustment_direction = form.adjustment_direction;
  }
  if (form.document_type === 'inventory_return') {
    payload.return_direction = form.return_direction;
  }
  if (form.document_type === 'stock_write_off') {
    payload.write_off_reason = form.write_off_reason;
  }

  try {
    let res;
    if (isEdit.value) {
      res = await api.put(`/inventory-documents/${props.document.id}`, payload);
    } else {
      res = await api.post('/inventory-documents', payload);
    }

    emit('saved', res.data?.data);
  } catch (err) {
    if (err?.response?.status === 422 && err.response.data?.errors) {
      errors.value = err.response.data.errors;
      submitError.value = 'يرجى مراجعة الأخطاء الموضحة أدناه.';
    } else {
      submitError.value = err?.response?.data?.message || 'تعذر حفظ المستند المخزني';
    }
  } finally {
    submitting.value = false;
  }
}
</script>
