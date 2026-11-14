<template>
  <aside class="flex flex-col h-full bg-[var(--card)] border-r border-[var(--border)] select-none min-h-0" dir="rtl">
    <!-- Cart Header -->
    <div class="p-3.5 border-b border-[var(--border)] flex flex-col gap-2.5 bg-[var(--card)] flex-none">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-black flex items-center gap-1.5 text-[var(--text)]">
          <ShoppingBag class="w-5 h-5 text-[var(--crimson)]" />
          <span>سلة البيع</span>
          <span v-if="posStore.cartLinesCount > 0" class="text-xs font-bold text-[var(--muted)]">
            ({{ posStore.cartLinesCount }} صنف · {{ posStore.cartTotalPieces }} قطعة)
          </span>
        </h2>

        <button
          v-if="posStore.cartLinesCount > 0"
          type="button"
          class="h-8 px-2.5 rounded-lg border border-[var(--border)] text-xs font-bold text-[var(--muted)] hover:text-red-600 hover:border-red-300 transition"
          @click="confirmClearCart"
        >
          تفريغ
        </button>
      </div>

      <!-- Customer Row -->
      <div class="flex items-center gap-2">
        <select
          v-model="selectedCustomerId"
          class="flex-1 h-9 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold px-2.5 outline-none focus:border-[var(--crimson)]"
          @change="onCustomerChange"
        >
          <option :value="null">عميل نقدي (Walk-in)</option>
          <option
            v-for="c in posStore.customers"
            :key="c.id"
            :value="c.id"
          >
            {{ c.name }}{{ c.phone ? ` · ${c.phone}` : '' }}
          </option>
        </select>

        <button
          type="button"
          class="h-9 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1 flex-none"
          title="إضافة عميل جديد"
          @click="$emit('open-add-customer')"
        >
          <UserPlus class="w-3.5 h-3.5 text-[var(--crimson)]" />
          <span class="hidden sm:inline">عميل جديد</span>
        </button>
      </div>
    </div>

    <!-- Items List (Scrollable) -->
    <div class="flex-1 overflow-y-auto px-4 py-2 divide-y divide-dashed divide-[var(--border)]">
      <div
        v-for="item in posStore.cartItemsList"
        :key="item.product_id"
        class="py-3 space-y-1.5"
      >
        <!-- Line Header: Title & Total -->
        <div class="flex items-start justify-between gap-2">
          <div class="flex-1">
            <h4 class="text-xs font-bold text-[var(--text)] leading-snug line-clamp-2">
              {{ item.name }}
            </h4>
            <div class="text-[10px] text-[var(--muted)] font-mono">
              {{ item.barcode || item.sku || 'بدون كود' }}
            </div>
          </div>
          <span class="text-sm font-black text-[var(--text)] flex-none">
            {{ formatMoney(item.total) }} <small class="text-[10px] font-normal text-[var(--muted)]">ج.م</small>
          </span>
        </div>

        <!-- Price Override & Unit Price -->
        <div class="flex items-center gap-2 text-xs">
          <span class="text-[11px] text-[var(--muted)]">سعر الوحدة:</span>
          
          <!-- Editable price input if user has permission, otherwise static -->
          <input
            v-if="canOverridePrice"
            :value="item.unit_price"
            type="number"
            min="0"
            step="any"
            class="w-20 h-7 text-center font-bold text-xs rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
            title="تعديل سعر البيع"
            @change="posStore.updateCartItemPrice(item.product_id, $event.target.value)"
          />
          <b v-else class="font-bold text-[var(--text)]">{{ formatMoney(item.unit_price) }} ج.م</b>

          <!-- Discount badge or price difference tag -->
          <span
            v-if="item.unit_price < item.original_unit_price"
            class="text-[10px] font-bold text-[var(--ok)] bg-[var(--okbg)] px-1.5 py-0.5 rounded"
          >
            وفر {{ formatMoney(item.original_unit_price - item.unit_price) }} ج.م/ق
          </span>
          <span
            v-else-if="item.unit_price > item.original_unit_price"
            class="text-[10px] font-bold text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded"
          >
            تجاوز السعر (+{{ formatMoney(item.unit_price - item.original_unit_price) }})
          </span>
        </div>

        <!-- Quantity & Remove Controls -->
        <div class="flex items-center gap-2 pt-1">
          <!-- Quantity Stepper -->
          <div class="flex items-center border border-[var(--border)] rounded-lg overflow-hidden bg-[var(--card)]">
            <button
              type="button"
              class="w-7 h-7 flex items-center justify-center text-sm font-bold text-[var(--text)] hover:bg-[var(--border)] transition"
              @click="posStore.updateCartItemQty(item.product_id, item.quantity - 1)"
            >
              −
            </button>
            <input
              :value="item.quantity"
              type="number"
              min="1"
              :max="item.product?.stock_quantity"
              class="w-9 h-7 text-center font-bold text-xs bg-transparent border-0 outline-none text-[var(--text)]"
              @change="posStore.updateCartItemQty(item.product_id, $event.target.value)"
            />
            <button
              type="button"
              class="w-7 h-7 flex items-center justify-center text-sm font-bold text-[var(--text)] hover:bg-[var(--border)] transition"
              @click="posStore.updateCartItemQty(item.product_id, item.quantity + 1)"
            >
              +
            </button>
          </div>

          <span class="text-[10px] text-[var(--muted)]">
            (متاح {{ item.product?.stock_quantity }})
          </span>

          <div class="flex-1"></div>

          <!-- Delete line button -->
          <button
            type="button"
            class="p-1 rounded-lg text-[var(--crimson)] hover:bg-[var(--soft)] transition"
            title="حذف من السلة"
            @click="posStore.removeFromCart(item.product_id)"
          >
            <Trash2 class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Empty Cart Message -->
      <div v-if="posStore.cartLinesCount === 0" class="text-center py-20 text-[var(--muted)]">
        <ShoppingCart class="w-12 h-12 mx-auto mb-2 text-[var(--muted)]/40" />
        <h4 class="font-bold text-sm text-[var(--text)] mb-1">السلة فارغة</h4>
        <p class="text-xs">امسح باركود الكتاب أو اضغط على أي صنف من القائمة لإضافته هنا.</p>
      </div>
    </div>

    <!-- Cart Footer & Totals -->
    <div class="p-4 border-t border-[var(--border)] bg-[var(--bg)] flex flex-col gap-2 flex-none">
      <!-- Subtotal -->
      <div class="flex items-center justify-between text-xs font-semibold text-[var(--text)]">
        <span class="text-[var(--muted)]">المجموع الفرعي ({{ posStore.cartTotalPieces }} قطعة)</span>
        <b>{{ formatMoney(posStore.cartSubtotal) }} ج.م</b>
      </div>

      <!-- Item Discounts / Savings (if any) -->
      <div
        v-if="posStore.cartPriceOverrideSavings > 0"
        class="flex items-center justify-between text-xs font-semibold text-[var(--ok)]"
      >
        <span>إجمالي الخصم على الأصناف</span>
        <b>-{{ formatMoney(posStore.cartPriceOverrideSavings) }} ج.م</b>
      </div>

      <!-- Invoice Discount Row -->
      <div class="flex items-center justify-between text-xs font-semibold pt-1">
        <span class="text-[var(--muted)]">خصم الفاتورة</span>
        <div class="flex items-center gap-1.5">
          <!-- Discount Type Toggle -->
          <div class="flex border border-[var(--border)] rounded-lg overflow-hidden bg-[var(--card)] text-[11px] font-bold">
            <button
              type="button"
              :class="[
                'px-2 py-0.5 transition',
                posStore.cart.invoice_discount_type === 'fixed'
                  ? 'bg-[var(--text)] text-[var(--card)]'
                  : 'text-[var(--muted)] hover:bg-[var(--border)]'
              ]"
              @click="posStore.setInvoiceDiscount('fixed', posStore.cart.invoice_discount_value)"
            >
              ج.م
            </button>
            <button
              type="button"
              :class="[
                'px-2 py-0.5 transition',
                posStore.cart.invoice_discount_type === 'percentage'
                  ? 'bg-[var(--text)] text-[var(--card)]'
                  : 'text-[var(--muted)] hover:bg-[var(--border)]'
              ]"
              @click="posStore.setInvoiceDiscount('percentage', posStore.cart.invoice_discount_value)"
            >
              %
            </button>
          </div>

          <!-- Discount Value Input -->
          <input
            :value="posStore.cart.invoice_discount_value || ''"
            type="number"
            min="0"
            step="any"
            placeholder="0"
            :disabled="!canApplyInvoiceDiscount"
            class="w-16 h-7 text-center font-bold text-xs rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)] disabled:opacity-50"
            @change="posStore.setInvoiceDiscount(posStore.cart.invoice_discount_type, $event.target.value)"
          />
        </div>
      </div>

      <!-- Invoice Discount Hint -->
      <div v-if="posStore.cartInvoiceDiscountAmount > 0" class="text-[11px] font-bold text-[var(--ok)] text-end">
        {{ discountHint }}
      </div>

      <!-- Grand Total -->
      <div class="flex items-center justify-between text-xl font-black text-[var(--text)] pt-1.5 border-t border-[var(--border)]">
        <span>الإجمالي</span>
        <span class="text-[var(--crimson)]">{{ formatMoney(posStore.cartGrandTotal) }} <small class="text-xs text-[var(--muted)] font-normal">ج.م</small></span>
      </div>

      <!-- Checkout Button with F9 Shortcut -->
      <button
        type="button"
        :disabled="!posStore.isOpenShift || posStore.cartLinesCount === 0"
        class="h-14 mt-1 rounded-2xl bg-[var(--crimson)] text-white text-lg font-black hover:opacity-95 transition flex items-center justify-center gap-2 shadow-lg shadow-[var(--crimson)]/20 disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none"
        @click="$emit('open-pay')"
      >
        <span>تحصيل {{ formatMoney(posStore.cartGrandTotal) }} ج.م</span>
        <kbd class="font-mono text-xs font-normal border border-white/40 px-1.5 py-0.5 rounded text-white/90">F9</kbd>
      </button>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed } from 'vue';
import { ShoppingBag, ShoppingCart, Trash2, UserPlus } from 'lucide-vue-next';
import { usePosStore } from '../../stores/pos';
import { useAuthStore } from '../../stores/auth';

const emit = defineEmits(['open-pay', 'open-add-customer']);

const posStore = usePosStore();
const authStore = useAuthStore();

const selectedCustomerId = ref(null);

const canOverridePrice = computed(() => authStore.hasPermission('pos.override_price'));
const canApplyInvoiceDiscount = computed(() => authStore.hasPermission('pos.apply_invoice_discount'));

const discountHint = computed(() => {
  const s = posStore.cartSumOfLineTotals;
  const d = posStore.cartInvoiceDiscountAmount;
  if (!d || !s) return '';
  const pct = Math.round((d / s) * 1000) / 10;
  if (posStore.cart.invoice_discount_type === 'percentage') {
    return `خصمت ${posStore.cart.invoice_discount_value}% = ${formatMoney(d)} ج.م من الإجمالي`;
  }
  return `خصمت ${formatMoney(d)} ج.م = ${pct}% من الإجمالي`;
});

function onCustomerChange() {
  if (selectedCustomerId.value) {
    const cust = posStore.customers.find((c) => c.id === selectedCustomerId.value);
    posStore.setCustomer(cust || null);
  } else {
    posStore.setCustomer(null);
  }
}

function confirmClearCart() {
  if (confirm('هل أنت متأكد من تفريغ سلة البيع بالكامل؟')) {
    posStore.clearCart();
    selectedCustomerId.value = null;
  }
}

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
