<template>
  <AppDrawer
    :open="open"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl xl:max-w-5xl"
    side="end"
    @close="emit('close')"
  >
    <!-- Header Slot -->
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--crimson)]/20 bg-[var(--crimson-soft)] text-[var(--crimson)] shadow-xs"
          >
            <ClipboardCheck class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono" dir="ltr">
                {{ stocktake?.stocktake_number || '—' }}
              </h2>

              <button
                v-if="stocktake?.stocktake_number"
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2 py-0.5 text-[11px] font-medium text-[var(--muted)] transition-colors hover:text-[var(--text)]"
                :title="$t('common.copy')"
                @click="copyStocktakeNumber"
              >
                <Check v-if="copied" class="h-3 w-3 text-emerald-500" />
                <Copy v-else class="h-3 w-3" />
                <span>{{ copied ? $t('common.copied') : $t('common.copy') }}</span>
              </button>

              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="statusBadgeClass(stocktake?.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel(stocktake?.status) }}
              </span>
            </div>

            <!-- Subtitle -->
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span class="font-bold text-[var(--text)]">{{ scopeLabel(stocktake?.scope) }}</span>
              <span>•</span>
              <span class="inline-flex items-center gap-1">
                <Building2 class="h-3.5 w-3.5 text-[var(--muted)]" />
                {{ stocktake?.warehouse?.name || '—' }}
              </span>
              <span>•</span>
              <span class="inline-flex items-center gap-1">
                <Calendar class="h-3.5 w-3.5 text-[var(--muted)]" />
                {{ formatDate(stocktake?.stocktake_date) }}
              </span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Main Content -->
    <div v-if="stocktake" class="space-y-5 p-6">
      <!-- Action Banner & Workflow Controls -->
      <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 shadow-xs">
        <div>
          <span class="text-xs font-bold text-[var(--muted)] block">إجراءات المستند والمرحلة الحالية:</span>
          <span class="text-sm font-black text-[var(--text)]">{{ workflowStageDescription }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <!-- Start Button (Draft -> In Progress) -->
          <button
            v-if="stocktake.status === 'draft'"
            type="button"
            :disabled="actionInProgress"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--crimson)] bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white shadow-xs hover:opacity-90 disabled:opacity-50"
            @click="handleStartStocktake"
          >
            <Play class="h-3.5 w-3.5" />
            <span>{{ $t('stocktaking.startStocktake') }}</span>
          </button>

          <!-- Submit Button (In Progress or Rejected -> Pending Approval) -->
          <button
            v-if="stocktake.status === 'in_progress' || stocktake.status === 'rejected'"
            type="button"
            :disabled="actionInProgress || uncountedCount > 0"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--crimson)] bg-[var(--crimson)] px-4 py-2 text-xs font-bold text-white shadow-xs hover:opacity-90 disabled:opacity-50"
            :title="uncountedCount > 0 ? 'يجب إكمال عد كافة المنتجات قبل الإرسال للاعتماد' : ''"
            @click="handleSubmitStocktake"
          >
            <Send class="h-3.5 w-3.5" />
            <span>{{ stocktake.status === 'rejected' ? $t('stocktaking.resubmit') : $t('stocktaking.submitForApproval') }}</span>
          </button>

          <!-- Approve Button (Pending Approval -> Approved) -->
          <button
            v-if="stocktake.status === 'pending_approval'"
            type="button"
            :disabled="actionInProgress"
            class="inline-flex items-center gap-1.5 rounded-xl border border-emerald-600 bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:opacity-90 disabled:opacity-50"
            @click="handleApproveStocktake"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('stocktaking.approve') }}</span>
          </button>

          <!-- Reject Button (Pending Approval -> Rejected) -->
          <button
            v-if="stocktake.status === 'pending_approval'"
            type="button"
            :disabled="actionInProgress"
            class="inline-flex items-center gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-2 text-xs font-bold text-amber-600 hover:bg-amber-500/20 disabled:opacity-50"
            @click="openRejectModal = true"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('stocktaking.reject') }}</span>
          </button>

          <!-- Cancel Button -->
          <button
            v-if="['draft', 'in_progress', 'pending_approval', 'rejected'].includes(stocktake.status)"
            type="button"
            :disabled="actionInProgress"
            class="inline-flex items-center gap-1.5 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-2 text-xs font-bold text-red-600 hover:bg-red-500/20 disabled:opacity-50"
            @click="handleCancelStocktake"
          >
            <Ban class="h-3.5 w-3.5" />
            <span>{{ $t('stocktaking.cancel') }}</span>
          </button>
        </div>
      </div>

      <!-- Action Error Banner -->
      <div v-if="actionError" class="rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-xs font-bold text-red-600">
        {{ actionError }}
      </div>

      <!-- Rejection Banner if Rejected -->
      <div v-if="stocktake.status === 'rejected'" class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-3.5 text-xs text-amber-700">
        <span class="font-extrabold block">تم رفض نتائج الجرد:</span>
        <span class="mt-0.5 block">{{ stocktake.rejection_reason || 'يرجى مراجعة نتائج العد وإعادة العد للبنود المشكوك فيها ثم إعادة الإرسال للاعتماد.' }}</span>
      </div>

      <!-- Progress Stats Summary Bar -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-[var(--muted)] block">إجمالي الأصناف</span>
          <span class="text-lg font-black text-[var(--text)] mt-0.5 block">{{ stocktake.total_items_count || stocktake.items?.length || 0 }}</span>
        </div>
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-emerald-600 block">تم العد</span>
          <span class="text-lg font-black text-emerald-600 mt-0.5 block">{{ stocktake.counted_items_count || 0 }}</span>
        </div>
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-amber-600 block">جاري العد</span>
          <span class="text-lg font-black text-amber-600 mt-0.5 block">{{ stocktake.counting_items_count || 0 }}</span>
        </div>
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-[var(--muted)] block">بانتظار العد</span>
          <span class="text-lg font-black text-[var(--text)] mt-0.5 block">{{ stocktake.pending_items_count || 0 }}</span>
        </div>
      </div>

      <!-- Tabs Navigation -->
      <div class="flex items-center gap-2 border-b border-[var(--border)] pb-2 overflow-x-auto text-xs font-bold">
        <button
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'counting' ? 'bg-[var(--crimson)] text-white shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'counting'"
        >
          <Barcode class="h-3.5 w-3.5" />
          <span>العد الفعلي (Blind Count)</span>
          <span class="rounded-full bg-black/20 px-1.5 py-0.2 text-[10px]">{{ stocktake.items?.length || 0 }}</span>
        </button>

        <button
          v-if="stocktake.can_review_results"
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'results' ? 'bg-[var(--crimson)] text-white shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'results'"
        >
          <Scale class="h-3.5 w-3.5" />
          <span>مراجعة النتائج والفروقات</span>
          <span v-if="stocktake.increases_count || stocktake.decreases_count" class="rounded-full bg-amber-500 text-white px-1.5 py-0.2 text-[10px]">
            {{ (stocktake.increases_count || 0) + (stocktake.decreases_count || 0) }}
          </span>
        </button>

        <button
          v-if="stocktake.inventory_documents && stocktake.inventory_documents.length > 0"
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'adjustments' ? 'bg-[var(--crimson)] text-white shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'adjustments'"
        >
          <SlidersHorizontal class="h-3.5 w-3.5" />
          <span>مستندات التسوية الناتجة</span>
          <span class="rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)] px-1.5 py-0.2 text-[10px]">
            {{ stocktake.inventory_documents.length }}
          </span>
        </button>

        <button
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'history' ? 'bg-[var(--crimson)] text-white shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'history'"
        >
          <History class="h-3.5 w-3.5" />
          <span>سجل التدقيق</span>
        </button>
      </div>

      <!-- TAB 1: COUNTING TAB (BLIND COUNT) -->
      <div v-if="activeTab === 'counting'" class="space-y-4">
        <!-- Search and Filter pills for items -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="relative w-full sm:w-64">
            <Search class="absolute right-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-[var(--muted)]" />
            <input
              v-model="itemsSearch"
              type="text"
              placeholder="بحث بالاسم، الباركود أو SKU..."
              class="h-9 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] pr-9 pl-3 text-xs outline-none focus:border-[var(--crimson)]"
            />
          </div>

          <div class="flex items-center gap-1 text-[11px] font-bold">
            <button
              v-for="st in ['all', 'pending', 'counting', 'counted']"
              :key="st"
              type="button"
              class="px-2.5 py-1 rounded-lg border transition-colors cursor-pointer"
              :class="itemStatusFilter === st
                ? 'border-[var(--crimson)] bg-[var(--crimson-soft)] text-[var(--crimson)]'
                : 'border-[var(--border)] text-[var(--muted)] hover:text-[var(--text)]'"
              @click="itemStatusFilter = st"
            >
              {{ itemStatusFilterLabel(st) }}
            </button>
          </div>
        </div>

        <!-- Items Table -->
        <div class="overflow-x-auto rounded-xl border border-[var(--border)] min-h-[300px]">
          <table class="w-full text-right text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 px-3 py-2.5 text-center">#</th>
                <th class="min-w-[200px] px-3 py-2.5">الكتاب / الصنف</th>
                <th class="w-24 px-3 py-2.5 text-center">الباركود / SKU</th>
                <th class="w-24 px-3 py-2.5 text-center">الحالة</th>
                <th class="w-32 px-3 py-2.5 text-center">الكمية الفعلية</th>
                <th class="min-w-[160px] px-3 py-2.5 text-center">إجراءات العد</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-if="filteredItems.length === 0">
                <td colspan="6" class="p-8 text-center text-xs text-[var(--muted)]">
                  لا توجد أصناف مطابقة للبحث أو الفلتر المختار.
                </td>
              </tr>
              <tr v-for="(item, idx) in filteredItems" :key="item.id" class="hover:bg-[var(--bg)]/50 transition-colors">
                <td class="px-3 py-3 text-center text-xs text-[var(--muted)]">{{ idx + 1 }}</td>
                <td class="px-3 py-3">
                  <span class="font-extrabold text-[var(--text)] block">{{ item.product?.name }}</span>
                  <span v-if="item.product?.author" class="text-[11px] text-[var(--muted)] block">{{ item.product.author }}</span>
                </td>
                <td class="px-3 py-3 text-center font-mono text-[11px] text-[var(--muted)]" dir="ltr">
                  <span>{{ item.product?.sku }}</span>
                  <div v-if="item.product?.barcode" class="text-[10px] text-emerald-600 font-bold tracking-tight">
                    ║▌ {{ item.product.barcode }}
                  </div>
                </td>
                <td class="px-3 py-3 text-center">
                  <span
                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold"
                    :class="itemStatusBadgeClass(item.count_status)"
                  >
                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                    {{ item.count_status_label }}
                  </span>
                </td>
                <td class="px-3 py-3 text-center">
                  <!-- Counting Mode Input -->
                  <div v-if="item.count_status === 'counting'" class="inline-flex items-center gap-1">
                    <input
                      v-model.number="itemDraftCounts[item.id]"
                      type="number"
                      step="any"
                      min="0"
                      class="h-8 w-24 rounded-lg border border-[var(--crimson)] bg-[var(--card)] px-2 text-center text-xs font-black text-[var(--text)] outline-none focus:ring-1 focus:ring-[var(--crimson)]"
                      placeholder="أدخل الكمية"
                    />
                  </div>
                  <div v-else-if="item.count_status === 'counted'" class="text-sm font-black text-[var(--text)]">
                    {{ item.counted_quantity }}
                  </div>
                  <span v-else class="text-[var(--muted)]">—</span>
                </td>
                <td class="px-3 py-3 text-center">
                  <!-- Action: Start Counting -->
                  <button
                    v-if="item.count_status === 'pending' && ['in_progress', 'rejected'].includes(stocktake.status)"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] px-2.5 py-1 text-[11px] font-bold text-[var(--crimson)] hover:bg-[var(--crimson-soft)]/80 cursor-pointer"
                    @click="handleStartCountItem(item)"
                  >
                    <Play class="h-3 w-3" />
                    <span>بدء العد</span>
                  </button>

                  <!-- Action: Record Count / Cancel Count -->
                  <div v-else-if="item.count_status === 'counting'" class="inline-flex items-center gap-1.5">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-emerald-600 bg-emerald-600 px-2.5 py-1 text-[11px] font-bold text-white hover:opacity-90 cursor-pointer"
                      @click="handleRecordCountItem(item)"
                    >
                      <Check class="h-3 w-3" />
                      <span>حفظ</span>
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-red-500/30 bg-red-500/10 px-2 py-1 text-[11px] font-bold text-red-600 hover:bg-red-500/20 cursor-pointer"
                      title="إلغاء العد"
                      @click="handleCancelCountItem(item)"
                    >
                      <X class="h-3 w-3" />
                    </button>
                  </div>

                  <!-- Action: Recount if counted and permitted -->
                  <div v-else-if="item.count_status === 'counted' && ['in_progress', 'rejected'].includes(stocktake.status)">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-[var(--border)] px-2 py-1 text-[11px] font-bold text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)] cursor-pointer"
                      title="إعادة العد للمنتج"
                      @click="handleRecountItem(item)"
                    >
                      <RotateCcw class="h-3 w-3" />
                      <span>إعادة عد</span>
                    </button>
                  </div>
                  <span v-else class="text-[var(--muted)] text-[11px]">مكتمل</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 2: RESULTS & VARIANCE TAB -->
      <div v-if="activeTab === 'results' && stocktake.can_review_results" class="space-y-4">
        <!-- Summary Cards for Variances -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'all' ? 'border-[var(--crimson)] bg-[var(--crimson-soft)]' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'all'"
          >
            <span class="text-[11px] font-bold text-[var(--muted)] block">كافة الأصناف المعدودة</span>
            <span class="text-base font-black text-[var(--text)] mt-0.5 block">{{ stocktake.counted_items_count }}</span>
          </div>

          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'increase' ? 'border-emerald-500 bg-emerald-500/10' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'increase'"
          >
            <span class="text-[11px] font-bold text-emerald-600 block">فائض / زيادة (+)</span>
            <span class="text-base font-black text-emerald-600 mt-0.5 block">{{ stocktake.increases_count || 0 }}</span>
          </div>

          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'decrease' ? 'border-red-500 bg-red-500/10' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'decrease'"
          >
            <span class="text-[11px] font-bold text-red-600 block">عجز / نقص (-)</span>
            <span class="text-base font-black text-red-600 mt-0.5 block">{{ stocktake.decreases_count || 0 }}</span>
          </div>

          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'exact' ? 'border-blue-500 bg-blue-500/10' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'exact'"
          >
            <span class="text-[11px] font-bold text-blue-600 block">مطابق تماماً (=)</span>
            <span class="text-base font-black text-blue-600 mt-0.5 block">{{ stocktake.exact_count || 0 }}</span>
          </div>
        </div>

        <!-- Results Table -->
        <div class="overflow-x-auto rounded-xl border border-[var(--border)] min-h-[300px]">
          <table class="w-full text-right text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 px-3 py-2.5 text-center">#</th>
                <th class="min-w-[200px] px-3 py-2.5">الكتاب / الصنف</th>
                <th class="w-24 px-3 py-2.5 text-center">رصيد النظام</th>
                <th class="w-24 px-3 py-2.5 text-center">الكمية الفعلية</th>
                <th class="w-28 px-3 py-2.5 text-center">الفارق</th>
                <th class="w-24 px-3 py-2.5 text-center">الاتجاه</th>
                <th class="min-w-[120px] px-3 py-2.5">القائم بالعد</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-if="filteredResultsItems.length === 0">
                <td colspan="7" class="p-8 text-center text-xs text-[var(--muted)]">
                  لا توجد نتائج مطابقة للفلتر المختار.
                </td>
              </tr>
              <tr v-for="(item, idx) in filteredResultsItems" :key="item.id" class="hover:bg-[var(--bg)]/50 transition-colors">
                <td class="px-3 py-3 text-center text-xs text-[var(--muted)]">{{ idx + 1 }}</td>
                <td class="px-3 py-3">
                  <span class="font-extrabold text-[var(--text)] block">{{ item.product?.name }}</span>
                  <span class="font-mono text-[11px] text-[var(--muted)] block" dir="ltr">{{ item.product?.sku }}</span>
                </td>
                <td class="px-3 py-3 text-center font-bold text-[var(--text)]">
                  {{ item.system_quantity !== null ? item.system_quantity : '—' }}
                </td>
                <td class="px-3 py-3 text-center font-black text-[var(--text)]">
                  {{ item.counted_quantity !== null ? item.counted_quantity : '—' }}
                </td>
                <td class="px-3 py-3 text-center">
                  <span
                    v-if="item.variance !== null"
                    class="font-black font-mono text-sm"
                    :class="varianceTextClass(item.variance_direction)"
                    dir="ltr"
                  >
                    {{ item.variance > 0 ? `+${item.variance}` : item.variance }}
                  </span>
                  <span v-else class="text-[var(--muted)]">—</span>
                </td>
                <td class="px-3 py-3 text-center">
                  <span
                    v-if="item.variance_direction"
                    class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-extrabold"
                    :class="varianceBadgeClass(item.variance_direction)"
                  >
                    {{ varianceDirectionLabel(item.variance_direction) }}
                  </span>
                </td>
                <td class="px-3 py-3 text-xs text-[var(--muted)]">
                  <span class="text-[var(--text)] font-medium block">{{ item.counted_by || '—' }}</span>
                  <span v-if="item.counted_at" class="text-[10px]">{{ formatTime(item.counted_at) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- TAB 3: GENERATED ADJUSTMENT DOCUMENTS -->
      <div v-if="activeTab === 'adjustments'" class="space-y-4">
        <div class="rounded-xl border border-blue-500/30 bg-blue-500/10 p-3.5 text-xs text-blue-700">
          <span class="font-extrabold block">مستندات التسوية المخزنية:</span>
          <span>
            يتم توليد مسودات التسويات المخزنية تلقائياً عند اعتماد الجرد. لتسوية الفروقات نهائياً، يرجى مراجعة واعتماد وترحيل هذه المستندات وفق دورة المستندات المخزنية.
          </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div
            v-for="doc in stocktake.inventory_documents"
            :key="doc.id"
            class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs space-y-3"
          >
            <div class="flex items-center justify-between">
              <span class="font-mono font-black text-sm text-[var(--text)]" dir="ltr">{{ doc.document_number }}</span>
              <span
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold"
                :class="statusBadgeClass(doc.status)"
              >
                {{ statusLabel(doc.status) }}
              </span>
            </div>

            <div class="text-xs space-y-1 text-[var(--muted)]">
              <div class="flex justify-between">
                <span>الاتجاه:</span>
                <span class="font-bold text-[var(--text)]">
                  {{ doc.adjustment_direction === 'increase' ? 'تسوية بالزيادة (+)' : 'تسوية بالنقص (-)' }}
                </span>
              </div>
              <div class="flex justify-between">
                <span>عدد الأصناف:</span>
                <span class="font-bold text-[var(--text)]">{{ doc.items?.length || 0 }} صنف</span>
              </div>
              <div class="flex justify-between">
                <span>تاريخ المستند:</span>
                <span class="font-bold text-[var(--text)]">{{ formatDate(doc.document_date) }}</span>
              </div>
            </div>

            <div class="pt-2 border-t border-[var(--border)] flex justify-end">
              <router-link
                to="/inventory"
                class="inline-flex items-center gap-1 text-xs font-bold text-[var(--crimson)] hover:underline"
              >
                <span>الانتقال للمستندات للترحيل</span>
                <ExternalLink class="h-3 w-3" />
              </router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 4: AUDIT TRAIL TAB -->
      <div v-if="activeTab === 'history'" class="space-y-3">
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 divide-y divide-[var(--border)]">
          <div v-if="!stocktake.histories || stocktake.histories.length === 0" class="py-4 text-center text-xs text-[var(--muted)]">
            لا يوجد سجل تدقيق متاح.
          </div>
          <div
            v-for="h in stocktake.histories"
            :key="h.id"
            class="py-3 flex items-start justify-between gap-4 text-xs"
          >
            <div>
              <span class="font-bold text-[var(--text)] block">{{ historyActionLabel(h.action) }}</span>
              <span v-if="h.reason" class="text-[11px] text-[var(--muted)] block mt-0.5">{{ h.reason }}</span>
              <span v-if="h.user" class="text-[10px] text-[var(--muted)] block">بواسطة: {{ h.user.name }}</span>
            </div>
            <span class="text-[10px] font-mono text-[var(--muted)] shrink-0" dir="ltr">
              {{ formatDateTime(h.created_at) }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Reject Reason Modal -->
    <div v-if="openRejectModal" class="fixed inset-0 z-60 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 shadow-2xl space-y-4">
        <h3 class="text-sm font-extrabold text-[var(--text)]">سبب رفض مستند الجرد</h3>
        <textarea
          v-model="rejectReason"
          rows="3"
          required
          class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-xs outline-none focus:border-[var(--crimson)]"
          placeholder="يرجى توضيح سبب الرفض والبنود المطلوب إعادة فحصها..."
        />
        <div class="flex items-center justify-end gap-2">
          <button
            type="button"
            class="rounded-xl border border-[var(--border)] px-4 py-2 text-xs font-bold text-[var(--muted)]"
            @click="openRejectModal = false"
          >
            إلغاء
          </button>
          <button
            type="button"
            class="rounded-xl border border-amber-600 bg-amber-600 px-4 py-2 text-xs font-bold text-white hover:opacity-90"
            :disabled="!rejectReason.trim()"
            @click="handleRejectStocktake"
          >
            تأكيد الرفض
          </button>
        </div>
      </div>
    </div>
  </AppDrawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import {
  Ban,
  Barcode,
  Building2,
  Calendar,
  Check,
  CheckCircle2,
  ClipboardCheck,
  Copy,
  ExternalLink,
  History,
  Play,
  RotateCcw,
  Scale,
  Search,
  Send,
  SlidersHorizontal,
  X,
  XCircle,
} from 'lucide-vue-next';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import api from '../../services/api';

const props = defineProps({
  open: { type: Boolean, default: false },
  stocktakeId: { type: Number, default: null },
});

const emit = defineEmits(['close', 'updated']);

const stocktake = ref(null);
const activeTab = ref('counting');
const copied = ref(false);
const actionInProgress = ref(false);
const actionError = ref('');
const openRejectModal = ref(false);
const rejectReason = ref('');

const itemsSearch = ref('');
const itemStatusFilter = ref('all');
const varianceFilter = ref('all');
const itemDraftCounts = reactive({});

async function loadStocktake() {
  if (!props.stocktakeId) return;
  try {
    const res = await api.get(`/stocktakes/${props.stocktakeId}`);
    stocktake.value = res.data.data;

    // Initialize draft counts
    if (stocktake.value?.items) {
      for (const item of stocktake.value.items) {
        if (itemDraftCounts[item.id] === undefined) {
          itemDraftCounts[item.id] = item.counted_quantity !== null ? item.counted_quantity : 0;
        }
      }
    }
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل تحميل بيانات مستند الجرد.';
  }
}

watch(
  () => [props.open, props.stocktakeId],
  ([isOpen, id]) => {
    if (isOpen && id) {
      actionError.value = '';
      activeTab.value = 'counting';
      loadStocktake();
    }
  }
);

const uncountedCount = computed(() => {
  if (!stocktake.value?.items) return 0;
  return stocktake.value.items.filter(i => i.count_status !== 'counted').length;
});

const filteredItems = computed(() => {
  if (!stocktake.value?.items) return [];
  let list = stocktake.value.items;

  if (itemStatusFilter.value !== 'all') {
    list = list.filter(i => i.count_status === itemStatusFilter.value);
  }

  if (itemsSearch.value.trim()) {
    const q = itemsSearch.value.trim().toLowerCase();
    list = list.filter(i =>
      i.product?.name?.toLowerCase().includes(q) ||
      i.product?.sku?.toLowerCase().includes(q) ||
      i.product?.barcode?.toLowerCase().includes(q) ||
      i.product?.author?.toLowerCase().includes(q)
    );
  }

  return list;
});

const filteredResultsItems = computed(() => {
  if (!stocktake.value?.items) return [];
  let list = stocktake.value.items.filter(i => i.count_status === 'counted');

  if (varianceFilter.value !== 'all') {
    list = list.filter(i => i.variance_direction === varianceFilter.value);
  }

  return list;
});

const workflowStageDescription = computed(() => {
  switch (stocktake.value?.status) {
    case 'draft': return 'المستند في مرحلة المسودة؛ يمكنك بدء التنفيذ لتثبيت المنتجات.';
    case 'in_progress': return 'الجرد قيد التنفيذ والعد الفعلي؛ قم بتسجيل الكميات ثم إرسال الجرد للاعتماد.';
    case 'pending_approval': return 'تم إكمال العد وبانتظار مراجعة واعتماد الإدارة.';
    case 'approved': return 'تم اعتماد نتائج الجرد وتوليد مستندات التسوية الناتجة؛ بانتظار ترحيلها لإكمال الجرد.';
    case 'rejected': return 'تم رفض النتائج؛ يمكنك إعادة عد البنود وتصحيحها ثم إعادة الإرسال.';
    case 'cancelled': return 'تم إلغاء مستند الجرد.';
    case 'completed': return 'اكتملت عملية الجرد وترحيل كافة التسويات بنجاح.';
    default: return '—';
  }
});

// Actions
async function handleStartStocktake() {
  actionInProgress.value = true;
  actionError.value = '';
  try {
    await api.post(`/stocktakes/${stocktake.value.id}/start`);
    await loadStocktake();
    emit('updated');
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل بدء الجرد.';
  } finally {
    actionInProgress.value = false;
  }
}

async function handleSubmitStocktake() {
  actionInProgress.value = true;
  actionError.value = '';
  try {
    await api.post(`/stocktakes/${stocktake.value.id}/submit`);
    await loadStocktake();
    emit('updated');
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل إرسال الجرد للاعتماد.';
  } finally {
    actionInProgress.value = false;
  }
}

async function handleApproveStocktake() {
  actionInProgress.value = true;
  actionError.value = '';
  try {
    await api.post(`/stocktakes/${stocktake.value.id}/approve`);
    await loadStocktake();
    emit('updated');
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل اعتماد الجرد.';
  } finally {
    actionInProgress.value = false;
  }
}

async function handleRejectStocktake() {
  actionInProgress.value = true;
  actionError.value = '';
  try {
    await api.post(`/stocktakes/${stocktake.value.id}/reject`, {
      rejection_reason: rejectReason.value,
    });
    openRejectModal.value = false;
    rejectReason.value = '';
    await loadStocktake();
    emit('updated');
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل رفض الجرد.';
  } finally {
    actionInProgress.value = false;
  }
}

async function handleCancelStocktake() {
  if (!confirm('هل أنت متأكد من إلغاء مستند الجرد المخزني؟')) return;
  actionInProgress.value = true;
  actionError.value = '';
  try {
    await api.post(`/stocktakes/${stocktake.value.id}/cancel`, {
      reason: 'إلغاء بواسطة المستخدم',
    });
    await loadStocktake();
    emit('updated');
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل إلغاء الجرد.';
  } finally {
    actionInProgress.value = false;
  }
}

async function handleStartCountItem(item) {
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/start-counting`);
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل بدء عد المنتج.';
  }
}

async function handleRecordCountItem(item) {
  const qty = itemDraftCounts[item.id];
  if (qty === undefined || qty === null || qty < 0) {
    actionError.value = 'يرجى إدخال كمية صحيحة (صفر أو أكبر).';
    return;
  }
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/record-count`, {
      counted_quantity: qty,
    });
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل تسجيل الكمية الفعلية.';
  }
}

async function handleCancelCountItem(item) {
  const reason = prompt('يرجى إدخال سبب إلغاء عملية العد:');
  if (!reason || !reason.trim()) return;
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/cancel-count`, {
      reason: reason.trim(),
    });
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل إلغاء العد.';
  }
}

async function handleRecountItem(item) {
  const reason = prompt('يرجى إدخال سبب إعادة العد للمنتج:');
  if (!reason || !reason.trim()) return;
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/recount`, {
      reason: reason.trim(),
    });
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || 'فشل طلب إعادة العد.';
  }
}

function copyStocktakeNumber() {
  if (!stocktake.value?.stocktake_number) return;
  navigator.clipboard.writeText(stocktake.value.stocktake_number);
  copied.value = true;
  setTimeout(() => (copied.value = false), 2000);
}

// Helpers & Formatters
function statusLabel(status) {
  switch (status) {
    case 'draft': return 'مسودة';
    case 'in_progress': return 'قيد التنفيذ';
    case 'pending_approval': return 'قيد الاعتماد';
    case 'approved': return 'معتمد';
    case 'rejected': return 'مرفوض';
    case 'cancelled': return 'ملغي';
    case 'completed': return 'مكتمل';
    default: return status || '—';
  }
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft': return 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border border-zinc-500/20';
    case 'in_progress': return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/20';
    case 'pending_approval': return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/20';
    case 'approved': return 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/20';
    case 'rejected': return 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/20';
    case 'cancelled': return 'bg-zinc-400/10 text-zinc-500 border border-zinc-400/20';
    case 'completed': return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20';
    default: return 'bg-zinc-500/10 text-zinc-500';
  }
}

function itemStatusFilterLabel(st) {
  switch (st) {
    case 'all': return 'الكل';
    case 'pending': return 'بانتظار العد';
    case 'counting': return 'جاري العد';
    case 'counted': return 'تم العد';
    default: return st;
  }
}

function itemStatusBadgeClass(st) {
  switch (st) {
    case 'pending': return 'bg-zinc-500/10 text-zinc-500 border border-zinc-500/20';
    case 'counting': return 'bg-amber-500/10 text-amber-600 border border-amber-500/20 animate-pulse';
    case 'counted': return 'bg-emerald-500/10 text-emerald-600 border border-emerald-500/20';
    default: return 'bg-zinc-500/10 text-zinc-500';
  }
}

function scopeLabel(scope) {
  return scope === 'full' ? 'جرد كامل' : 'منتجات مختارة';
}

function varianceDirectionLabel(dir) {
  switch (dir) {
    case 'increase': return 'زيادة (+)';
    case 'decrease': return 'عجز (-)';
    case 'exact': return 'مطابق (=)';
    default: return dir;
  }
}

function varianceBadgeClass(dir) {
  switch (dir) {
    case 'increase': return 'bg-emerald-500/15 text-emerald-600 border border-emerald-500/30';
    case 'decrease': return 'bg-red-500/15 text-red-600 border border-red-500/30';
    case 'exact': return 'bg-blue-500/15 text-blue-600 border border-blue-500/30';
    default: return 'bg-zinc-500/15 text-zinc-500';
  }
}

function varianceTextClass(dir) {
  switch (dir) {
    case 'increase': return 'text-emerald-600';
    case 'decrease': return 'text-red-600';
    case 'exact': return 'text-blue-600';
    default: return 'text-[var(--text)]';
  }
}

function historyActionLabel(action) {
  switch (action) {
    case 'created': return 'إنشاء مسودة الجرد';
    case 'started': return 'بدء تنفيذ الجرد';
    case 'start_counting': return 'بدء عد صنف';
    case 'recorded_count': return 'تسجيل الكمية الفعلية';
    case 'cancel_count': return 'إلغاء عملية العد';
    case 'recount': return 'إعادة عد الصنف';
    case 'submitted': return 'إرسال الجرد للاعتماد';
    case 'approved': return 'اعتماد نتائج الجرد وتوليد التسويات';
    case 'approved_and_completed': return 'اعتماد واكتمال الجرد فوراً (بدون فروق)';
    case 'rejected': return 'رفض نتائج الجرد';
    case 'cancelled': return 'إلغاء مستند الجرد';
    case 'completed': return 'اكتمال ترحيل التسويات وإغلاق الجرد';
    default: return action;
  }
}

function formatDate(val) {
  if (!val) return '—';
  return String(val).substring(0, 10);
}

function formatTime(val) {
  if (!val) return '';
  const d = new Date(val);
  return d.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
}

function formatDateTime(val) {
  if (!val) return '—';
  const d = new Date(val);
  return d.toLocaleString('ar-EG', { dateStyle: 'short', timeStyle: 'short' });
}
</script>
