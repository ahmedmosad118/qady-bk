<template>
  <AppDrawer
    :open="!!saleId"
    size="xl"
    @close="emit('close')"
  >
    <!-- Drawer Header -->
    <template #header>
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)] flex items-center justify-center font-bold shrink-0">
            <Receipt class="w-5 h-5" />
          </div>
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="font-extrabold text-base sm:text-lg leading-tight text-[var(--text)]">تفاصيل فاتورة البيع</h3>
              <span v-if="sale" class="font-mono text-sm px-2.5 py-0.5 rounded-lg bg-[var(--bg)] border border-[var(--border)] font-black text-[var(--text)]" dir="ltr">
                {{ sale.invoice_number }}
              </span>
            </div>
            <p class="text-xs text-[var(--muted)] mt-0.5">
              مكتبة القاضي
            </p>
          </div>
        </div>

        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
          @click="emit('close')"
        >
          <X class="w-5 h-5" />
        </button>
      </div>
    </template>

    <!-- Sticky Actions & Navigation Tabs -->
    <template #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-1 overflow-x-auto">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            :class="[
              'px-3.5 py-2 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5 whitespace-nowrap cursor-pointer',
              activeTab === tab.id
                ? 'bg-[var(--card)] text-[var(--text)] border border-[var(--border)] shadow-xs'
                : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--card)] border border-transparent'
            ]"
            @click="activeTab = tab.id"
          >
            <component :is="tab.icon" class="w-3.5 h-3.5" />
            <span>{{ tab.label }}</span>
            <span
              v-if="tab.badge !== undefined"
              class="px-1.5 py-0.5 rounded-full text-[10px] font-black leading-none"
              :class="activeTab === tab.id ? 'bg-[var(--text)] text-[var(--card)]' : 'bg-[var(--border)] text-[var(--muted)]'"
            >
              {{ tab.badge }}
            </span>
          </button>
        </div>

        <!-- Quick Actions -->
        <div v-if="sale" class="flex items-center gap-2 shrink-0">
          <button
            v-if="sale.inventory_documents && sale.inventory_documents.length > 0"
            type="button"
            class="h-9 px-3.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:bg-[var(--bg)] transition flex items-center gap-1.5 shadow-xs cursor-pointer"
            @click="goToInventoryDocument(sale.inventory_documents[0])"
          >
            <PackageCheck class="w-4 h-4 text-[var(--muted)]" />
            <span>المستند المخزني ({{ sale.inventory_documents[0].document_number }})</span>
            <ExternalLink class="w-3 h-3 text-[var(--muted)]" />
          </button>

          <button
            type="button"
            class="h-9 px-3.5 rounded-xl bg-[var(--text)] text-[var(--card)] text-xs font-bold hover:opacity-90 transition flex items-center gap-1.5 shadow-xs cursor-pointer shrink-0"
            @click="emit('print', sale)"
          >
            <Printer class="w-4 h-4" />
            <span>طباعة الإيصال</span>
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Body Content -->
    <div>
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
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block">
                  {{ totalDiscounts.toFixed(2) }} <span class="text-xs font-medium text-[var(--muted)]">ج.م</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">الصافي النهائي</span>
                <b class="text-lg font-black text-[var(--text)] mt-1 block">
                  {{ Number(sale.grand_total || 0).toFixed(2) }} <span class="text-xs font-medium text-[var(--muted)]">ج.م</span>
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

            <!-- Returned Items Alert Banner (if returns exist on this sale) -->
            <div
              v-if="sale.returns && sale.returns.length > 0"
              class="rounded-2xl border border-[var(--border)] bg-[var(--bg)]/60 p-4 space-y-3"
            >
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2.5">
                  <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[var(--card)] border border-[var(--border)] text-[var(--muted)]">
                    <RotateCcw class="w-4 h-4" />
                  </div>
                  <div>
                    <h4 class="text-xs font-black text-[var(--text)]">
                      تم تنفيذ {{ sale.returns.length }} {{ sale.returns.length === 1 ? 'مرتجع' : 'مرتجعات' }} على هذه الفاتورة
                      <span class="ms-1.5 rounded-full px-2 py-0.5 text-[10px] font-extrabold bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20">
                        {{ isFullyReturned ? 'مرتجع كامل' : 'مرتجع جزئي' }}
                      </span>
                    </h4>
                    <p class="text-[11px] font-semibold text-[var(--muted)] mt-0.5">
                      إجمالي المسترد: <b class="font-mono text-[var(--text)]">{{ totalReturned.toFixed(2) }} ج.م</b>
                      <span class="mx-1.5">·</span>
                      الصافي بعد المرتجع: <b class="font-mono text-[var(--text)]">{{ netAfterReturns.toFixed(2) }} ج.م</b>
                    </p>
                  </div>
                </div>

                <button
                  type="button"
                  class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
                  @click="activeTab = 'returns'"
                >
                  <span>تفاصيل المرتجعات</span>
                  <ExternalLink class="w-3 h-3 text-[var(--muted)]" />
                </button>
              </div>

              <!-- Returned items summary pills -->
              <div v-if="returnedSummaryItems.length > 0" class="flex flex-wrap gap-2 pt-2 border-t border-[var(--border)]">
                <span
                  v-for="rItem in returnedSummaryItems"
                  :key="rItem.key"
                  class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-2.5 py-1 text-[11px] font-bold text-[var(--text)]"
                >
                  <span class="font-bold">{{ rItem.productName }}</span>
                  <span class="font-mono text-[10px] px-1.5 py-0.5 rounded bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">
                    × {{ rItem.quantity }}
                  </span>
                  <span class="font-mono text-[10px] text-[var(--muted)]">
                    ({{ rItem.refundAmount.toFixed(2) }} ج.م)
                  </span>
                </span>
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
                  <span class="col-span-2 font-bold text-[var(--text)]">
                    {{ Number(sale.invoice_discount_amount || 0).toFixed(2) }} ج.م
                    <span v-if="sale.invoice_discount_value > 0" class="text-[11px] text-[var(--muted)]">
                      ({{ sale.invoice_discount_value }} {{ sale.invoice_discount_type === 'percentage' ? '%' : 'ج.م' }})
                    </span>
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">خصومات الأصناف المباشرة:</span>
                  <span class="col-span-2 font-bold text-[var(--text)]">
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

            <!-- Sold Books / Items Table (Inline with Invoice Details) -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <div class="flex items-center justify-between px-4 py-3 bg-[var(--bar)]/50 border-b border-[var(--border)]">
                <div class="flex items-center gap-2">
                  <BookOpen class="w-4 h-4 text-[var(--muted)]" />
                  <h4 class="text-xs font-black text-[var(--text)]">الكتب والأصناف المباعة</h4>
                </div>
                <span class="rounded-full bg-[var(--card)] border border-[var(--border)] px-2 py-0.5 text-[11px] font-extrabold text-[var(--muted)]">
                  {{ sale.items?.length || 0 }} صنف
                </span>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-start text-xs">
                  <thead>
                    <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                      <th class="px-3.5 py-2.5 text-start">#</th>
                      <th class="px-3.5 py-2.5 text-start">الكتاب / الصنف</th>
                      <th class="px-3.5 py-2.5 text-start">الباركود</th>
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
                        <span v-if="item.original_unit_price && item.original_unit_price !== item.unit_price" class="text-[10px] text-[var(--muted)] block">
                          تعديل سعر يدوي (السعر الأصلي: {{ item.original_unit_price }} ج.م)
                        </span>
                        <span
                          v-if="returnedQtyBySaleItemId[item.id] > 0"
                          class="mt-1 inline-flex items-center gap-1 rounded-md border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-400"
                        >
                          <RotateCcw class="w-2.5 h-2.5" />
                          <span>مرتجع: {{ returnedQtyBySaleItemId[item.id] }} من {{ Number(item.quantity || 0) }}</span>
                        </span>
                      </td>
                      <td class="px-3.5 py-3 font-mono text-[11px] text-[var(--muted)]">
                        <div>{{ item.product_snapshot?.barcode || item.product?.barcode || '—' }}</div>
                      </td>
                      <td class="px-3.5 py-3 text-center font-bold text-[var(--text)] font-mono">
                        {{ Number(item.quantity || 0) }}
                      </td>
                      <td class="px-3.5 py-3 text-end font-mono">
                        {{ Number(item.unit_price || 0).toFixed(2) }}
                      </td>
                      <td class="px-3.5 py-3 text-end font-mono text-[var(--muted)]">
                        {{ Number(item.discount_amount || 0) > 0 ? Number(item.discount_amount).toFixed(2) : '—' }}
                      </td>
                      <td class="px-3.5 py-3 text-end font-mono font-black text-[var(--text)]">
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
          </div>

          <!-- Tab 2: Returns on this Sale -->
          <div v-else-if="activeTab === 'returns'" class="space-y-4">
            <div v-if="sale.returns && sale.returns.length > 0" class="space-y-4">
              <!-- Summary strip -->
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
                  <span class="text-[11px] font-bold text-[var(--muted)] block">عدد فواتير المرتجع</span>
                  <b class="mt-1 block font-mono text-base font-black text-[var(--text)]">{{ sale.returns.length }}</b>
                </div>
                <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
                  <span class="text-[11px] font-bold text-[var(--muted)] block">إجمالي المبالغ المستردة</span>
                  <b class="mt-1 block font-mono text-base font-black text-[var(--text)]">
                    {{ totalReturned.toFixed(2) }} <span class="text-xs font-sans font-medium text-[var(--muted)]">ج.م</span>
                  </b>
                </div>
                <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
                  <span class="text-[11px] font-bold text-[var(--muted)] block">صافي الفاتورة بعد المرتجعات</span>
                  <b class="mt-1 block font-mono text-base font-black text-[var(--text)]">
                    {{ netAfterReturns.toFixed(2) }} <span class="text-xs font-sans font-medium text-[var(--muted)]">ج.م</span>
                  </b>
                </div>
              </div>

              <!-- Each Return Card -->
              <div
                v-for="ret in sale.returns"
                :key="ret.id"
                class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden"
              >
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] bg-[var(--bar)]/60 px-4 py-3">
                  <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)]">
                      <RotateCcw class="w-4 h-4" />
                    </div>
                    <div>
                      <button
                        type="button"
                        class="font-mono text-xs font-black text-[var(--text)] hover:underline flex items-center gap-1 cursor-pointer"
                        @click="emit('open-return', ret.id)"
                      >
                        <span>{{ ret.return_number }}</span>
                        <ExternalLink class="w-3 h-3 text-[var(--muted)]" />
                      </button>
                      <p class="text-[11px] text-[var(--muted)]">
                        {{ formatDateTime(ret.returned_at) }}
                        <span v-if="ret.creator?.name"> · بواسطة: {{ ret.creator.name }}</span>
                        <span v-if="ret.payment_method?.name"> · {{ ret.payment_method.name }}</span>
                      </p>
                    </div>
                  </div>

                  <div class="flex items-center gap-2">
                    <span class="font-mono text-xs font-black text-[var(--text)]">
                      -{{ Number(ret.total_returned || 0).toFixed(2) }} ج.م
                    </span>
                    <button
                      type="button"
                      class="h-8 px-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer flex items-center gap-1"
                      @click="emit('open-return', ret.id)"
                    >
                      <ExternalLink class="w-3 h-3 text-[var(--muted)]" />
                      <span>عرض فاتورة المرتجع</span>
                    </button>
                  </div>
                </div>

                <div v-if="ret.notes" class="px-4 py-2 text-xs text-[var(--muted)] bg-[var(--bg)]/40 border-b border-[var(--border)]">
                  ملاحظات: {{ ret.notes }}
                </div>

                <div class="overflow-x-auto">
                  <table class="w-full text-xs text-start">
                    <thead>
                      <tr class="border-b border-[var(--border)] bg-[var(--bg)]/40 text-[10px] font-bold text-[var(--muted)]">
                        <th class="px-4 py-2 text-start">الكتاب / الصنف المرتجع</th>
                        <th class="px-4 py-2 text-center">الكمية المرتجعة</th>
                        <th class="px-4 py-2 text-end">سعر الوحدة</th>
                        <th class="px-4 py-2 text-end">الخصم الموزع</th>
                        <th class="px-4 py-2 text-end">المبلغ المسترد</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                      <tr v-for="rItem in ret.items" :key="rItem.id" class="hover:bg-[var(--bg)]/40">
                        <td class="px-4 py-2.5 font-bold text-[var(--text)]">
                          {{ rItem.product?.name || `منتج #${rItem.product_id}` }}
                          <span v-if="rItem.product?.barcode" class="block font-mono text-[10px] font-normal text-[var(--muted)]">
                            {{ rItem.product.barcode }}
                          </span>
                        </td>
                        <td class="px-4 py-2.5 text-center font-mono font-extrabold text-[var(--text)]">
                          {{ Number(rItem.quantity || 0) }}
                        </td>
                        <td class="px-4 py-2.5 text-end font-mono text-[var(--muted)]">
                          {{ Number(rItem.unit_price || 0).toFixed(2) }} ج.م
                        </td>
                        <td class="px-4 py-2.5 text-end font-mono text-[var(--muted)]">
                          -{{ Number(rItem.allocated_discount || 0).toFixed(2) }} ج.م
                        </td>
                        <td class="px-4 py-2.5 text-end font-mono font-black text-[var(--text)]">
                          {{ Number(rItem.refund_amount || 0).toFixed(2) }} ج.م
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center">
              <div class="w-14 h-14 mx-auto rounded-full bg-[var(--bar)] flex items-center justify-center text-[var(--muted)] mb-3">
                <RotateCcw class="w-7 h-7" />
              </div>
              <h4 class="font-bold text-sm text-[var(--text)]">لا توجد مرتجعات مسجلة</h4>
              <p class="text-xs text-[var(--muted)] max-w-md mx-auto mt-1">
                لم يتم تسجيل أي مرتجعات على هذه الفاتورة حتى الآن.
              </p>
            </div>
          </div>

          <!-- Tab 4: Customer -->
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
                  <UserCheck class="w-5 h-5 text-[var(--muted)]" />
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
                    <Clock class="w-5 h-5 text-[var(--muted)]" />
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
                    <b class="font-mono text-[var(--text)]">{{ sale.shift.shift_number }}</b>
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
                        <span class="font-mono text-xs text-[var(--text)] font-black px-2 py-0.5 rounded bg-[var(--bar)]">
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
                      class="h-8 px-3 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] hover:bg-[var(--bg)] text-xs font-bold transition flex items-center gap-1.5 cursor-pointer"
                      @click="goToInventoryDocument(invDoc)"
                    >
                      <ExternalLink class="w-3.5 h-3.5 text-[var(--muted)]" />
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
                        <th class="px-3 py-2 text-start">الباركود</th>
                        <th class="px-3 py-2 text-center">الكمية المصروفة</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                      <tr v-for="item in invDoc.items" :key="item.id" class="hover:bg-[var(--bg)]/50">
                        <td class="px-3 py-2 font-medium">{{ item.product?.name || 'صنف' }}</td>
                        <td class="px-3 py-2 font-mono text-[var(--muted)]">{{ item.product?.barcode || '—' }}</td>
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

    <!-- Drawer Footer -->
    <template #footer>
      <div class="flex items-center justify-between gap-3">
        <span class="text-xs text-[var(--muted)]">
          مكتبة القاضي · نظام إدارة نقاط البيع والمخازن
        </span>

        <button
          type="button"
          class="h-9 px-4 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:bg-[var(--bg)] transition cursor-pointer"
          @click="emit('close')"
        >
          إغلاق
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
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
  PackageCheck,
  ExternalLink,
  FileQuestion,
  RotateCcw,
} from 'lucide-vue-next';
import api from '../../../services/api';
import AppDrawer from '../../../components/ui/AppDrawer.vue';

const router = useRouter();

const props = defineProps({
  saleId: {
    type: [Number, String],
    required: true,
  },
  initialSale: {
    type: Object,
    default: null,
  },
  initialTab: {
    type: String,
    default: 'info',
  },
});

const emit = defineEmits(['close', 'print', 'open-shift', 'open-return', 'open-inventory-document']);

const sale = ref(props.initialSale);
const loading = ref(!props.initialSale);
const activeTab = ref(props.initialTab || 'info');

const tabs = computed(() => [
  { id: 'info', label: 'بيانات الفاتورة', icon: FileText },
  { id: 'returns', label: 'المرتجعات', icon: RotateCcw, badge: sale.value?.returns?.length || 0 },
  { id: 'customer', label: 'العميل', icon: User },
  { id: 'user', label: 'الكاشير والوردية', icon: Clock },
  { id: 'inventory', label: 'المستند المخزني', icon: Package, badge: sale.value?.inventory_documents?.length },
]);

const totalReturned = computed(() => {
  if (!sale.value?.returns) return 0;
  return sale.value.returns.reduce((sum, r) => sum + Number(r.total_returned || 0), 0);
});

const netAfterReturns = computed(() => {
  const grandTotal = Number(sale.value?.grand_total || 0);
  return Math.max(0, grandTotal - totalReturned.value);
});

const isFullyReturned = computed(() => {
  const grandTotal = Number(sale.value?.grand_total || 0);
  return totalReturned.value > 0 && totalReturned.value >= grandTotal - 0.01;
});

const returnedQtyBySaleItemId = computed(() => {
  const map = {};
  if (!sale.value?.returns) return map;
  for (const ret of sale.value.returns) {
    for (const item of ret.items || []) {
      const key = item.pos_sale_item_id;
      if (key) {
        map[key] = (map[key] || 0) + Number(item.quantity || 0);
      }
    }
  }
  return map;
});

const returnedSummaryItems = computed(() => {
  const grouped = new Map();
  if (!sale.value?.returns) return [];
  for (const ret of sale.value.returns) {
    for (const item of ret.items || []) {
      const key = String(item.product_id || item.pos_sale_item_id || item.id);
      const existing = grouped.get(key) || {
        key,
        productName: item.product?.name || `منتج #${item.product_id}`,
        quantity: 0,
        refundAmount: 0,
      };
      existing.quantity += Number(item.quantity || 0);
      existing.refundAmount += Number(item.refund_amount || 0);
      grouped.set(key, existing);
    }
  }
  return Array.from(grouped.values());
});

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

function goToInventoryDocument(doc) {
  if (!doc?.id) return;
  const typeSlugMap = {
    goods_delivery: 'goods-delivery',
    goods_receipt: 'goods-receipt',
    warehouse_transfer: 'warehouse-transfer',
    stock_adjustment: 'stock-adjustment',
    inventory_return: 'inventory-return',
    stock_write_off: 'stock-write-off',
    opening_balance: 'opening-balance',
  };
  const slug = typeSlugMap[doc.document_type];
  emit('close');
  router.push({
    path: slug ? `/inventory/${slug}` : '/inventory',
    query: {
      search: doc.document_number || undefined,
      open: String(doc.id),
    },
  });
}

function docTypeLabel(type) {
  const map = {
    goods_delivery: 'إذن تسليم مخزني',
    goods_receipt: 'إذن استلام مخزني',
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

watch(() => props.saleId, () => {
  activeTab.value = props.initialTab || 'info';
  fetchSaleDetails();
});

watch(() => props.initialTab, (val) => {
  if (val) activeTab.value = val;
});

onMounted(() => {
  fetchSaleDetails();
});
</script>
