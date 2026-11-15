<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs select-none"
    dir="rtl"
    @keydown.esc="emit('close')"
  >
    <div
      class="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-3xl bg-[var(--card)] shadow-2xl border border-[var(--border)] text-[var(--text)] overflow-hidden"
    >
      <!-- Modal Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-[var(--border)] bg-[var(--card)] flex-none">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-[var(--crimson)]/10 text-[var(--crimson)] flex items-center justify-center font-bold">
            <Receipt class="w-5 h-5" />
          </div>
          <div>
            <div class="flex items-center gap-2">
              <h3 class="font-extrabold text-lg leading-tight">تفاصيل فاتورة البيع</h3>
              <span v-if="sale" class="font-mono text-sm px-2.5 py-0.5 rounded-lg bg-[var(--bar)] font-black text-[var(--crimson)]">
                {{ sale.invoice_number }}
              </span>
            </div>
            <p class="text-xs text-[var(--muted)] mt-0.5">
              مكتبة القاضي
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2">
          <!-- Print Button -->
          <button
            v-if="sale"
            type="button"
            class="h-9 px-3 rounded-xl bg-[var(--crimson)] text-white text-xs font-bold hover:bg-[var(--crimson-hover)] transition flex items-center gap-1.5 shadow-sm"
            @click="emit('print', sale)"
          >
            <Printer class="w-4 h-4" />
            <span>طباعة الإيصال</span>
          </button>

          <!-- Close Button -->
          <button
            type="button"
            class="w-9 h-9 rounded-xl border border-[var(--border)] text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)] transition flex items-center justify-center"
            @click="emit('close')"
          >
            <X class="w-5 h-5" />
          </button>
        </div>
      </div>

      <!-- Navigation Tabs -->
      <div class="flex items-center gap-1 px-6 pt-3 border-b border-[var(--border)] bg-[var(--card)] flex-none overflow-x-auto">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          type="button"
          :class="[
            'px-4 py-2.5 text-xs font-bold rounded-t-xl transition-all border-b-2 flex items-center gap-1.5 whitespace-nowrap cursor-pointer',
            activeTab === tab.id
              ? 'border-[var(--crimson)] text-[var(--crimson)] bg-[var(--crimson)]/5'
              : 'border-transparent text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'
          ]"
          @click="activeTab = tab.id"
        >
          <component :is="tab.icon" class="w-4 h-4" />
          <span>{{ tab.label }}</span>
          <span
            v-if="tab.badge !== undefined"
            class="px-1.5 py-0.2 rounded-full text-[10px] font-black"
            :class="activeTab === tab.id ? 'bg-[var(--crimson)] text-white' : 'bg-[var(--border)] text-[var(--muted)]'"
          >
            {{ tab.badge }}
          </span>
        </button>
      </div>

      <!-- Modal Body (Scrollable) -->
      <div class="flex-1 overflow-y-auto p-6 min-h-0 bg-[var(--bg)]/40">
        <!-- Loading State -->
        <div v-if="loading" class="py-16 text-center">
          <div class="inline-block w-8 h-8 border-3 border-[var(--crimson)] border-t-transparent rounded-full animate-spin"></div>
          <p class="text-xs font-bold text-[var(--muted)] mt-3">جاري تحميل بيانات الفاتورة...</p>
        </div>

        <div v-else-if="sale" class="space-y-6">
          <!-- Tab 1: Invoice Information -->
          <div v-if="activeTab === 'info'" class="space-y-4">
            <!-- Key Financial Summary Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">المجموع قبل الخصم</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block">
                  {{ Number(sale.subtotal || 0).toFixed(2) }} <span class="text-xs font-medium">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">إجمالي الخصومات</span>
                <b class="text-base font-extrabold text-amber-600 mt-1 block">
                  {{ totalDiscounts.toFixed(2) }} <span class="text-xs font-medium">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--crimson)]/30 bg-[var(--crimson)]/5">
                <span class="text-[11px] font-bold text-[var(--crimson)] block">الصافي النهائي</span>
                <b class="text-lg font-black text-[var(--crimson)] mt-1 block">
                  {{ Number(sale.grand_total || 0).toFixed(2) }} <span class="text-xs font-medium">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">طريقة السداد</span>
                <div class="flex items-center gap-1.5 mt-1">
                  <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                  <b class="text-sm font-bold text-[var(--text)]">{{ sale.payment_method?.name || 'نقدي' }}</b>
                </div>
              </div>
            </div>

            <!-- Detailed Specs Table -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <div class="px-4 py-3 bg-[var(--bar)]/50 border-b border-[var(--border)]">
                <h4 class="text-xs font-black text-[var(--text)]">بيانات وتفاصيل العملية</h4>
              </div>
              <div class="divide-y divide-[var(--border)] text-xs">
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">رقم الفاتورة:</span>
                  <span class="col-span-2 font-mono font-bold">{{ sale.invoice_number }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">تاريخ ووقت البيع:</span>
                  <span class="col-span-2 font-mono font-bold" dir="ltr">{{ formatDateTime(sale.sold_at) }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">خصم الفاتورة العام:</span>
                  <span class="col-span-2 font-bold text-amber-600">
                    {{ Number(sale.invoice_discount_amount || 0).toFixed(2) }} ج.م
                    <span v-if="sale.invoice_discount_value > 0" class="text-[11px] text-[var(--muted)]">
                      ({{ sale.invoice_discount_value }} {{ sale.invoice_discount_type === 'percentage' ? '%' : 'ج.م' }})
                    </span>
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">خصومات الأصناف المباشرة:</span>
                  <span class="col-span-2 font-bold text-amber-600">
                    {{ Number(sale.items_discount_total || 0).toFixed(2) }} ج.م
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">المعرف الفريد (UUID):</span>
                  <span class="col-span-2 font-mono text-[11px] text-[var(--muted)]">{{ sale.client_uuid || '—' }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">ملاحظات الفاتورة:</span>
                  <span class="col-span-2 text-[var(--text)]">{{ sale.notes || 'لا توجد ملاحظات مدونة' }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Tab 2: Sold Items / Books -->
          <div v-else-if="activeTab === 'items'" class="space-y-3">
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <table class="w-full text-start text-xs">
                <thead>
                  <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                    <th class="px-3.5 py-2.5 text-start">#</th>
                    <th class="px-3.5 py-2.5 text-start">الكتاب / الصنف</th>
                    <th class="px-3.5 py-2.5 text-start">الكود / الباركود</th>
                    <th class="px-3.5 py-2.5 text-center">الكمية</th>
                    <th class="px-3.5 py-2.5 text-end">سعر الوحدة</th>
                    <th class="px-3.5 py-2.5 text-end">الخصم</th>
                    <th class="px-3.5 py-2.5 text-end">الإجمالي</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr
                    v-for="(item, idx) in sale.items"
                    :key="item.id || idx"
                    class="hover:bg-[var(--bg)]/50 transition-colors"
                  >
                    <td class="px-3.5 py-3 font-mono text-[var(--muted)]">{{ idx + 1 }}</td>
                    <td class="px-3.5 py-3">
                      <b class="text-[var(--text)] block">{{ item.product_snapshot?.name || item.product?.name || 'صنف غير مسمى' }}</b>
                      <span v-if="item.original_unit_price && item.original_unit_price !== item.unit_price" class="text-[10px] text-amber-600 block">
                        تعديل سعر يدوي (السعر الأصلي: {{ item.original_unit_price }} ج.م)
                      </span>
                    </td>
                    <td class="px-3.5 py-3 font-mono text-[11px] text-[var(--muted)]">
                      <div>{{ item.product_snapshot?.sku || item.product?.sku || '—' }}</div>
                      <div class="text-[10px]">{{ item.product_snapshot?.barcode || item.product?.barcode || '' }}</div>
                    </td>
                    <td class="px-3.5 py-3 text-center font-bold text-[var(--text)] font-mono">
                      {{ Number(item.quantity || 0) }}
                    </td>
                    <td class="px-3.5 py-3 text-end font-mono">
                      {{ Number(item.unit_price || 0).toFixed(2) }}
                    </td>
                    <td class="px-3.5 py-3 text-end font-mono text-amber-600">
                      {{ Number(item.discount_amount || 0) > 0 ? Number(item.discount_amount).toFixed(2) : '—' }}
                    </td>
                    <td class="px-3.5 py-3 text-end font-mono font-black text-[var(--crimson)]">
                      {{ Number(item.total || 0).toFixed(2) }} ج.م
                    </td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr class="bg-[var(--bar)]/50 font-bold border-t border-[var(--border)]">
                    <td colspan="3" class="px-3.5 py-3 text-start">إجمالي الأصناف: {{ sale.items?.length || 0 }} صنف</td>
                    <td class="px-3.5 py-3 text-center font-mono">{{ totalQuantity }} قطعة</td>
                    <td colspan="2" class="px-3.5 py-3 text-end text-[var(--muted)]">المجموع:</td>
                    <td class="px-3.5 py-3 text-end font-mono font-black text-[var(--text)]">
                      {{ Number(sale.subtotal || 0).toFixed(2) }} ج.م
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <!-- Tab 3: Customer -->
          <div v-else-if="activeTab === 'customer'" class="space-y-4">
            <div v-if="sale.customer" class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5">
              <div class="flex items-center gap-3 pb-4 border-b border-[var(--border)]">
                <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-600 flex items-center justify-center font-bold">
                  <User class="w-6 h-6" />
                </div>
                <div>
                  <h4 class="font-extrabold text-base">{{ sale.customer.name }}</h4>
                  <p class="text-xs text-[var(--muted)]">عميل مسجل لدى النظام</p>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 text-xs">
                <div>
                  <span class="text-[var(--muted)] font-semibold block">رقم الهاتف:</span>
                  <span class="font-mono font-bold mt-1 block">{{ sale.customer.phone || 'غير مسجل' }}</span>
                </div>
                <div>
                  <span class="text-[var(--muted)] font-semibold block">البريد الإلكتروني:</span>
                  <span class="font-mono mt-1 block">{{ sale.customer.email || 'غير مسجل' }}</span>
                </div>
                <div>
                  <span class="text-[var(--muted)] font-semibold block">الرقم الضريبي:</span>
                  <span class="font-mono mt-1 block">{{ sale.customer.tax_number || '—' }}</span>
                </div>
                <div>
                  <span class="text-[var(--muted)] font-semibold block">نوع الحساب:</span>
                  <span class="mt-1 block font-bold">
                    {{ sale.customer.type === 'company' ? 'شركة / جهة اعتبارية' : 'فرد' }}
                  </span>
                </div>
              </div>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center">
              <div class="w-14 h-14 mx-auto rounded-full bg-[var(--bar)] flex items-center justify-center text-[var(--muted)] mb-3">
                <UserX class="w-7 h-7" />
              </div>
              <h4 class="font-bold text-sm text-[var(--text)]">عميل نقدي (Walk-in Customer)</h4>
              <p class="text-xs text-[var(--muted)] max-w-md mx-auto mt-1 leading-relaxed">
                تم إصدار هذه الفاتورة لعميل مباشر عبر نقطة البيع دون تسجيل حساب أو ربط ببيانات عميل دائم.
              </p>
            </div>
          </div>

          <!-- Tab 4: User & Shift -->
          <div v-else-if="activeTab === 'user'" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <!-- Creator User -->
              <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4">
                <div class="flex items-center gap-2.5 pb-3 border-b border-[var(--border)]">
                  <UserCheck class="w-5 h-5 text-[var(--crimson)]" />
                  <h4 class="font-bold text-xs">الكاشير / منشئ الفاتورة</h4>
                </div>
                <div class="mt-3 space-y-2 text-xs">
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">الاسم:</span>
                    <b class="text-[var(--text)]">{{ sale.creator?.name || 'غير معروف' }}</b>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">البريد الإلكتروني:</span>
                    <span class="font-mono text-[var(--muted)]">{{ sale.creator?.email || '—' }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">تاريخ الإنشاء:</span>
                    <span class="font-mono" dir="ltr">{{ formatDateTime(sale.created_at) }}</span>
                  </div>
                </div>
              </div>

              <!-- Shift Reference -->
              <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4">
                <div class="flex items-center justify-between pb-3 border-b border-[var(--border)]">
                  <div class="flex items-center gap-2.5">
                    <Clock class="w-5 h-5 text-amber-600" />
                    <h4 class="font-bold text-xs">الوردية المرتبطة</h4>
                  </div>
                  <span
                    v-if="sale.shift"
                    class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                    :class="sale.shift.closed_at ? 'bg-[var(--bar)] text-[var(--muted)]' : 'bg-emerald-50 text-emerald-700'"
                  >
                    {{ sale.shift.closed_at ? 'مغلقة' : 'مفتوحة حالياً' }}
                  </span>
                </div>

                <div v-if="sale.shift" class="mt-3 space-y-2 text-xs">
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">رقم الوردية:</span>
                    <b class="font-mono text-[var(--crimson)]">{{ sale.shift.shift_number }}</b>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">فتح الوردية:</span>
                    <span class="font-mono" dir="ltr">{{ formatDateTime(sale.shift.opened_at) }}</span>
                  </div>
                  <div v-if="sale.shift.closed_at" class="flex justify-between">
                    <span class="text-[var(--muted)]">إغلاق الوردية:</span>
                    <span class="font-mono" dir="ltr">{{ formatDateTime(sale.shift.closed_at) }}</span>
                  </div>
                  <div class="pt-2">
                    <button
                      type="button"
                      class="w-full py-1.5 rounded-xl border border-[var(--border)] bg-[var(--bar)] hover:bg-[var(--border)] text-xs font-bold transition flex items-center justify-center gap-1"
                      @click="emit('open-shift', sale.shift.id)"
                    >
                      <Layers class="w-3.5 h-3.5" />
                      <span>عرض تفاصيل الوردية</span>
                    </button>
                  </div>
                </div>
                <div v-else class="mt-4 text-xs text-[var(--muted)] text-center py-2">
                  لا توجد معلومات وردية مسجلة لهذه الفاتورة.
                </div>
              </div>
            </div>
          </div>

          <!-- Tab 5: Inventory Document -->
          <div v-else-if="activeTab === 'inventory'" class="space-y-4">
            <div
              v-if="sale.inventory_documents && sale.inventory_documents.length > 0"
              class="space-y-4"
            >
              <div
                v-for="invDoc in sale.inventory_documents"
                :key="invDoc.id"
                class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 space-y-4"
              >
                <!-- Document Top Meta -->
                <div class="flex flex-wrap items-center justify-between gap-3 pb-3 border-b border-[var(--border)]">
                  <div class="flex items-center gap-2.5">
                    <div class="w-9 h-9 rounded-xl bg-purple-500/10 text-purple-600 flex items-center justify-center font-bold">
                      <Package class="w-5 h-5" />
                    </div>
                    <div>
                      <div class="flex items-center gap-2">
                        <b class="text-sm font-bold text-[var(--text)]">{{ docTypeLabel(invDoc.document_type) }}</b>
                        <span class="font-mono text-xs text-[var(--crimson)] font-black px-2 py-0.5 rounded bg-[var(--bar)]">
                          {{ invDoc.document_number }}
                        </span>
                      </div>
                      <span class="text-[11px] text-[var(--muted)]">
                        {{ docTypeLabel(invDoc.document_type) }} · تاريخ المستند: {{ formatDate(invDoc.document_date) }}
                      </span>
                    </div>
                  </div>

                  <div class="flex items-center gap-2">
                    <span
                      class="px-2.5 py-1 rounded-full text-xs font-bold bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300"
                    >
                      {{ invDoc.status === 'posted' ? 'مرحّل نهائياً' : invDoc.status }}
                    </span>

                    <button
                      type="button"
                      class="h-8 px-3 rounded-lg border border-[var(--border)] bg-[var(--bar)] hover:bg-[var(--border)] text-xs font-bold transition flex items-center gap-1.5 cursor-pointer"
                      @click="emit('open-inventory-document', invDoc.id)"
                    >
                      <ExternalLink class="w-3.5 h-3.5" />
                      <span>عرض المستند الكامل</span>
                    </button>
                  </div>
                </div>

                <!-- Warehouse & Details -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                  <div class="p-2.5 rounded-xl bg-[var(--bar)]/50">
                    <span class="text-[var(--muted)] block">المخزن المنصرف منه:</span>
                    <b class="text-[var(--text)] mt-0.5 block">{{ invDoc.source_warehouse?.name || 'المخزن الرئيسي' }}</b>
                  </div>
                  <div class="p-2.5 rounded-xl bg-[var(--bar)]/50">
                    <span class="text-[var(--muted)] block">بواسطة:</span>
                    <b class="text-[var(--text)] mt-0.5 block">{{ invDoc.creator?.name || sale.creator?.name || 'الكاشير' }}</b>
                  </div>
                  <div class="p-2.5 rounded-xl bg-[var(--bar)]/50">
                    <span class="text-[var(--muted)] block">حالة حركة المخزون:</span>
                    <b class="text-emerald-600 mt-0.5 block">تم خصم الأرصدة فعلياً</b>
                  </div>
                </div>

                <!-- Document Items -->
                <div v-if="invDoc.items && invDoc.items.length > 0" class="border border-[var(--border)] rounded-xl overflow-hidden">
                  <table class="w-full text-xs text-start">
                    <thead>
                      <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                        <th class="px-3 py-2 text-start">الصنف</th>
                        <th class="px-3 py-2 text-start">الكود (SKU)</th>
                        <th class="px-3 py-2 text-center">الكمية المصروفة</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                      <tr v-for="item in invDoc.items" :key="item.id" class="hover:bg-[var(--bg)]/50">
                        <td class="px-3 py-2 font-medium">{{ item.product?.name || 'صنف' }}</td>
                        <td class="px-3 py-2 font-mono text-[var(--muted)]">{{ item.product?.sku || '—' }}</td>
                        <td class="px-3 py-2 text-center font-bold font-mono">{{ Number(item.quantity) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <!-- Empty State -->
            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center">
              <div class="w-14 h-14 mx-auto rounded-full bg-[var(--bar)] flex items-center justify-center text-[var(--muted)] mb-3">
                <FileQuestion class="w-7 h-7" />
              </div>
              <h4 class="font-bold text-sm text-[var(--text)]">لا يوجد مستند مخزني مرتبط</h4>
              <p class="text-xs text-[var(--muted)] max-w-md mx-auto mt-1 leading-relaxed">
                لم يتم ربط أي مستند صرف مخزني بهذه الفاتورة مباشرة، أو تم تسجيل الفاتورة عبر قناة استثنائية.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal Footer -->
      <div class="flex items-center justify-between px-6 py-3.5 border-t border-[var(--border)] bg-[var(--card)] flex-none">
        <span class="text-xs text-[var(--muted)]">
          مكتبة القاضي · نظام إدارة نقاط البيع والمخازن
        </span>

        <button
          type="button"
          class="h-9 px-4 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:bg-[var(--bg)] transition"
          @click="emit('close')"
        >
          إغلاق
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import {
  Receipt,
  Printer,
  X,
  FileText,
  BookOpen,
  User,
  UserCheck,
  UserX,
  Clock,
  Layers,
  Package,
  ExternalLink,
  FileQuestion,
} from 'lucide-vue-next';
import api from '../../../services/api';

const props = defineProps({
  saleId: {
    type: [Number, String],
    required: true,
  },
  initialSale: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'print', 'open-shift', 'open-inventory-document']);

const sale = ref(props.initialSale);
const loading = ref(!props.initialSale);
const activeTab = ref('info');

const tabs = computed(() => [
  { id: 'info', label: 'بيانات الفاتورة', icon: FileText },
  { id: 'items', label: 'الكتب والأصناف المباعة', icon: BookOpen, badge: sale.value?.items?.length },
  { id: 'customer', label: 'العميل', icon: User },
  { id: 'user', label: 'الكاشير والوردية', icon: Clock },
  { id: 'inventory', label: 'المستند المخزني', icon: Package, badge: sale.value?.inventory_documents?.length },
]);

const totalDiscounts = computed(() => {
  if (!sale.value) return 0;
  const itemsDisc = Number(sale.value.items_discount_total || 0);
  const invDisc = Number(sale.value.invoice_discount_amount || 0);
  return itemsDisc + invDisc;
});

const totalQuantity = computed(() => {
  if (!sale.value?.items) return 0;
  return sale.value.items.reduce((acc, item) => acc + Number(item.quantity || 0), 0);
});

async function fetchSaleDetails() {
  loading.value = true;
  try {
    const res = await api.get(`/pos/sales/${props.saleId}`);
    if (res.data?.success) {
      sale.value = res.data.data;
    }
  } catch (err) {
    console.error('Failed to load sale details:', err);
  } finally {
    loading.value = false;
  }
}

function docTypeLabel(type) {
  const map = {
    goods_delivery: 'إذن تسليم مخزني',
    goods_receipt: 'إذن استلام مخزني',
    stock_issue: 'إذن صرف داخلي',
    warehouse_transfer: 'إذن تحويل مخزني',
    stock_adjustment: 'إذن تسوية جردية',
    stock_write_off: 'إذن إهلاك',
  };
  return map[type] || type || 'مستند مخزني';
}

function formatDateTime(val) {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatDate(val) {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleDateString('ar-EG', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

onMounted(() => {
  fetchSaleDetails();
});
</script>
