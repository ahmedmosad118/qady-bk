<template>
  <aside class="flex flex-col h-full bg-[var(--card)] border-s border-[var(--border)] select-none min-h-0">
    <!-- Cart Header -->
    <div class="p-3.5 border-b border-[var(--border)] flex flex-col gap-2.5 bg-[var(--card)] flex-none">
      <div class="flex items-center justify-between">
        <h2 class="text-base font-black flex items-center gap-1.5 text-[var(--text)]">
          <ShoppingBag class="w-5 h-5 text-[var(--crimson)]" />
          <span>{{ $t('pos.cartTitle') }}</span>
          <span v-if="posStore.cartLinesCount > 0" class="text-xs font-bold text-[var(--muted)]">
            {{ $t('pos.cartSummary', { lines: posStore.cartLinesCount, pieces: posStore.cartTotalPieces }) }}
          </span>
        </h2>

        <button
          v-if="posStore.cartLinesCount > 0"
          type="button"
          class="h-8 px-2.5 rounded-lg border border-[var(--border)] text-xs font-bold text-[var(--muted)] hover:text-red-600 hover:border-red-300 transition"
          @click="confirmClearCart"
        >
          {{ $t('pos.clearCart') }}
        </button>
      </div>

      <!-- Customer Row -->
      <div class="flex items-center gap-2">
        <div class="flex-1 min-w-0">
          <SearchableSelect
            :model-value="posStore.cart.customer?.id ?? null"
            :fetch-options="fetchCustomerOptions"
            :selected-option-data="selectedCustomerOption"
            :paginate="10"
            :clearable="true"
            :placeholder="$t('pos.walkInCustomer')"
            :search-placeholder="$t('pos.searchCustomerPlaceholder')"
            @change="onCustomerChange"
          />
        </div>

        <button
          type="button"
          class="h-10 px-3 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:border-[var(--crimson)] transition flex items-center gap-1 flex-none cursor-pointer"
          :title="$t('pos.addCustomerTooltip')"
          @click="$emit('open-add-customer')"
        >
          <UserPlus class="w-3.5 h-3.5 text-[var(--crimson)]" />
          <span class="hidden sm:inline">{{ $t('pos.newCustomer') }}</span>
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
          </div>
          <span class="text-sm font-black text-[var(--text)] flex-none font-mono">
            {{ formatMoney(item.total) }} <small class="text-[10px] font-normal text-[var(--muted)] font-sans">{{ $t('common.currency') }}</small>
          </span>
        </div>

        <!-- Individual Price Input & Override -->
        <div class="flex items-center gap-2 text-xs">
          <div class="flex items-center gap-1.5">
            <span class="text-[11px] font-bold text-[var(--muted)]">{{ $t('pos.priceLabel') }}</span>
            <div class="relative flex items-center">
              <input
                :value="item.unit_price"
                type="number"
                min="0"
                step="any"
                class="w-20 h-7 text-center font-bold text-xs rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)] ps-1 pe-6 font-mono"
                :title="$t('pos.overridePriceTooltip')"
                @change="posStore.updateCartItemPrice(item.product_id, $event.target.value)"
                @keyup.enter="$event.target.blur()"
              />
              <span class="absolute end-1.5 text-[10px] font-bold text-[var(--muted)] pointer-events-none">{{ $t('common.currency') }}</span>
            </div>
          </div>

          <!-- Savings / Price Difference Tag -->
          <span
            v-if="item.unit_price < item.original_unit_price"
            class="text-[10px] font-bold text-[var(--ok)] bg-[var(--okbg)] px-1.5 py-0.5 rounded whitespace-nowrap"
          >
            {{ $t('pos.savedAmount', { amount: formatMoney(item.original_unit_price - item.unit_price) }) }}
          </span>
          <span
            v-else-if="item.unit_price > item.original_unit_price"
            class="text-[10px] font-bold text-amber-700 bg-amber-50 px-1.5 py-0.5 rounded whitespace-nowrap"
          >
            +{{ formatMoney(item.unit_price - item.original_unit_price) }}
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
            {{ $t('pos.availableQty', { qty: item.product?.stock_quantity }) }}
          </span>

          <div class="flex-1"></div>

          <!-- Delete line button -->
          <button
            type="button"
            class="p-1 rounded-lg text-[var(--crimson)] hover:bg-[var(--soft)] transition"
            :title="$t('pos.removeFromCart')"
            @click="posStore.removeFromCart(item.product_id)"
          >
            <Trash2 class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Empty Cart Message -->
      <div v-if="posStore.cartLinesCount === 0" class="text-center py-20 text-[var(--muted)]">
        <ShoppingCart class="w-12 h-12 mx-auto mb-2 text-[var(--muted)]/40" />
        <h4 class="font-bold text-sm text-[var(--text)] mb-1">{{ $t('pos.cartEmptyToast') }}</h4>
        <p class="text-xs">{{ $t('pos.emptyCartHint') }}</p>
      </div>
    </div>

    <!-- Cart Footer & Totals -->
    <div class="p-4 border-t border-[var(--border)] bg-[var(--bg)] flex flex-col gap-2 flex-none">
      <!-- Subtotal -->
      <div class="flex items-center justify-between text-xs font-semibold text-[var(--text)]">
        <span class="text-[var(--muted)]">{{ $t('pos.subtotalPieces', { pieces: posStore.cartTotalPieces }) }}</span>
        <b>{{ formatMoney(posStore.cartSubtotal) }} {{ $t('common.currency') }}</b>
      </div>

      <!-- Item Discounts / Savings (if any) -->
      <div
        v-if="posStore.cartPriceOverrideSavings > 0"
        class="flex items-center justify-between text-xs font-semibold text-[var(--ok)]"
      >
        <span>{{ $t('pos.itemsDiscountTotal') }}</span>
        <b>-{{ formatMoney(posStore.cartPriceOverrideSavings) }} {{ $t('common.currency') }}</b>
      </div>

      <!-- Invoice Discount Row -->
      <div class="flex items-center justify-between text-xs font-semibold pt-1">
        <span class="text-[var(--muted)]">{{ $t('pos.invoiceDiscount') }}</span>
        <div class="flex items-center gap-1.5">
          <!-- Discount Type Toggle -->
          <div class="flex border border-[var(--border)] rounded-lg overflow-hidden bg-[var(--card)] text-[11px] font-bold">
            <button
              type="button"
              :class="[
                'px-2.5 py-1 transition cursor-pointer',
                posStore.cart.invoice_discount_type === 'fixed'
                  ? 'bg-[var(--text)] text-[var(--card)]'
                  : 'text-[var(--muted)] hover:bg-[var(--border)]'
              ]"
              :title="$t('pos.fixedDiscountTooltip')"
              @click="posStore.setInvoiceDiscount('fixed', posStore.cart.invoice_discount_value)"
            >
              {{ $t('common.currency') }}
            </button>
            <button
              type="button"
              :class="[
                'px-2.5 py-1 transition cursor-pointer',
                posStore.cart.invoice_discount_type === 'percentage'
                  ? 'bg-[var(--text)] text-[var(--card)]'
                  : 'text-[var(--muted)] hover:bg-[var(--border)]'
              ]"
              :title="$t('pos.percentDiscountTooltip')"
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
            class="w-16 h-7 text-center font-bold text-xs rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] outline-none focus:border-[var(--crimson)]"
            :title="$t('pos.discountValueTooltip')"
            @input="posStore.setInvoiceDiscount(posStore.cart.invoice_discount_type, $event.target.value)"
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
        <span>{{ $t('pos.grandTotal') }}</span>
        <span class="text-[var(--crimson)]">{{ formatMoney(posStore.cartGrandTotal) }} <small class="text-xs text-[var(--muted)] font-normal">{{ $t('common.currency') }}</small></span>
      </div>

      <!-- Checkout Button with F9 Shortcut -->
      <button
        type="button"
        :disabled="!posStore.isOpenShift || posStore.cartLinesCount === 0"
        class="h-14 mt-1 rounded-2xl bg-[var(--crimson)] text-white text-lg font-black hover:opacity-95 transition flex items-center justify-center gap-2 shadow-lg shadow-[var(--crimson)]/20 disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none"
        @click="$emit('open-pay')"
      >
        <span>{{ $t('pos.collectAmount', { amount: formatMoney(posStore.cartGrandTotal) }) }}</span>
        <kbd class="font-mono text-xs font-normal border border-white/40 px-1.5 py-0.5 rounded text-white/90">F9</kbd>
      </button>
    </div>
  </aside>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ShoppingBag, ShoppingCart, Trash2, UserPlus } from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import { createContactFetcher } from '../../utils/selectFetchers';
import { usePosStore } from '../../stores/pos';
import { useAuthStore } from '../../stores/auth';

const emit = defineEmits(['open-pay', 'open-add-customer']);

const { t } = useI18n();
const posStore = usePosStore();
const authStore = useAuthStore();

const fetchCustomerOptions = createContactFetcher({ isCustomer: true, activeOnly: true });

const selectedCustomerOption = computed(() => {
  const cust = posStore.cart.customer;
  return cust ? { value: cust.id, label: cust.name, raw: cust } : null;
});

const canOverridePrice = computed(() => authStore.hasPermission('pos.override_price'));
const canApplyInvoiceDiscount = computed(() => authStore.hasPermission('pos.apply_invoice_discount'));

const discountHint = computed(() => {
  const s = posStore.cartSumOfLineTotals;
  const d = posStore.cartInvoiceDiscountAmount;
  if (!d || !s) return '';
  const pct = Math.round((d / s) * 1000) / 10;
  if (posStore.cart.invoice_discount_type === 'percentage') {
    return t('pos.discountPercentHint', { pct: posStore.cart.invoice_discount_value, amount: formatMoney(d) });
  }
  return t('pos.discountFixedHint', { amount: formatMoney(d), pct });
});

function onCustomerChange(val, option) {
  if (val !== null && val !== undefined && val !== '') {
    posStore.setCustomer(option?.raw || { id: val, name: option?.label || '' });
  } else {
    posStore.setCustomer(null);
  }
}

function confirmClearCart() {
  if (confirm(t('pos.confirmClearCart'))) {
    posStore.clearCart();
  }
}

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
</script>
