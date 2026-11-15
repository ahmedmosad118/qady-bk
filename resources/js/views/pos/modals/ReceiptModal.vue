<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm" dir="rtl" @keydown.esc="handleClose">
    <div class="w-full max-w-[500px] max-h-[95vh] flex flex-col rounded-3xl bg-[var(--card)] p-5 shadow-2xl border border-[var(--border)] text-[var(--text)]">
      <!-- Modal Header -->
      <div class="flex items-center justify-between pb-3 mb-2 border-b border-[var(--border)] flex-none">
        <div class="flex items-center gap-2">
          <span class="inline-block w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></span>
          <h3 class="font-black text-lg">إيصال الفاتورة الرسمية</h3>
        </div>

        <div class="flex items-center gap-2">
          <!-- Width Toggle -->
          <div class="flex items-center gap-1 bg-[var(--bar)] p-1 rounded-xl text-xs font-bold">
            <button
              type="button"
              :class="[
                'px-2.5 py-1 rounded-lg transition cursor-pointer',
                paperSize === '80mm' ? 'bg-[var(--card)] text-[var(--crimson)] shadow-sm' : 'text-[var(--muted)]'
              ]"
              @click="paperSize = '80mm'"
            >
              حراري 80mm
            </button>
            <button
              type="button"
              :class="[
                'px-2.5 py-1 rounded-lg transition cursor-pointer',
                paperSize === '58mm' ? 'bg-[var(--card)] text-[var(--crimson)] shadow-sm' : 'text-[var(--muted)]'
              ]"
              @click="paperSize = '58mm'"
            >
              حراري 58mm
            </button>
          </div>

          <!-- Header Close Button -->
          <button
            type="button"
            class="p-1.5 rounded-xl border border-[var(--border)] text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
            title="إغلاق (Esc)"
            @click="handleClose"
          >
            <X class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Receipt Preview Container (Scrollable) -->
      <div class="flex-1 overflow-y-auto px-2 py-1">
        <div
          id="thermal-receipt-printable"
          class="receipt-box mx-auto my-1 bg-white text-black p-4 rounded-xl shadow border border-neutral-300 font-mono text-xs leading-relaxed"
          :style="{ width: paperSize === '58mm' ? '250px' : '340px' }"
        >
          <!-- Header -->
          <div class="text-center pb-2 border-b-2 border-dashed border-neutral-800">
            <!-- Brand Logo -->
            <svg class="w-10 h-10 mx-auto text-[#16181d] fill-current" viewBox="0 0 200 200">
              <path d="M10 70L100 20L190 70Z" fill="#16181D"/>
              <circle cx="100" cy="52" r="7" fill="#B8923A"/>
              <rect x="20" y="78" width="160" height="8" rx="2" fill="#B8923A"/>
              <g fill="#16181D">
                <rect x="20" y="96" width="24" height="70" rx="4"/>
                <rect x="54" y="96" width="24" height="70" rx="4"/>
                <rect x="122" y="96" width="24" height="70" rx="4"/>
                <rect x="156" y="96" width="24" height="70" rx="4"/>
              </g>
              <rect x="88" y="96" width="24" height="70" rx="4" fill="#A3162B"/>
              <rect x="10" y="174" width="180" height="8" rx="2" fill="#16181D"/>
            </svg>
            <h2 class="text-base font-black mt-1 text-black tracking-wide">مكتبة القاضي</h2>
            <p class="text-[11px] text-neutral-700 font-bold">للإصدارات والمراجع القانونية</p>
            <p class="text-[10px] text-neutral-600 mt-0.5">القاهرة · جمهورية مصر العربية</p>
            <div class="mt-1">
              <span class="text-[10px] font-bold inline-block bg-neutral-100 px-2 py-0.5 rounded border border-neutral-300">
                فاتورة بيع ضريبية مبسطة
              </span>
            </div>
          </div>

          <!-- Metadata -->
          <div class="py-2 border-b border-dashed border-neutral-700 text-[11px] space-y-1">
            <div class="flex justify-between items-center">
              <span class="text-neutral-600 font-sans">رقم الفاتورة:</span>
              <b class="font-bold text-black font-mono tracking-wider">{{ sale.invoice_number }}</b>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-neutral-600 font-sans">التاريخ والوقت:</span>
              <span class="font-mono" dir="ltr">{{ formattedDate }}</span>
            </div>
            <div class="flex justify-between items-center">
              <span class="text-neutral-600 font-sans">العميل:</span>
              <span class="font-bold text-black">{{ customerName }}</span>
            </div>
            <div v-if="cashierName" class="flex justify-between items-center">
              <span class="text-neutral-600 font-sans">الكاشير:</span>
              <span>{{ cashierName }}</span>
            </div>
          </div>

          <!-- Items Table -->
          <div class="py-2 border-b-2 border-dashed border-neutral-800">
            <div class="flex justify-between font-bold text-[11px] pb-1 border-b border-neutral-400">
              <span class="w-[50%] text-start">الصنف</span>
              <span class="w-[25%] text-center">الكمية</span>
              <span class="w-[25%] text-end">الإجمالي</span>
            </div>

            <div
              v-for="(item, idx) in saleItems"
              :key="idx"
              class="py-1.5 text-[11px] border-b border-dotted border-neutral-200 last:border-0"
            >
              <div class="font-bold text-black leading-snug">{{ item.name }}</div>
              <div class="flex justify-between items-center text-neutral-600 text-[10px] mt-0.5">
                <span class="w-[50%] text-start font-mono text-[10px]">
                  {{ formatMoney(item.unit_price) }} ج.م
                </span>
                <span class="w-[25%] text-center font-bold text-black font-mono">
                  × {{ item.quantity }}
                </span>
                <span class="w-[25%] text-end font-black text-black font-mono">
                  {{ formatMoney(item.total) }} ج.م
                </span>
              </div>
              <div v-if="item.discount_amount && Number(item.discount_amount) > 0" class="text-[9px] text-emerald-800 text-end">
                (خصم صنف: -{{ formatMoney(item.discount_amount) }} ج.م)
              </div>
            </div>
          </div>

          <!-- Totals Section -->
          <div class="py-2 space-y-1.5 text-[11px]">
            <div class="flex justify-between text-neutral-700">
              <span>المجموع الفرعي:</span>
              <span class="font-mono">{{ formatMoney(sale.subtotal) }} ج.م</span>
            </div>

            <div v-if="Number(sale.items_discount_total || 0) > 0" class="flex justify-between text-emerald-800 font-semibold">
              <span>إجمالي خصومات الأصناف:</span>
              <span class="font-mono">-{{ formatMoney(sale.items_discount_total) }} ج.م</span>
            </div>

            <div v-if="Number(sale.invoice_discount_amount || 0) > 0" class="flex justify-between text-emerald-800 font-semibold">
              <span>خصم عام على الفاتورة:</span>
              <span class="font-mono">-{{ formatMoney(sale.invoice_discount_amount) }} ج.م</span>
            </div>

            <!-- Grand Total Highlight Box -->
            <div class="flex justify-between items-center font-black text-sm py-1.5 px-2 bg-neutral-100 rounded border border-neutral-400 text-black mt-1">
              <span class="text-xs">صافي الإجمالي المطلوب:</span>
              <span class="text-base font-mono font-black">{{ formatMoney(sale.grand_total) }} ج.م</span>
            </div>

            <!-- Payment Details -->
            <div class="pt-1.5 space-y-1 border-t border-neutral-300">
              <div class="flex justify-between text-[11px]">
                <span class="text-neutral-600">طريقة السداد:</span>
                <b class="font-bold">{{ paymentMethodName }}</b>
              </div>

              <div v-if="paymentMethodName === 'نقدي'" class="flex justify-between text-[11px]">
                <span class="text-neutral-600">المبلغ المدفوع:</span>
                <span class="font-mono font-bold">{{ formatMoney(sale.paid_amount || sale.grand_total) }} ج.م</span>
              </div>

              <div v-if="paymentMethodName === 'نقدي'" class="flex justify-between text-[11px]">
                <span class="text-neutral-600">المتبقي للعميل:</span>
                <span class="font-mono font-bold text-emerald-900">{{ formatMoney(sale.change_amount || 0) }} ج.م</span>
              </div>
            </div>
          </div>

          <!-- Sharp SVG Barcode & Footer -->
          <div class="text-center pt-3 border-t-2 border-dashed border-neutral-800">
            <!-- Simulated Code128 Crisp Vector Barcode -->
            <svg viewBox="0 0 220 38" class="w-48 h-9 mx-auto my-0.5" preserveAspectRatio="none">
              <rect x="0" y="0" width="220" height="38" fill="#ffffff" />
              <!-- Bars Pattern based on invoice number -->
              <g fill="#000000">
                <rect v-for="(bar, bIdx) in barcodeBars" :key="bIdx" :x="bar.x" y="0" :width="bar.w" height="38" />
              </g>
            </svg>
            <p class="text-[10px] font-bold font-mono tracking-widest text-neutral-800 mt-0.5">{{ sale.invoice_number }}</p>

            <p class="text-[10px] text-neutral-700 font-bold mt-2">شكرًا لتعاملكم مع مكتبة القاضي</p>
            <p class="text-[9px] text-neutral-500 mt-0.5">البضاعة المباعة ترد وتستبدل خلال 14 يوماً بموجب الفاتورة الأصلية</p>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex gap-3 pt-3 mt-2 border-t border-[var(--border)] flex-none">
        <!-- Direct Print Button -->
        <button
          type="button"
          class="flex-1 h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 active:bg-emerald-800 text-white font-black text-base transition flex items-center justify-center gap-2 shadow-lg shadow-emerald-600/20 cursor-pointer"
          @click="printReceipt"
        >
          <Printer class="w-5 h-5" />
          <span>طباعة الإيصال (Ctrl+P)</span>
        </button>

        <!-- Close Button (Reprint Mode) -->
        <button
          v-if="isReprint"
          type="button"
          class="h-12 px-6 rounded-xl bg-[var(--bar)] hover:bg-[var(--bar-hover)] text-[var(--text)] font-bold text-sm transition flex items-center justify-center gap-2 border border-[var(--border)] cursor-pointer"
          @click="handleClose"
        >
          <X class="w-4 h-4" />
          <span>إغلاق (Esc)</span>
        </button>

        <!-- New Sale Button (Checkout Mode) -->
        <button
          v-else
          type="button"
          class="h-12 px-6 rounded-xl bg-[var(--crimson)] hover:bg-[var(--crimson-hover)] text-white font-bold text-sm transition flex items-center justify-center gap-2 shadow-md cursor-pointer"
          @click="handleClose"
        >
          <Plus class="w-4 h-4" />
          <span>فاتورة جديدة (Esc)</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Printer, Plus, X } from 'lucide-vue-next';
import { useAuthStore } from '../../../stores/auth';

const props = defineProps({
  sale: {
    type: Object,
    required: true,
  },
  isReprint: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['new-sale', 'close']);

function handleClose() {
  if (props.isReprint) {
    emit('close');
  } else {
    emit('new-sale');
  }
}

const authStore = useAuthStore();
const paperSize = ref('80mm');

const customerName = computed(() => {
  return props.sale.customer?.name || 'عميل نقدي (Walk-in)';
});

const cashierName = computed(() => {
  return props.sale.creator?.name || authStore.user?.name || '';
});

const paymentMethodName = computed(() => {
  return props.sale.payment_method?.name || props.sale.paymentMethod?.name || 'نقدي';
});

const formattedDate = computed(() => {
  const dateStr = props.sale.sold_at || props.sale.created_at || new Date().toISOString();
  return new Date(dateStr).toLocaleString('ar-EG', {
    dateStyle: 'short',
    timeStyle: 'short',
  });
});

const saleItems = computed(() => {
  const items = props.sale.items || [];
  return items.map((it) => ({
    name: it.product_snapshot?.name || it.product?.name || it.name || 'كتاب',
    quantity: Number(it.quantity || 1),
    unit_price: Number(it.unit_price || 0),
    discount_amount: Number(it.discount_amount || 0),
    total: Number(it.total || (it.unit_price * it.quantity)),
  }));
});

// Deterministic crisp vector barcode generation for any invoice number
const barcodeBars = computed(() => {
  const str = props.sale.invoice_number || 'INV-00000000-00000';
  const bars = [];
  let currentX = 10;

  // Start guard
  bars.push({ x: currentX, w: 2 }); currentX += 4;
  bars.push({ x: currentX, w: 2 }); currentX += 4;

  for (let i = 0; i < str.length; i++) {
    const code = str.charCodeAt(i);
    const pattern = [(code % 3) + 1, ((code >> 1) % 2) + 1, ((code >> 2) % 3) + 1, ((code >> 3) % 2) + 1];
    for (let p = 0; p < pattern.length; p++) {
      const w = pattern[p];
      if (p % 2 === 0) {
        bars.push({ x: currentX, w });
      }
      currentX += w + 1;
      if (currentX > 200) break;
    }
    if (currentX > 200) break;
  }

  // Stop guard
  bars.push({ x: currentX, w: 3 }); currentX += 5;
  bars.push({ x: currentX, w: 2 });

  return bars;
});

function formatMoney(amount) {
  return Number(amount || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Clean, isolated iframe printing:
 * Only sends the receipt HTML to printer, avoiding any background bleeding or screen artifacts.
 */
function printReceipt() {
  const receiptEl = document.getElementById('thermal-receipt-printable');
  if (!receiptEl) {
    window.print();
    return;
  }

  // Clean up any previously created print iframe
  const existingIframe = document.getElementById('pos-print-iframe');
  if (existingIframe && existingIframe.parentNode) {
    existingIframe.parentNode.removeChild(existingIframe);
  }

  const iframe = document.createElement('iframe');
  iframe.id = 'pos-print-iframe';
  iframe.style.position = 'fixed';
  iframe.style.right = '0';
  iframe.style.bottom = '0';
  iframe.style.width = '0';
  iframe.style.height = '0';
  iframe.style.border = 'none';
  iframe.style.zIndex = '-9999';
  document.body.appendChild(iframe);

  const is58 = paperSize.value === '58mm';
  const widthMm = is58 ? '58mm' : '80mm';
  const fontSize = is58 ? '10px' : '11.5px';

  const doc = iframe.contentWindow?.document || iframe.contentDocument;
  if (!doc) {
    window.print();
    return;
  }

  const receiptHtml = receiptEl.innerHTML;

  doc.open();
  doc.write(`
    <!DOCTYPE html>
    <html dir="rtl" lang="ar">
    <head>
      <meta charset="utf-8">
      <title>فاتورة - ${props.sale.invoice_number || 'إيصال'}</title>
      <style>
        @page {
          size: ${widthMm} auto;
          margin: 0;
        }
        @media print {
          html, body {
            width: ${widthMm};
            margin: 0;
            padding: 0;
            background: #ffffff !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
          }
        }
        * {
          box-sizing: border-box;
          margin: 0;
          padding: 0;
        }
        body {
          font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
          font-size: ${fontSize};
          line-height: 1.35;
          color: #000000;
          background: #ffffff;
          padding: 3mm 4mm;
          width: ${widthMm};
          margin: 0 auto;
        }
        .text-center { text-align: center; }
        .text-start { text-align: right; }
        .text-end { text-align: left; }
        .font-bold { font-weight: bold; }
        .font-black { font-weight: 900; }
        .border-b-2 { border-bottom: 2px dashed #000; }
        .border-t-2 { border-top: 2px dashed #000; }
        .border-b { border-bottom: 1px dashed #444; }
        .border-t { border-top: 1px solid #000; }
        .py-1 { padding-top: 4px; padding-bottom: 4px; }
        .py-1\\.5 { padding-top: 6px; padding-bottom: 6px; }
        .py-2 { padding-top: 8px; padding-bottom: 8px; }
        .pb-1 { padding-bottom: 4px; }
        .pb-2 { padding-bottom: 8px; }
        .pt-1 { padding-top: 4px; }
        .pt-1\\.5 { padding-top: 6px; }
        .pt-2 { padding-top: 8px; }
        .pt-3 { padding-top: 12px; }
        .my-0\\.5 { margin-top: 2px; margin-bottom: 2px; }
        .mt-0\\.5 { margin-top: 2px; }
        .mt-1 { margin-top: 4px; }
        .mt-2 { margin-top: 8px; }
        .flex { display: flex; }
        .justify-between { justify-content: space-between; }
        .items-center { align-items: center; }
        .w-full { width: 100%; }
        .w-\\[50\\%\\] { width: 50%; }
        .w-\\[25\\%\\] { width: 25%; }
        .bg-neutral-100 { background-color: #f3f4f6; }
        .rounded { border-radius: 4px; }
        .border { border: 1px solid #d1d5db; }
        svg { display: block; margin: 0 auto; }
      </style>
    </head>
    <body>
      ${receiptHtml}
    </body>
    </html>
  `);
  doc.close();

  setTimeout(() => {
    try {
      iframe.contentWindow?.focus();
      iframe.contentWindow?.print();
    } catch (err) {
      console.error('Print iframe error:', err);
      window.print();
    } finally {
      setTimeout(() => {
        if (document.body.contains(iframe)) {
          document.body.removeChild(iframe);
        }
      }, 1500);
    }
  }, 250);
}

function onReceiptKeyDown(e) {
  if (e.ctrlKey && e.key.toLowerCase() === 'p') {
    e.preventDefault();
    printReceipt();
  } else if (e.key === 'Escape') {
    e.preventDefault();
    handleClose();
  }
}

onMounted(() => {
  window.addEventListener('keydown', onReceiptKeyDown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onReceiptKeyDown);
});
</script>
