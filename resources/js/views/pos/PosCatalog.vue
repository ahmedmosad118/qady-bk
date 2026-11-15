<template>
  <section class="flex flex-col min-h-0 p-4 overflow-hidden" dir="rtl">
    <!-- Search Bar with Scanner Support -->
    <div class="relative flex items-center gap-2.5 h-[54px] px-4 rounded-2xl border-2 border-[var(--border)] bg-[var(--card)] focus-within:border-[var(--crimson)] transition">
      <Barcode class="w-6 h-6 text-[var(--muted)] flex-none" />
      <input
        ref="searchInputRef"
        v-model="posStore.searchQuery"
        type="text"
        placeholder="امسح الباركود أو ابحث بالاسم أو SKU…"
        autocomplete="off"
        class="flex-1 bg-transparent border-0 outline-none text-[var(--text)] font-bold text-base placeholder:text-[var(--muted)]/70"
        @keydown.enter="handleBarcodeOrEnter"
      />
      <button
        v-if="posStore.searchQuery"
        type="button"
        class="p-1 rounded-lg text-[var(--muted)] hover:bg-[var(--bar)] transition"
        @click="posStore.searchQuery = ''"
      >
        <X class="w-4 h-4" />
      </button>
      <kbd class="hidden sm:inline font-mono text-xs font-bold px-2 py-0.5 rounded-md border border-[var(--border)] text-[var(--muted)] bg-[var(--bar)]">
        F2
      </kbd>
    </div>

    <!-- Category Filters -->
    <div class="flex items-center gap-2 my-3 overflow-x-auto pb-1 scrollbar-none flex-nowrap">
      <button
        type="button"
        :class="[
          'px-4 py-1.5 rounded-full text-xs font-bold border transition whitespace-nowrap',
          posStore.selectedCategoryId === null
            ? 'bg-[var(--text)] text-[var(--card)] border-[var(--text)] shadow-sm'
            : 'bg-[var(--card)] text-[var(--text)] border-[var(--border)] hover:border-[var(--muted)]'
        ]"
        @click="posStore.selectedCategoryId = null"
      >
        الكل
      </button>
      <button
        v-for="cat in posStore.categories"
        :key="cat.id"
        type="button"
        :class="[
          'px-4 py-1.5 rounded-full text-xs font-bold border transition whitespace-nowrap',
          posStore.selectedCategoryId === cat.id
            ? 'bg-[var(--text)] text-[var(--card)] border-[var(--text)] shadow-sm'
            : 'bg-[var(--card)] text-[var(--text)] border-[var(--border)] hover:border-[var(--muted)]'
        ]"
        @click="posStore.selectedCategoryId = cat.id"
      >
        {{ cat.name }}
      </button>
    </div>

    <!-- Products Grid -->
    <div class="flex-1 overflow-y-auto pr-1">
      <div
        v-if="posStore.filteredProducts.length > 0"
        class="grid grid-cols-[repeat(auto-fill,minmax(168px,1fr))] gap-3.5 pb-4 items-start"
      >
        <button
          v-for="prod in posStore.filteredProducts"
          :key="prod.id"
          type="button"
          :disabled="!posStore.isOpenShift || Number(prod.stock_quantity || 0) < 1"
          class="group rounded-2xl border border-[var(--border)] bg-[var(--card)] p-2.5 text-start transition flex flex-col gap-2 hover:border-[var(--crimson)] hover:-translate-y-0.5 hover:shadow-md disabled:opacity-45 disabled:cursor-not-allowed disabled:transform-none select-none"
          @click="posStore.addToCart(prod)"
        >
          <!-- Elegant Book Cover Mockup matching reference design -->
          <div
            class="aspect-[4/3] rounded-[10px_4px_4px_10px] p-2 text-center text-white font-extrabold text-xs leading-snug flex items-center justify-center relative overflow-hidden shadow-inner"
            :style="{ background: getCoverBackground(prod) }"
          >
            <!-- Spine shadow simulation on left -->
            <div class="absolute inset-y-0 left-0 w-2.5 bg-black/35 pointer-events-none"></div>
            <!-- Gold accent band -->
            <span class="w-full py-1.5 px-1 border-y border-[#D7B26A] text-[11px] line-clamp-2 drop-shadow-sm">
              {{ prod.name }}
            </span>
          </div>

          <!-- Title -->
          <h3 class="text-xs font-bold text-[var(--text)] line-clamp-2 min-h-[34px] leading-tight group-hover:text-[var(--crimson)] transition-colors">
            {{ prod.name }}
          </h3>

          <!-- Price & Stock Footer -->
          <div class="flex items-center justify-between text-xs pt-1 border-t border-[var(--border)]/60">
            <b class="text-sm font-black text-[var(--text)]">
              {{ formatMoney(prod.selling_price) }} <small class="text-[10px] font-normal text-[var(--muted)]">ج.م</small>
            </b>
            <span
              :class="[
                'text-[11px]',
                Number(prod.stock_quantity || 0) < 1
                  ? 'text-[var(--crimson)] font-black'
                  : Number(prod.stock_quantity || 0) < 5
                    ? 'text-amber-600 font-bold'
                    : 'text-[var(--muted)]'
              ]"
            >
              {{ Number(prod.stock_quantity || 0) < 1 ? 'نفد' : `متبقي ${prod.stock_quantity}` }}
            </span>
          </div>
        </button>
      </div>

      <!-- Empty State -->
      <div v-else class="text-center py-20 text-[var(--muted)]">
        <BookOpen class="w-12 h-12 mx-auto mb-3 text-[var(--muted)]/50" />
        <h4 class="font-bold text-base text-[var(--text)] mb-1">لا توجد كتب مطابقة</h4>
        <p class="text-xs">جرب البحث بكلمات أخرى أو تغيير التصنيف المختار.</p>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref } from 'vue';
import { Barcode, X, BookOpen } from 'lucide-vue-next';
import { usePosStore } from '../../stores/pos';

const posStore = usePosStore();
const searchInputRef = ref(null);

// Curated Al-Qadi book mockup palette
const bookColors = [
  '#A3162B', // Al-Qadi brand crimson
  '#16181D', // Dark ink
  '#1F2A44', // Legal Navy
  '#1F4B3F', // Classic Forest Green
  '#5A1020', // Deep Maroon
  '#74101F', // Wine Red
];

function getCoverBackground(product) {
  // Deterministic color based on product ID
  const idx = Math.abs(Number(product.id || 0)) % bookColors.length;
  const col = bookColors[idx];
  return `linear-gradient(to left, rgba(0,0,0,0.3) 0 6%, transparent 6%), ${col}`;
}

async function handleBarcodeOrEnter() {
  const query = posStore.searchQuery.trim();
  if (!query) return;

  // 1. Try exact barcode match first
  const exactBarcodeProduct = posStore.products.find((p) => p.barcode === query);
  if (exactBarcodeProduct) {
    posStore.addToCart(exactBarcodeProduct);
    posStore.searchQuery = '';
    return;
  }

  // 2. If filtered list has exactly 1 match on Enter, add it directly
  if (posStore.filteredProducts.length === 1) {
    posStore.addToCart(posStore.filteredProducts[0]);
    posStore.searchQuery = '';
    return;
  }

  // 3. Otherwise try server lookup
  const res = await posStore.lookupBarcode(query);
  if (res) {
    posStore.searchQuery = '';
  }
}

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

defineExpose({
  focusSearch: () => {
    searchInputRef.value?.focus();
    searchInputRef.value?.select();
  },
});
</script>
