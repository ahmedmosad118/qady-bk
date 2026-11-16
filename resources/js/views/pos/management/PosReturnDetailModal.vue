<template>
  <AppDrawer :open="!!returnId" size="xl" @close="$emit('close')">
    <template #header>
      <div class="flex items-center justify-between gap-4 border-b border-[var(--border)] bg-[var(--card)] px-6 py-4">
        <div class="flex items-center gap-3 min-w-0">
          <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">
            <RotateCcw class="w-5 h-5" />
          </div>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-base font-extrabold text-[var(--text)]">تفاصيل فاتورة المرتجع</h2>
              <span
                v-if="returnRecord"
                class="rounded-md bg-[var(--bg)] border border-[var(--border)] px-2 py-0.5 font-mono text-xs font-black text-[var(--text)]"
              >
                {{ returnRecord.return_number }}
              </span>
            </div>
            <p class="mt-0.5 text-xs text-[var(--muted)]">
              {{ returnRecord ? formatDateTime(returnRecord.returned_at) : 'جاري التحميل...' }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
          <button
            v-if="returnRecord?.inventory_documents?.length"
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-xl border border-emerald-500/35 bg-emerald-500/10 px-3 text-xs font-bold text-emerald-700 dark:text-emerald-400 transition hover:bg-emerald-600 hover:text-white cursor-pointer"
            @click="openLinkedDoc(returnRecord.inventory_documents[0])"
          >
            <PackageCheck class="w-3.5 h-3.5" />
            <span>إذن الاستلام ({{ returnRecord.inventory_documents[0].document_number }})</span>
          </button>
          <button
            v-if="returnRecord?.sale?.id"
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--card)] cursor-pointer"
            @click="$emit('open-sale', returnRecord.sale.id)"
          >
            <Receipt class="w-3.5 h-3.5 text-[var(--muted)]" />
            <span>الفاتورة الأصلية</span>
          </button>
          <button
            type="button"
            class="flex h-9 w-9 items-center justify-center rounded-xl border border-[var(--border)] text-[var(--muted)] transition hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
            @click="$emit('close')"
          >
            <X class="w-4 h-4" />
          </button>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div v-if="returnRecord && !loading" class="flex items-center gap-1 overflow-x-auto border-b border-[var(--border)] bg-[var(--bg)]/40 px-6 pt-2">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          class="flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2.5 text-xs font-bold transition cursor-pointer"
          :class="activeTab === tab.id
            ? 'border-[var(--text)] text-[var(--text)]'
            : 'border-transparent text-[var(--muted)] hover:text-[var(--text)]'"
          @click="activeTab = tab.id"
        >
          <component :is="tab.icon" class="w-3.5 h-3.5" />
          <span>{{ tab.label }}</span>
          <span
            v-if="tab.badge !== undefined"
            class="rounded-full bg-[var(--card)] border border-[var(--border)] px-1.5 py-0.5 text-[10px] font-black leading-none"
          >
            {{ tab.badge }}
          </span>
        </button>
      </div>
    </template>

    <!-- Loading Body -->
    <div v-if="loading" class="flex flex-col items-center justify-center py-24 text-[var(--muted)]">
      <Loader2 class="w-8 h-8 animate-spin text-[var(--muted)] mb-3" />
      <p class="text-sm font-medium">جاري تحميل بيانات المرتجع...</p>
    </div>

    <!-- Error Body -->
    <div v-else-if="error" class="flex flex-col items-center justify-center py-20 text-center px-6">
      <div class="w-12 h-12 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center mb-3">
        <AlertCircle class="w-6 h-6" />
      </div>
      <p class="text-sm font-bold text-rose-600">{{ error }}</p>
    </div>

    <!-- Content -->
    <div v-else-if="returnRecord" class="space-y-6 p-6">
      <!-- TAB 1: Overview Info -->
      <div v-show="activeTab === 'info'" class="space-y-6">
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">إجمالي المرتجع المسترد</p>
            <p class="mt-1 font-mono text-lg font-black text-[var(--text)] tabular-nums">
              {{ Number(returnRecord.total_returned || 0).toFixed(2) }} <span class="text-xs font-normal text-[var(--muted)]">ج.م</span>
            </p>
          </div>

          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">عدد الأصناف المرتجعة</p>
            <p class="mt-1 font-mono text-lg font-black text-[var(--text)] tabular-nums">
              {{ returnRecord.items?.length || 0 }}
            </p>
          </div>

          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">طريقة الاسترداد</p>
            <p class="mt-1 text-sm font-black text-[var(--text)] flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
              {{ returnRecord.payment_method?.name || '—' }}
            </p>
          </div>

          <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
            <p class="text-[11px] font-bold text-[var(--muted)]">الفاتورة الأصلية</p>
            <button
              v-if="returnRecord.sale?.id"
              type="button"
              class="mt-1 font-mono text-sm font-black text-[var(--text)] hover:underline flex items-center gap-1 cursor-pointer"
              @click="$emit('open-sale', returnRecord.sale.id)"
            >
              <span>{{ returnRecord.sale.invoice_number }}</span>
              <ArrowUpLeft class="w-3.5 h-3.5 text-[var(--muted)]" />
            </button>
            <p v-else class="mt-1 text-sm font-bold text-[var(--muted)]">—</p>
          </div>
        </div>

        <!-- Key Value Table -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
          <div class="bg-[var(--bg)]/60 px-4 py-2.5 border-b border-[var(--border)] text-xs font-bold text-[var(--text)]">
            بيانات وتفاصيل المرتجع
          </div>
          <div class="divide-y divide-[var(--border)] text-xs">
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">رقم المرتجع</span>
              <span class="font-mono font-bold text-[var(--text)]">{{ returnRecord.return_number }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">تاريخ ووقت المرتجع</span>
              <span class="font-bold text-[var(--text)]">{{ formatDateTime(returnRecord.returned_at) }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">الموظف المسؤول (الكاشير)</span>
              <span class="font-bold text-[var(--text)]">{{ returnRecord.creator?.name || '—' }}</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">الوردية</span>
              <button
                v-if="returnRecord.shift?.id"
                type="button"
                class="font-mono font-bold text-[var(--text)] hover:underline cursor-pointer"
                @click="$emit('open-shift', returnRecord.shift.id)"
              >
                {{ returnRecord.shift.shift_number }}
              </button>
              <span v-else class="font-bold text-[var(--muted)]">—</span>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <span class="text-[var(--muted)] font-medium">إذن الاستلام المخزني المرتبط</span>
              <button
                v-if="returnRecord.inventory_documents?.length"
                type="button"
                class="inline-flex items-center gap-1.5 font-mono font-bold text-emerald-700 dark:text-emerald-400 hover:underline cursor-pointer"
                @click="openLinkedDoc(returnRecord.inventory_documents[0])"
              >
                <PackageCheck class="w-3.5 h-3.5" />
                <span>{{ returnRecord.inventory_documents[0].document_number }}</span>
              </button>
              <span v-else class="font-bold text-[var(--muted)]">—</span>
            </div>
            <div class="flex items-start justify-between gap-4 px-4 py-3">
              <span class="text-[var(--muted)] font-medium shrink-0">ملاحظات المرتجع</span>
              <span class="font-medium text-[var(--text)] text-left">{{ returnRecord.notes || 'لا توجد ملاحظات مدونة' }}</span>
            </div>
          </div>
        </div>

        <!-- Returned Items Table (Inline with Return Details) -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
          <div class="flex items-center justify-between px-4 py-3 bg-[var(--bg)]/60 border-b border-[var(--border)]">
            <div class="flex items-center gap-2">
              <BookOpen class="w-4 h-4 text-[var(--muted)]" />
              <span class="text-xs font-bold text-[var(--text)]">الكتب والأصناف المرتجعة</span>
            </div>
            <span class="rounded-full bg-[var(--card)] border border-[var(--border)] px-2 py-0.5 text-[11px] font-extrabold text-[var(--muted)]">
              {{ returnRecord.items?.length || 0 }} صنف
            </span>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
              <thead>
                <tr class="border-b border-[var(--border)] bg-[var(--bg)]/40 text-[11px] font-bold text-[var(--muted)]">
                  <th class="px-4 py-3">المنتج / الكتاب</th>
                  <th class="px-4 py-3 text-center">الكمية المرتجعة</th>
                  <th class="px-4 py-3">سعر الوحدة</th>
                  <th class="px-4 py-3">الخصم الموزع</th>
                  <th class="px-4 py-3">المبلغ المسترد</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)] text-xs">
                <tr v-for="item in returnRecord.items" :key="item.id" class="hover:bg-[var(--bg)]/40">
                  <td class="px-4 py-3">
                    <div class="font-bold text-[var(--text)]">{{ item.product?.name || `منتج #${item.product_id}` }}</div>
                    <div class="text-[11px] text-[var(--muted)] font-mono flex items-center gap-2 mt-0.5">
                      <span v-if="item.product?.barcode">Barcode: {{ item.product.barcode }}</span>
                    </div>
                  </td>
                  <td class="px-4 py-3 text-center font-mono font-extrabold text-[var(--text)]">
                    {{ Number(item.quantity).toFixed(item.quantity % 1 === 0 ? 0 : 2) }}
                  </td>
                  <td class="px-4 py-3 font-mono font-semibold text-[var(--muted)]">
                    {{ Number(item.unit_price || 0).toFixed(2) }} ج.م
                  </td>
                  <td class="px-4 py-3 font-mono font-semibold text-[var(--muted)]">
                    -{{ Number(item.allocated_discount || 0).toFixed(2) }} ج.م
                  </td>
                  <td class="px-4 py-3 font-mono font-black text-[var(--text)]">
                    {{ Number(item.refund_amount || 0).toFixed(2) }} ج.م
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- TAB 2: Original Sale & Customer -->
      <div v-show="activeTab === 'sale'" class="space-y-4">
        <div v-if="returnRecord.sale" class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 space-y-4">
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] pb-4">
            <div class="flex items-center gap-3">
              <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">
                <Receipt class="w-5 h-5" />
              </div>
              <div>
                <div class="flex items-center gap-2">
                  <span class="font-mono text-sm font-black text-[var(--text)]">{{ returnRecord.sale.invoice_number }}</span>
                  <span class="rounded-md bg-[var(--bg)] border border-[var(--border)] px-2 py-0.5 text-[10px] font-bold text-[var(--muted)]">
                    {{ returnRecord.sale.payment_method?.name || '—' }}
                  </span>
                </div>
                <p class="mt-0.5 text-xs text-[var(--muted)]">{{ formatDateTime(returnRecord.sale.sold_at) }}</p>
              </div>
            </div>

            <button
              type="button"
              class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 text-xs font-bold text-[var(--text)] transition hover:bg-[var(--bg)] cursor-pointer"
              @click="$emit('open-sale', returnRecord.sale.id)"
            >
              <span>عرض الفاتورة الأصلية</span>
              <ArrowUpLeft class="w-3.5 h-3.5 text-[var(--muted)]" />
            </button>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
            <div class="rounded-xl bg-[var(--bg)] p-3">
              <span class="text-[var(--muted)] block">العميل</span>
              <span class="mt-1 block font-bold text-[var(--text)]">
                {{ returnRecord.sale.customer?.name || 'عميل نقدي عابر' }}
              </span>
              <span v-if="returnRecord.sale.customer?.phone" class="mt-0.5 block font-mono text-[11px] text-[var(--muted)]">
                {{ returnRecord.sale.customer.phone }}
              </span>
            </div>
            <div class="rounded-xl bg-[var(--bg)] p-3">
              <span class="text-[var(--muted)] block">إجمالي الفاتورة الأصلية</span>
              <span class="mt-1 block font-mono text-sm font-black text-[var(--text)]">
                {{ Number(returnRecord.sale.grand_total || 0).toFixed(2) }} ج.م
              </span>
            </div>
            <div class="rounded-xl bg-[var(--bg)] p-3">
              <span class="text-[var(--muted)] block font-bold">قيمة هذا المرتجع</span>
              <span class="mt-1 block font-mono text-sm font-black text-[var(--text)]">
                {{ Number(returnRecord.total_returned || 0).toFixed(2) }} ج.م
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 4: Linked Inventory Document -->
      <div v-show="activeTab === 'inventory'" class="space-y-4">
        <div v-if="returnRecord.inventory_documents && returnRecord.inventory_documents.length > 0" class="space-y-4">
          <div
            v-for="doc in returnRecord.inventory_documents"
            :key="doc.id"
            class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
          >
            <div class="bg-[var(--bg)]/60 px-4 py-3 border-b border-[var(--border)] flex flex-wrap items-center justify-between gap-2">
              <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
                  <PackageCheck class="w-4 h-4" />
                </div>
                <div>
                  <button
                    type="button"
                    class="font-mono text-xs font-extrabold text-[var(--text)] hover:underline cursor-pointer flex items-center gap-1"
                    @click="openLinkedDoc(doc)"
                  >
                    <span>{{ doc.document_number }}</span>
                    <ArrowUpLeft class="w-3 h-3 text-[var(--muted)]" />
                  </button>
                  <p class="text-[11px] text-[var(--muted)]">
                    إذن استلام مخزني (مرتجع مبيعات POS)
                  </p>
                </div>
              </div>

              <div class="flex items-center gap-2">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/10 text-emerald-700 dark:text-emerald-400 border border-emerald-500/20">
                  {{ doc.status === 'posted' ? 'تم الترحيل للمخزون' : doc.status }}
                </span>
                <button
                  type="button"
                  class="px-2.5 py-1 rounded-lg bg-[var(--card)] border border-[var(--border)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
                  @click="openLinkedDoc(doc)"
                >
                  عرض تفاصيل المستند
                </button>
              </div>
            </div>

            <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs bg-[var(--bg)]/20 border-b border-[var(--border)]">
              <div>
                <span class="text-[var(--muted)] block">المستودع المستلم</span>
                <span class="font-bold text-[var(--text)] mt-0.5 block">
                  {{ doc.destination_warehouse?.name || '—' }}
                </span>
              </div>
              <div>
                <span class="text-[var(--muted)] block">منشئ المستند</span>
                <span class="font-bold text-[var(--text)] mt-0.5 block">{{ doc.creator?.name || '—' }}</span>
              </div>
              <div>
                <span class="text-[var(--muted)] block">عدد الأصناف المستلمة</span>
                <span class="font-bold text-[var(--text)] mt-0.5 block">{{ doc.items?.length || 0 }} أصناف</span>
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full text-right border-collapse">
                <thead>
                  <tr class="border-b border-[var(--border)] bg-[var(--bg)]/40 text-[10px] font-bold text-[var(--muted)]">
                    <th class="px-4 py-2">الكتاب / الصنف</th>
                    <th class="px-4 py-2 text-center">الكمية المرجعة للمخزن</th>
                    <th class="px-4 py-2">التكلفة</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)] text-xs">
                  <tr v-for="dItem in doc.items" :key="dItem.id">
                    <td class="px-4 py-2.5 font-bold text-[var(--text)]">{{ dItem.product?.name }}</td>
                    <td class="px-4 py-2.5 text-center font-mono font-extrabold text-emerald-600">+{{ Number(dItem.quantity) }}</td>
                    <td class="px-4 py-2.5 font-mono text-[var(--muted)]">{{ Number(dItem.unit_cost || 0).toFixed(2) }} ج.م</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div v-else class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-8 text-center">
          <PackageCheck class="w-8 h-8 text-[var(--muted)]/50 mx-auto mb-2" />
          <p class="text-xs font-bold text-[var(--text)]">لا توجد مستندات مخزنية مرتبطة</p>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-between border-t border-[var(--border)] bg-[var(--bg)]/60 px-6 py-3.5">
        <span class="text-[11px] font-medium text-[var(--muted)]">مكتبة القاضي · نظام إدارة المرتجعات والمخزون</span>
        <button
          type="button"
          class="px-5 py-2 rounded-xl border border-[var(--border)] bg-[var(--card)] text-xs font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
          @click="$emit('close')"
        >
          إغلاق
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import {
  RotateCcw,
  X,
  Loader2,
  AlertCircle,
  FileText,
  BookOpen,
  Receipt,
  PackageCheck,
  ArrowUpLeft,
} from 'lucide-vue-next';
import AppDrawer from '../../../components/ui/AppDrawer.vue';
import api from '../../../services/api';

const router = useRouter();

const props = defineProps({
  returnId: {
    type: [Number, String],
    required: true,
  },
});

const emit = defineEmits(['close', 'open-sale', 'open-shift', 'open-inventory-document']);

const returnRecord = ref(null);
const loading = ref(true);
const error = ref(null);
const activeTab = ref('info');

const tabs = computed(() => [
  { id: 'info', label: 'بيانات المرتجع', icon: FileText },
  { id: 'sale', label: 'الفاتورة الأصلية والعميل', icon: Receipt },
  { id: 'inventory', label: 'إذن الاستلام المخزني', icon: PackageCheck, badge: returnRecord.value?.inventory_documents?.length || 0 },
]);

async function loadReturnDetails() {
  if (!props.returnId) return;
  loading.value = true;
  error.value = null;
  try {
    const res = await api.get(`/pos/returns/${props.returnId}`);
    if (res.data?.success) {
      returnRecord.value = res.data.data;
    } else {
      error.value = 'تعذر جلب تفاصيل المرتجع.';
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'حدث خطأ أثناء تحميل تفاصيل المرتجع.';
  } finally {
    loading.value = false;
  }
}

function openLinkedDoc(doc) {
  if (!doc?.id) return;
  const typeSlugMap = {
    goods_delivery: 'goods-delivery',
    goods_receipt: 'goods-receipt',
    stock_issue: 'stock-issue',
    warehouse_transfer: 'warehouse-transfer',
    stock_adjustment: 'stock-adjustment',
    inventory_return: 'inventory-return',
    stock_write_off: 'stock-write-off',
    opening_balance: 'opening-balance',
  };
  const slug = typeSlugMap[doc.document_type] || 'goods-receipt';
  emit('close');
  router.push({
    path: `/inventory/${slug}`,
    query: {
      search: doc.document_number || undefined,
      open: String(doc.id),
    },
  });
}

function formatDateTime(val) {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

watch(() => props.returnId, () => {
  activeTab.value = 'info';
  loadReturnDetails();
}, { immediate: true });
</script>
