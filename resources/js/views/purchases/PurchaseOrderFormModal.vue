<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 md:p-6 overflow-y-auto bg-black/60 backdrop-blur-xs"
    @click.self="emit('close')"
  >
    <div
      class="relative w-full max-w-4xl rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl transition-all my-auto"
      :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
    >
      <!-- Header -->
      <div class="flex items-start justify-between border-b border-[var(--border)] px-6 py-4">
        <div>
          <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl">
            {{ isEdit ? $t('purchaseOrders.editTitle') : $t('purchaseOrders.createTitle') }}
          </h2>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            {{ $t('purchaseOrders.createSubtitle') }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-xl p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <!-- Form Body -->
      <form @submit.prevent="submitForm">
        <div class="max-h-[75vh] space-y-5 overflow-y-auto p-6 custom-scrollbar">
          <!-- Error banner -->
          <div
            v-if="submitError"
            class="flex items-center gap-2 rounded-xl border border-[var(--crimson)]/20 bg-[var(--crimson-soft)] px-4 py-2.5 text-xs font-bold text-[var(--crimson)]"
          >
            <AlertCircle class="h-4 w-4 shrink-0" />
            <span>{{ submitError }}</span>
          </div>

          <!-- Top Row: Supplier, Order Date, Delivery Date (3-columns) -->
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <!-- Supplier -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ $t('purchaseOrders.supplier') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.supplier_id"
                :options="supplierOptions"
                :paginate="10"
                :placeholder="$t('purchaseOrders.selectSupplier')"
                :clearable="false"
              />
              <p v-if="errors.supplier_id" class="mt-1 text-xs text-[var(--crimson)]">
                {{ errors.supplier_id[0] }}
              </p>
            </div>

            <!-- Order Date -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ $t('purchaseOrders.orderDate') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.order_date"
                type="date"
                required
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
              />
              <p v-if="errors.order_date" class="mt-1 text-xs text-[var(--crimson)]">
                {{ errors.order_date[0] }}
              </p>
            </div>

            <!-- Expected Delivery Date -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ $t('purchaseOrders.deliveryDate') }}
              </label>
              <input
                v-model="form.expected_delivery_date"
                type="date"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
              />
              <p v-if="errors.expected_delivery_date" class="mt-1 text-xs text-[var(--crimson)]">
                {{ errors.expected_delivery_date[0] }}
              </p>
            </div>
          </div>

          <!-- Line Items Section -->
          <div class="space-y-3">
            <div class="flex items-center justify-between border-b border-[var(--border)] pb-2">
              <h3 class="text-sm font-extrabold text-[var(--text)]">
                {{ $t('purchaseOrders.items') }} <span class="text-[var(--crimson)]">*</span>
              </h3>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] px-3 py-1.5 text-xs font-bold text-[var(--crimson)] transition-colors hover:bg-[var(--crimson-soft)]/80"
                @click="addItemRow"
              >
                <Plus class="h-3.5 w-3.5" />
                <span>{{ $t('purchaseOrders.addItem') }}</span>
              </button>
            </div>

            <p v-if="errors.items" class="text-xs text-[var(--crimson)]">{{ errors.items[0] }}</p>

            <!-- Items Table -->
            <div class="rounded-xl border border-[var(--border)] overflow-visible">
              <table class="w-full table-fixed text-right text-xs">
                <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
                  <tr>
                    <th class="w-[4%] px-1 py-2.5 text-center">#</th>
                    <th class="w-[36%] px-2.5 py-2.5">{{ $t('purchaseOrders.product') }}</th>
                    <th class="w-[11%] px-1 py-2.5 text-center">{{ $t('purchaseOrders.quantity') }}</th>
                    <th class="w-[13%] px-1 py-2.5 text-center">{{ $t('purchaseOrders.unitPrice') }}</th>
                    <th class="w-[21%] px-1 py-2.5 text-center">{{ $t('purchaseOrders.discount') }}</th>
                    <th class="w-[11%] px-2 py-2.5 text-left">{{ $t('purchaseOrders.lineTotal') }}</th>
                    <th class="w-[4%] px-1 py-2.5 text-center"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr v-for="(item, idx) in form.items" :key="idx" class="align-middle transition-colors hover:bg-[var(--bg)]/40">
                    <td class="px-1 py-2.5 text-center font-bold text-[var(--muted)]">{{ idx + 1 }}</td>

                    <!-- Product Select -->
                    <td class="px-2 py-2.5 min-w-0">
                      <SearchableSelect
                        v-model="item.product_id"
                        :options="productOptions"
                        :paginate="10"
                        placement="top"
                        :placeholder="$t('purchaseOrders.selectProduct')"
                        :clearable="false"
                        @update:model-value="onProductSelected(item)"
                      />
                      <p v-if="errors[`items.${idx}.product_id`]" class="mt-1 text-[11px] text-[var(--crimson)] truncate">
                        {{ errors[`items.${idx}.product_id`][0] }}
                      </p>
                    </td>

                    <!-- Quantity -->
                    <td class="px-1 py-2.5 text-center">
                      <input
                        v-model.number="item.quantity"
                        type="number"
                        step="any"
                        min="0.001"
                        required
                        class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-1 text-center text-xs font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-1 focus:ring-[var(--crimson-soft)]"
                      />
                      <p v-if="errors[`items.${idx}.quantity`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                        {{ errors[`items.${idx}.quantity`][0] }}
                      </p>
                    </td>

                    <!-- Unit Price -->
                    <td class="px-1 py-2.5 text-center">
                      <input
                        v-model.number="item.unit_price"
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-1 text-center text-xs font-mono font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-1 focus:ring-[var(--crimson-soft)]"
                      />
                      <p v-if="errors[`items.${idx}.unit_price`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                        {{ errors[`items.${idx}.unit_price`][0] }}
                      </p>
                    </td>

                    <!-- Discount (Value + Segmented Toggle [% | ج.م]) -->
                    <td class="px-1 py-2.5 text-center">
                      <div class="flex items-center justify-center gap-1">
                        <input
                          v-model.number="item.discount_value"
                          type="number"
                          step="0.01"
                          min="0"
                          :max="item.discount_type === 'percentage' ? 100 : undefined"
                          class="h-9 w-14 rounded-lg border border-[var(--border)] bg-[var(--card)] px-1 text-center text-xs font-mono font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-1 focus:ring-[var(--crimson-soft)]"
                          placeholder="0"
                        />
                        <div class="inline-flex rounded-lg border border-[var(--border)] bg-[var(--bg)] p-0.5 text-[11px] font-bold shrink-0">
                          <button
                            type="button"
                            class="px-1.5 py-1 rounded-md transition-colors"
                            :class="item.discount_type === 'percentage' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                            @click="item.discount_type = 'percentage'"
                          >
                            %
                          </button>
                          <button
                            type="button"
                            class="px-1.5 py-1 rounded-md transition-colors"
                            :class="item.discount_type === 'fixed' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                            @click="item.discount_type = 'fixed'"
                          >
                            ج.م
                          </button>
                        </div>
                      </div>
                    </td>

                    <!-- Line Total -->
                    <td class="px-2 py-2.5 text-left font-bold font-mono text-xs text-[var(--text)] whitespace-nowrap">
                      {{ formatMoney(calcLineTotal(item)) }} <span class="text-[10px] font-normal text-[var(--muted)]">ج.م</span>
                    </td>

                    <!-- Remove Line -->
                    <td class="px-1 py-2.5 text-center">
                      <button
                        type="button"
                        class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 dark:hover:text-rose-400 disabled:opacity-30 transition-colors"
                        :disabled="form.items.length <= 1"
                        @click="removeItemRow(idx)"
                      >
                        <Trash2 class="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Notes & File Attachment Section (Placed before Price & Discount) -->
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 pt-2 border-t border-[var(--border)]">
            <!-- Notes (Moved to end) -->
            <div class="space-y-1.5">
              <label class="block text-xs font-bold text-[var(--text)]">
                {{ $t('purchaseOrders.notes') }}
              </label>
              <textarea
                v-model="form.notes"
                rows="3"
                class="w-full h-[98px] rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-xs text-[var(--text)] outline-none transition placeholder:text-[var(--muted)] focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)] resize-none"
                :placeholder="$t('purchaseOrders.notesPlaceholder')"
              />
            </div>

            <!-- File Attachment Placeholder -->
            <div class="space-y-1.5">
              <div class="flex items-center justify-between">
                <label class="block text-xs font-bold text-[var(--text)]">
                  مرفق أمر الشراء
                </label>
                <span class="text-[10px] text-[var(--muted)] font-normal">(اختياري)</span>
              </div>

              <div
                class="group relative flex h-[98px] cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-[var(--border)] bg-[var(--bg)]/50 px-4 text-center transition-colors hover:border-[var(--crimson)] hover:bg-[var(--card)]"
                @click="triggerFileInput"
              >
                <input
                  ref="fileInputRef"
                  type="file"
                  class="hidden"
                  accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
                  @change="handleFileSelected"
                />

                <div v-if="!selectedFileName" class="flex flex-col items-center justify-center">
                  <UploadCloud class="h-6 w-6 text-[var(--muted)] transition-colors group-hover:text-[var(--crimson)] mb-1" />
                  <p class="text-xs font-bold text-[var(--text)]">
                    انقر لإرفاق ملف أو اسحبه هنا
                  </p>
                  <p class="text-[10px] text-[var(--muted)] mt-0.5">
                    PDF, PNG, JPG, DOCX (حتى 10MB)
                  </p>
                </div>

                <div v-else class="flex items-center gap-2 max-w-full px-2" @click.stop>
                  <FileText class="h-6 w-6 text-[var(--crimson)] shrink-0" />
                  <div class="text-right truncate flex-1 min-w-0">
                    <p class="text-xs font-bold text-[var(--text)] truncate">{{ selectedFileName }}</p>
                    <p class="text-[10px] text-emerald-600 font-semibold">جاهز للإرفاق</p>
                  </div>
                  <button
                    type="button"
                    class="rounded-lg p-1 text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 transition-colors mr-2 shrink-0"
                    title="حذف الملف"
                    @click="removeSelectedFile"
                  >
                    <X class="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Bottom Summary & Order Discount Section -->
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 pt-2 border-t border-[var(--border)]">
            <!-- Order-Level Discount (Right in RTL) -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 space-y-3">
              <div class="text-xs font-extrabold text-[var(--text)]">
                {{ $t('purchaseOrders.orderDiscount') }}
              </div>

              <div class="space-y-1.5">
                <label class="block text-[11px] font-bold text-[var(--muted)]">
                  قيمة الخصم
                </label>
                <div class="flex items-center gap-2">
                  <input
                    v-model.number="form.discount_value"
                    type="number"
                    step="0.01"
                    min="0"
                    :max="form.discount_type === 'percentage' ? 100 : undefined"
                    class="h-10 w-28 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 text-center text-sm font-mono font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
                    placeholder="0"
                  />
                  <div class="inline-flex rounded-xl border border-[var(--border)] bg-[var(--card)] p-0.5 text-xs font-bold shrink-0">
                    <button
                      type="button"
                      class="px-3 py-1.5 rounded-lg transition-colors"
                      :class="form.discount_type === 'percentage' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                      @click="form.discount_type = 'percentage'"
                    >
                      %
                    </button>
                    <button
                      type="button"
                      class="px-3 py-1.5 rounded-lg transition-colors"
                      :class="form.discount_type === 'fixed' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                      @click="form.discount_type = 'fixed'"
                    >
                      ج.م
                    </button>
                  </div>
                </div>
                <div class="flex items-center gap-1.5 text-xs text-[var(--muted)] pt-1">
                  <RotateCcw class="h-3 w-3 text-[var(--muted)]" />
                  <span>خصمت {{ formatMoney(calcOrderDiscountAmount) }} ج.م</span>
                </div>
              </div>
            </div>

            <!-- Grand Totals & Financial Breakdown (Left in RTL) -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 space-y-2.5">
              <!-- Subtotal before order discount -->
              <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-[var(--muted)]">الإجمالي قبل الخصم</span>
                <span class="font-mono font-bold text-[var(--text)]">{{ formatMoney(calcGrossTotal) }} ج.م</span>
              </div>

              <!-- Order Discount line -->
              <div
                v-if="calcOrderDiscountAmount > 0"
                class="flex items-center justify-between text-xs text-rose-600 dark:text-rose-400"
              >
                <span class="font-bold">
                  {{ $t('purchaseOrders.orderDiscount') }}
                  <span class="text-[11px] font-normal">({{ form.discount_type === 'percentage' ? '%' + (form.discount_value || 0) : 'مبلغ ثابت' }})</span>
                </span>
                <span class="font-mono font-bold">- {{ formatMoney(calcOrderDiscountAmount) }} ج.م</span>
              </div>

              <div class="border-t border-[var(--border)] my-1"></div>

              <!-- Subtotal after order discount -->
              <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-[var(--muted)]">الإجمالي بعد الخصم</span>
                <span class="font-mono font-bold text-[var(--text)]">{{ formatMoney(calcOrderGrandTotal) }} ج.م</span>
              </div>

              <div class="border-t border-[var(--border)] my-1"></div>

              <!-- Grand Final Total (Prominent) -->
              <div class="flex items-baseline justify-between pt-1">
                <span class="text-base font-extrabold text-[var(--text)]">{{ $t('purchaseOrders.total') }}</span>
                <div class="flex items-baseline gap-1">
                  <span class="text-2xl font-black font-mono text-[var(--text)]">{{ formatMoney(calcOrderGrandTotal) }}</span>
                  <span class="text-xs font-bold text-[var(--muted)]">ج.م</span>
                </div>
              </div>

              <!-- Total Savings Pill (Green Badge) -->
              <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-300 border border-emerald-200/50 dark:border-emerald-800/30">
                <span>إجمالي التوفير</span>
                <span class="font-mono">{{ formatMoney(totalSavings) }} ج.م <span v-if="savingsPercent > 0">({{ savingsPercent }}%)</span></span>
              </div>

              <!-- Items & Quantity Summary -->
              <div class="text-center text-[11px] text-[var(--muted)] pt-1">
                {{ form.items.length }} أصناف • إجمالي الكميات {{ totalQuantity }}
              </div>
            </div>
          </div>
        </div>

        <!-- Footer Actions -->
        <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] bg-[var(--bg)] px-6 py-4">
          <button
            type="button"
            class="rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 py-2 text-xs font-bold text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
            @click="emit('close')"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="submit"
            class="inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2 text-xs font-bold text-white shadow-sm shadow-[var(--crimson)]/20 hover:bg-[var(--crimson-hover)] disabled:opacity-50"
            :disabled="submitting"
          >
            <span v-if="submitting" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
            <span>{{ isEdit ? $t('common.saveChanges') : $t('common.save') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import { X, Plus, Trash2, AlertCircle, RotateCcw, UploadCloud, FileText } from 'lucide-vue-next';

const props = defineProps({
  open: { type: Boolean, default: false },
  order: { type: Object, default: null },
  suppliers: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();

const isEdit = computed(() => !!props.order?.id);
const submitting = ref(false);
const submitError = ref('');
const errors = ref({});

// File Attachment (UI placeholder for future attachments package)
const fileInputRef = ref(null);
const selectedFileName = ref('');

function triggerFileInput() {
  fileInputRef.value?.click();
}

function handleFileSelected(event) {
  const file = event.target.files?.[0];
  if (file) {
    selectedFileName.value = file.name;
  }
}

function removeSelectedFile() {
  selectedFileName.value = '';
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
  }
}

const form = reactive({
  supplier_id: null,
  order_date: new Date().toISOString().substring(0, 10),
  expected_delivery_date: null,
  notes: '',
  discount_type: 'percentage',
  discount_value: 0,
  items: [
    { product_id: null, quantity: 1, unit_price: 0, discount_type: 'percentage', discount_value: 0 },
  ],
});

// Map suppliers to options
const supplierOptions = computed(() => {
  return props.suppliers.map((s) => ({
    value: s.id,
    label: s.name,
  }));
});

// Map products to options
const productOptions = computed(() => {
  return props.products.map((p) => ({
    value: p.id,
    label: p.name,
    barcode: p.barcode,
  }));
});

// Handle product selection to prefill purchase_price
function onProductSelected(item) {
  if (!item.product_id) return;
  const prod = props.products.find((p) => p.id === item.product_id);
  if (prod && (!item.unit_price || item.unit_price === 0)) {
    item.unit_price = Number(prod.purchase_price) || 0;
  }
}

function addItemRow() {
  form.items.push({
    product_id: null,
    quantity: 1,
    unit_price: 0,
    discount_type: 'percentage',
    discount_value: 0,
  });
}

function removeItemRow(index) {
  if (form.items.length > 1) {
    form.items.splice(index, 1);
  }
}

// Calculations
function calcLineGross(item) {
  const qty = Number(item.quantity) || 0;
  const price = Number(item.unit_price) || 0;
  return qty * price;
}

function calcLineDiscount(item) {
  const gross = calcLineGross(item);
  const val = Number(item.discount_value) || 0;
  if (val <= 0) return 0;
  if (item.discount_type === 'percentage') {
    const pct = Math.min(100, Math.max(0, val));
    return (gross * pct) / 100;
  }
  return Math.min(gross, Math.max(0, val));
}

function calcLineTotal(item) {
  return Math.max(0, calcLineGross(item) - calcLineDiscount(item));
}

const calcGrossTotal = computed(() => {
  return form.items.reduce((sum, item) => sum + calcLineGross(item), 0);
});

const calcItemDiscountsTotal = computed(() => {
  return form.items.reduce((sum, item) => sum + calcLineDiscount(item), 0);
});

const calcOrderSubtotal = computed(() => {
  return form.items.reduce((sum, item) => sum + calcLineTotal(item), 0);
});

const calcOrderDiscountAmount = computed(() => {
  const sub = calcOrderSubtotal.value;
  const val = Number(form.discount_value) || 0;
  if (val <= 0) return 0;
  if (form.discount_type === 'percentage') {
    const pct = Math.min(100, Math.max(0, val));
    return (sub * pct) / 100;
  }
  return Math.min(sub, Math.max(0, val));
});

const calcOrderGrandTotal = computed(() => {
  return Math.max(0, calcOrderSubtotal.value - calcOrderDiscountAmount.value);
});

const totalSavings = computed(() => {
  return calcItemDiscountsTotal.value + calcOrderDiscountAmount.value;
});

const savingsPercent = computed(() => {
  if (calcGrossTotal.value <= 0) return 0;
  const pct = (totalSavings.value / calcGrossTotal.value) * 100;
  return Math.round(pct);
});

const totalQuantity = computed(() => {
  const total = form.items.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
  return Number.isInteger(total) ? total : total.toFixed(2);
});

function formatMoney(amount) {
  const num = Number(amount) || 0;
  return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Reset / Initialize on open or order change
watch(
  () => props.open,
  (val) => {
    if (val) {
      initForm();
    }
  },
  { immediate: true }
);

function initForm() {
  submitError.value = '';
  errors.value = {};
  selectedFileName.value = '';
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
  }

  if (props.order && props.order.id) {
    form.supplier_id = props.order.supplier_id;
    form.order_date = props.order.order_date ? String(props.order.order_date).substring(0, 10) : new Date().toISOString().substring(0, 10);
    form.expected_delivery_date = props.order.expected_delivery_date ? String(props.order.expected_delivery_date).substring(0, 10) : null;
    form.notes = props.order.notes || '';
    form.discount_type = props.order.discount_type || 'percentage';
    form.discount_value = Number(props.order.discount_value) || 0;

    if (props.order.items && props.order.items.length > 0) {
      form.items = props.order.items.map((i) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity) || 1,
        unit_price: Number(i.unit_price) || 0,
        discount_type: i.discount_type || 'percentage',
        discount_value: Number(i.discount_value) || 0,
      }));
    } else {
      form.items = [{ product_id: null, quantity: 1, unit_price: 0, discount_type: 'percentage', discount_value: 0 }];
    }
  } else {
    form.supplier_id = null;
    form.order_date = new Date().toISOString().substring(0, 10);
    form.expected_delivery_date = null;
    form.notes = '';
    form.discount_type = 'percentage';
    form.discount_value = 0;
    form.items = [{ product_id: null, quantity: 1, unit_price: 0, discount_type: 'percentage', discount_value: 0 }];
  }
}

async function submitForm() {
  submitError.value = '';
  errors.value = {};

  // Validate items
  const validItems = form.items.filter((i) => i.product_id && Number(i.quantity) > 0);
  if (validItems.length === 0) {
    submitError.value = 'يجب اختيار صنف واحد على الأقل وتحديد كميته.';
    return;
  }

  submitting.value = true;

  const orderDiscVal = Number(form.discount_value) || 0;
  const hasOrderDisc = orderDiscVal > 0;

  const payload = {
    supplier_id: form.supplier_id,
    order_date: form.order_date,
    expected_delivery_date: form.expected_delivery_date || null,
    notes: form.notes || null,
    discount_type: hasOrderDisc ? form.discount_type : null,
    discount_value: hasOrderDisc ? orderDiscVal : null,
    items: validItems.map((i) => {
      const itemDiscVal = Number(i.discount_value) || 0;
      const hasItemDisc = itemDiscVal > 0;
      return {
        product_id: i.product_id,
        quantity: Number(i.quantity),
        unit_price: Number(i.unit_price),
        discount_type: hasItemDisc ? i.discount_type : null,
        discount_value: hasItemDisc ? itemDiscVal : null,
      };
    }),
  };

  try {
    let res;
    if (isEdit.value) {
      res = await api.put(`/purchase-orders/${props.order.id}`, payload);
    } else {
      res = await api.post('/purchase-orders', payload);
    }

    emit('saved', res.data.data);
    emit('close');
  } catch (err) {
    if (err.response?.status === 422) {
      errors.value = err.response.data.errors || {};
      submitError.value = err.response.data.message || 'بيانات النموذج غير مكتملة أو تحتوي على أخطاء.';
    } else {
      submitError.value = err.response?.data?.message || 'حدث خطأ غير متوقع أثناء حفظ أمر الشراء.';
    }
  } finally {
    submitting.value = false;
  }
}
</script>
