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
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
          >
            <ClipboardCheck class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono">
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
          <span class="text-xs font-bold text-[var(--muted)] block">{{ $t('stocktaking.detail.stageActions') }}</span>
          <span class="text-sm font-black text-[var(--text)]">{{ workflowStageDescription }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <!-- Start Button (Draft -> In Progress) -->
          <button
            v-if="stocktake.status === 'draft'"
            type="button"
            :disabled="actionInProgress"
            class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--text)] px-4 py-2 text-xs font-bold text-[var(--card)] shadow-xs hover:opacity-90 disabled:opacity-50 cursor-pointer"
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
            class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--text)] px-4 py-2 text-xs font-bold text-[var(--card)] shadow-xs hover:opacity-90 disabled:opacity-50 cursor-pointer"
            :title="uncountedCount > 0 ? $t('stocktaking.detail.completeCountingFirst') : ''"
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
      <div v-if="stocktake.status === 'rejected'" class="rounded-xl border border-rose-500/25 bg-rose-500/5 p-3.5 text-xs text-rose-700 dark:text-rose-400">
        <span class="font-extrabold block">{{ $t('stocktaking.detail.rejectedTitle') }}</span>
        <span class="mt-0.5 block text-[var(--text)]">{{ stocktake.rejection_reason || $t('stocktaking.detail.rejectedDefaultMsg') }}</span>
      </div>

      <!-- Progress Stats Summary Bar -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('stocktaking.detail.totalItems') }}</span>
          <span class="text-lg font-black text-[var(--text)] mt-0.5 block">{{ stocktake.total_items_count || stocktake.items?.length || 0 }}</span>
        </div>
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-emerald-600 block">{{ $t('stocktaking.detail.counted') }}</span>
          <span class="text-lg font-black text-emerald-600 mt-0.5 block">{{ stocktake.counted_items_count || 0 }}</span>
        </div>
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('stocktaking.detail.counting') }}</span>
          <span class="text-lg font-black text-[var(--text)] mt-0.5 block">{{ stocktake.counting_items_count || 0 }}</span>
        </div>
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-3 text-center">
          <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('stocktaking.detail.pendingCount') }}</span>
          <span class="text-lg font-black text-[var(--text)] mt-0.5 block">{{ stocktake.pending_items_count || 0 }}</span>
        </div>
      </div>

      <!-- Tabs Navigation -->
      <div class="flex items-center gap-2 border-b border-[var(--border)] pb-2 overflow-x-auto text-xs font-bold">
        <button
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'counting' ? 'bg-[var(--card)] text-[var(--text)] border border-[var(--border)] shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'counting'"
        >
          <Barcode class="h-3.5 w-3.5" />
          <span>{{ $t('stocktaking.detail.tabBlindCount') }}</span>
          <span class="rounded-full bg-[var(--bg)] border border-[var(--border)] text-[var(--muted)] px-1.5 py-0.2 text-[10px]">{{ stocktake.items?.length || 0 }}</span>
        </button>

        <button
          v-if="stocktake.can_review_results"
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'results' ? 'bg-[var(--card)] text-[var(--text)] border border-[var(--border)] shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'results'"
        >
          <Scale class="h-3.5 w-3.5" />
          <span>{{ $t('stocktaking.detail.tabVariances') }}</span>
          <span v-if="stocktake.increases_count || stocktake.decreases_count" class="rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-300 px-1.5 py-0.2 text-[10px]">
            {{ (stocktake.increases_count || 0) + (stocktake.decreases_count || 0) }}
          </span>
        </button>

        <button
          v-if="stocktake.inventory_documents && stocktake.inventory_documents.length > 0"
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'adjustments' ? 'bg-[var(--card)] text-[var(--text)] border border-[var(--border)] shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'adjustments'"
        >
          <SlidersHorizontal class="h-3.5 w-3.5" />
          <span>{{ $t('stocktaking.detail.tabAdjustments') }}</span>
          <span class="rounded-full bg-[var(--bg)] border border-[var(--border)] text-[var(--text)] px-1.5 py-0.2 text-[10px]">
            {{ stocktake.inventory_documents.length }}
          </span>
        </button>

        <button
          type="button"
          class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors cursor-pointer"
          :class="activeTab === 'history' ? 'bg-[var(--card)] text-[var(--text)] border border-[var(--border)] shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)] hover:bg-[var(--bg)]'"
          @click="activeTab = 'history'"
        >
          <History class="h-3.5 w-3.5" />
          <span>{{ $t('stocktaking.detail.tabAudit') }}</span>
        </button>
      </div>

      <!-- TAB 1: COUNTING TAB (BLIND COUNT) -->
      <div v-if="activeTab === 'counting'" class="space-y-4">
        <!-- Search and Filter pills for items -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="relative w-full sm:w-64">
            <Search class="absolute start-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-[var(--muted)]" />
            <input
              v-model="itemsSearch"
              type="text"
              :placeholder="$t('stocktaking.detail.searchItemPlaceholder')"
              class="h-9 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] ps-9 pe-3 text-xs outline-none focus:border-[var(--text)]"
            />
          </div>

          <div class="flex items-center gap-1 text-[11px] font-bold">
            <button
              v-for="st in ['all', 'pending', 'counting', 'counted']"
              :key="st"
              type="button"
              class="px-2.5 py-1 rounded-lg border transition-colors cursor-pointer"
              :class="itemStatusFilter === st
                ? 'border-[var(--text)] bg-[var(--bg)] text-[var(--text)]'
                : 'border-[var(--border)] text-[var(--muted)] hover:text-[var(--text)]'"
              @click="itemStatusFilter = st"
            >
              {{ itemStatusFilterLabel(st) }}
            </button>
          </div>
        </div>

        <!-- Items Table -->
        <div class="overflow-x-auto rounded-xl border border-[var(--border)] min-h-[300px]">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 px-3 py-2.5 text-center">#</th>
                <th class="min-w-[200px] px-3 py-2.5">{{ $t('stocktaking.detail.bookOrItem') }}</th>
                <th class="w-24 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.barcode') }}</th>
                <th class="w-24 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.status') }}</th>
                <th class="w-32 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.actualQty') }}</th>
                <th class="min-w-[160px] px-3 py-2.5 text-center">{{ $t('stocktaking.detail.countingActions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-if="filteredItems.length === 0">
                <td colspan="6" class="p-8 text-center text-xs text-[var(--muted)]">
                  {{ $t('stocktaking.detail.noMatchingItems') }}
                </td>
              </tr>
              <tr v-for="(item, idx) in filteredItems" :key="item.id" class="hover:bg-[var(--bg)]/50 transition-colors">
                <td class="px-3 py-3 text-center text-xs text-[var(--muted)]">{{ idx + 1 }}</td>
                <td class="px-3 py-3">
                  <span class="font-extrabold text-[var(--text)] block">{{ item.product?.name }}</span>
                  <span v-if="item.product?.author" class="text-[11px] text-[var(--muted)] block">{{ item.product.author }}</span>
                </td>
                <td class="px-3 py-3 text-center font-mono text-[11px] text-[var(--muted)]">
                  <div v-if="item.product?.barcode" class="text-[11px] text-emerald-600 font-bold tracking-tight">
                    ║▌ {{ item.product.barcode }}
                  </div>
                  <span v-else>—</span>
                </td>
                <td class="px-3 py-3 text-center">
                  <span
                    class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold"
                    :class="itemStatusBadgeClass(item.count_status)"
                  >
                    <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                    {{ itemStatusFilterLabel(item.count_status) || item.count_status_label }}
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
                      :placeholder="$t('stocktaking.detail.enterQty')"
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
                    class="inline-flex items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--card)] px-2.5 py-1 text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] cursor-pointer"
                    @click="handleStartCountItem(item)"
                  >
                    <Play class="h-3 w-3 text-[var(--muted)]" />
                    <span>{{ $t('stocktaking.detail.startCount') }}</span>
                  </button>

                  <!-- Action: Record Count / Cancel Count -->
                  <div v-else-if="item.count_status === 'counting'" class="inline-flex items-center gap-1.5">
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-emerald-600 bg-emerald-600 px-2.5 py-1 text-[11px] font-bold text-white hover:opacity-90 cursor-pointer"
                      @click="handleRecordCountItem(item)"
                    >
                      <Check class="h-3 w-3" />
                      <span>{{ $t('stocktaking.detail.save') }}</span>
                    </button>
                    <button
                      type="button"
                      class="inline-flex items-center gap-1 rounded-lg border border-red-500/30 bg-red-500/10 px-2 py-1 text-[11px] font-bold text-red-600 hover:bg-red-500/20 cursor-pointer"
                      :title="$t('stocktaking.detail.cancelCount')"
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
                      :title="$t('stocktaking.detail.recountTitle')"
                      @click="handleRecountItem(item)"
                    >
                      <RotateCcw class="h-3 w-3" />
                      <span>{{ $t('stocktaking.detail.recount') }}</span>
                    </button>
                  </div>
                  <span v-else class="text-[var(--muted)] text-[11px]">{{ $t('stocktaking.detail.completed') }}</span>
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
            :class="varianceFilter === 'all' ? 'border-[var(--text)] bg-[var(--bg)]' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'all'"
          >
            <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('stocktaking.detail.allCountedItems') }}</span>
            <span class="text-base font-black text-[var(--text)] mt-0.5 block">{{ stocktake.counted_items_count }}</span>
          </div>

          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'increase' ? 'border-emerald-500 bg-emerald-500/10' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'increase'"
          >
            <span class="text-[11px] font-bold text-emerald-600 block">{{ $t('stocktaking.detail.surplus') }}</span>
            <span class="text-base font-black text-emerald-600 mt-0.5 block">{{ stocktake.increases_count || 0 }}</span>
          </div>

          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'decrease' ? 'border-red-500 bg-red-500/10' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'decrease'"
          >
            <span class="text-[11px] font-bold text-red-600 block">{{ $t('stocktaking.detail.shortage') }}</span>
            <span class="text-base font-black text-red-600 mt-0.5 block">{{ stocktake.decreases_count || 0 }}</span>
          </div>

          <div
            class="rounded-xl border p-3 cursor-pointer transition-all text-center"
            :class="varianceFilter === 'exact' ? 'border-blue-500 bg-blue-500/10' : 'border-[var(--border)] bg-[var(--card)]'"
            @click="varianceFilter = 'exact'"
          >
            <span class="text-[11px] font-bold text-blue-600 block">{{ $t('stocktaking.detail.exactMatch') }}</span>
            <span class="text-base font-black text-blue-600 mt-0.5 block">{{ stocktake.exact_count || 0 }}</span>
          </div>
        </div>

        <!-- Results Table -->
        <div class="overflow-x-auto rounded-xl border border-[var(--border)] min-h-[300px]">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 px-3 py-2.5 text-center">#</th>
                <th class="min-w-[200px] px-3 py-2.5">{{ $t('stocktaking.detail.bookOrItem') }}</th>
                <th class="w-24 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.systemBalance') }}</th>
                <th class="w-24 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.actualQty') }}</th>
                <th class="w-28 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.variance') }}</th>
                <th class="w-24 px-3 py-2.5 text-center">{{ $t('stocktaking.detail.direction') }}</th>
                <th class="min-w-[120px] px-3 py-2.5">{{ $t('stocktaking.detail.countedBy') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-if="filteredResultsItems.length === 0">
                <td colspan="7" class="p-8 text-center text-xs text-[var(--muted)]">
                  {{ $t('stocktaking.detail.noMatchingResults') }}
                </td>
              </tr>
              <tr v-for="(item, idx) in filteredResultsItems" :key="item.id" class="hover:bg-[var(--bg)]/50 transition-colors">
                <td class="px-3 py-3 text-center text-xs text-[var(--muted)]">{{ idx + 1 }}</td>
                <td class="px-3 py-3">
                  <span class="font-extrabold text-[var(--text)] block">{{ item.product?.name }}</span>
                  <span v-if="item.product?.barcode" class="font-mono text-[11px] text-[var(--muted)] block">{{ item.product.barcode }}</span>
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
          <span class="font-extrabold block">{{ $t('stocktaking.detail.adjustmentDocsTitle') }}</span>
          <span>
            {{ $t('stocktaking.detail.adjustmentDocsHint') }}
          </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div
            v-for="doc in stocktake.inventory_documents"
            :key="doc.id"
            class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs space-y-3"
          >
            <div class="flex items-center justify-between">
              <span class="font-mono font-black text-sm text-[var(--text)]">{{ doc.document_number }}</span>
              <span
                class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-[10px] font-bold"
                :class="statusBadgeClass(doc.status)"
              >
                {{ statusLabel(doc.status) }}
              </span>
            </div>

            <div class="text-xs space-y-1 text-[var(--muted)]">
              <div class="flex justify-between">
                <span>{{ $t('stocktaking.detail.direction') }}</span>
                <span class="font-bold text-[var(--text)]">
                  {{ doc.adjustment_direction === 'increase' ? $t('stocktaking.detail.adjustmentIncrease') : $t('stocktaking.detail.adjustmentDecrease') }}
                </span>
              </div>
              <div class="flex justify-between">
                <span>{{ $t('stocktaking.detail.itemsCountLabel') }}</span>
                <span class="font-bold text-[var(--text)]">{{ doc.items?.length || 0 }} {{ $t('stocktaking.detail.itemsUnit') }}</span>
              </div>
              <div class="flex justify-between">
                <span>{{ $t('stocktaking.detail.docDateLabel') }}</span>
                <span class="font-bold text-[var(--text)]">{{ formatDate(doc.document_date) }}</span>
              </div>
            </div>

            <div class="pt-2 border-t border-[var(--border)] flex justify-end">
              <router-link
                to="/inventory"
                class="inline-flex items-center gap-1 text-xs font-bold text-[var(--text)] hover:underline"
              >
                <span>{{ $t('stocktaking.detail.goToDocuments') }}</span>
                <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
              </router-link>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 4: AUDIT TRAIL TAB -->
      <div v-if="activeTab === 'history'" class="space-y-3">
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 divide-y divide-[var(--border)]">
          <div v-if="!stocktake.histories || stocktake.histories.length === 0" class="py-4 text-center text-xs text-[var(--muted)]">
            {{ $t('stocktaking.detail.noAuditLog') }}
          </div>
          <div
            v-for="h in stocktake.histories"
            :key="h.id"
            class="py-3 flex items-start justify-between gap-4 text-xs"
          >
            <div>
              <span class="font-bold text-[var(--text)] block">{{ historyActionLabel(h.action) }}</span>
              <span v-if="h.reason" class="text-[11px] text-[var(--muted)] block mt-0.5">{{ h.reason }}</span>
              <span v-if="h.user" class="text-[10px] text-[var(--muted)] block">{{ $t('stocktaking.detail.byUser') }} {{ h.user.name }}</span>
            </div>
            <span class="text-[10px] font-mono text-[var(--muted)] shrink-0">
              {{ formatDateTime(h.created_at) }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Reject Reason Modal -->
    <div v-if="openRejectModal" class="fixed inset-0 z-60 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
      <div class="w-full max-w-md rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 shadow-2xl space-y-4">
        <h3 class="text-sm font-extrabold text-[var(--text)]">{{ $t('stocktaking.detail.rejectModalTitle') }}</h3>
        <textarea
          v-model="rejectReason"
          rows="3"
          required
          class="w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-xs outline-none focus:border-[var(--crimson)]"
          :placeholder="$t('stocktaking.detail.rejectModalPlaceholder')"
        />
        <div class="flex items-center justify-end gap-2">
          <button
            type="button"
            class="rounded-xl border border-[var(--border)] px-4 py-2 text-xs font-bold text-[var(--muted)]"
            @click="openRejectModal = false"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="button"
            class="rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2 text-xs font-bold text-rose-700 dark:text-rose-400 hover:bg-rose-500/20 cursor-pointer"
            :disabled="!rejectReason.trim()"
            @click="handleRejectStocktake"
          >
            {{ $t('stocktaking.detail.confirmReject') }}
          </button>
        </div>
      </div>
    </div>
  </AppDrawer>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
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
import { formatDateTime as formatAppDateTime } from '../../utils/date';

const { t, locale } = useI18n();

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
    actionError.value = err.response?.data?.message || t('stocktaking.detail.loadError');
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
  const s = stocktake.value?.status;
  return s ? t(`stocktaking.detail.statusHint.${s}`) : '—';
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
    actionError.value = err.response?.data?.message || t('stocktaking.detail.startError');
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
    actionError.value = err.response?.data?.message || t('stocktaking.detail.submitError');
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
    actionError.value = err.response?.data?.message || t('stocktaking.detail.approveError');
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
    actionError.value = err.response?.data?.message || t('stocktaking.detail.rejectError');
  } finally {
    actionInProgress.value = false;
  }
}

async function handleCancelStocktake() {
  if (!confirm(t('stocktaking.detail.confirmCancel'))) return;
  actionInProgress.value = true;
  actionError.value = '';
  try {
    await api.post(`/stocktakes/${stocktake.value.id}/cancel`, {
      reason: t('stocktaking.detail.defaultCancelReason'),
    });
    await loadStocktake();
    emit('updated');
  } catch (err) {
    actionError.value = err.response?.data?.message || t('stocktaking.detail.cancelError');
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
    actionError.value = err.response?.data?.message || t('stocktaking.detail.startCountError');
  }
}

async function handleRecordCountItem(item) {
  const qty = itemDraftCounts[item.id];
  if (qty === undefined || qty === null || qty < 0) {
    actionError.value = t('stocktaking.detail.invalidQtyError');
    return;
  }
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/record-count`, {
      counted_quantity: qty,
    });
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || t('stocktaking.detail.recordCountError');
  }
}

async function handleCancelCountItem(item) {
  const reason = prompt(t('stocktaking.detail.cancelCountReasonPrompt'));
  if (!reason || !reason.trim()) return;
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/cancel-count`, {
      reason: reason.trim(),
    });
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || t('stocktaking.detail.cancelCountError');
  }
}

async function handleRecountItem(item) {
  const reason = prompt(t('stocktaking.detail.recountReasonPrompt'));
  if (!reason || !reason.trim()) return;
  actionError.value = '';
  try {
    await api.post(`/stocktake-items/${item.id}/recount`, {
      reason: reason.trim(),
    });
    await loadStocktake();
  } catch (err) {
    actionError.value = err.response?.data?.message || t('stocktaking.detail.recountError');
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
  if (!status) return '—';
  const map = {
    draft: t('stocktaking.statuses.draft'),
    in_progress: t('stocktaking.statuses.in_progress'),
    pending_approval: t('stocktaking.statuses.pending_approval'),
    approved: t('stocktaking.statuses.approved'),
    rejected: t('stocktaking.statuses.rejected'),
    cancelled: t('stocktaking.statuses.cancelled'),
    completed: t('stocktaking.statuses.completed'),
  };
  return map[status] || status;
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
  const map = {
    all: t('stocktaking.itemStatuses.all'),
    pending: t('stocktaking.itemStatuses.pending'),
    counting: t('stocktaking.itemStatuses.counting'),
    counted: t('stocktaking.itemStatuses.counted'),
  };
  return map[st] || st;
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
  return scope === 'full' ? t('stocktaking.scopeFullShort') : t('stocktaking.scopeSelectedShort');
}

function varianceDirectionLabel(dir) {
  const map = {
    increase: t('stocktaking.variances.increase'),
    decrease: t('stocktaking.variances.decrease'),
    exact: t('stocktaking.variances.exact'),
  };
  return map[dir] || dir;
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
  const map = {
    created: t('stocktaking.detail.historyActions.created'),
    started: t('stocktaking.detail.historyActions.started'),
    start_counting: t('stocktaking.detail.historyActions.start_counting'),
    recorded_count: t('stocktaking.detail.historyActions.recorded_count'),
    cancel_count: t('stocktaking.detail.historyActions.cancel_count'),
    recount: t('stocktaking.detail.historyActions.recount'),
    submitted: t('stocktaking.detail.historyActions.submitted'),
    approved: t('stocktaking.detail.historyActions.approved'),
    approved_and_completed: t('stocktaking.detail.historyActions.approved_and_completed'),
    rejected: t('stocktaking.detail.historyActions.rejected'),
    cancelled: t('stocktaking.detail.historyActions.cancelled'),
    completed: t('stocktaking.detail.historyActions.completed'),
  };
  return map[action] || action;
}

function formatDate(val) {
  if (!val) return '—';
  return String(val).substring(0, 10);
}

function formatTime(val) {
  if (!val) return '';
  const d = new Date(val);
  return d.toLocaleTimeString(locale.value === 'ar' ? 'ar-EG' : 'en-US', { hour: '2-digit', minute: '2-digit' });
}

function formatDateTime(val) {
  return formatAppDateTime(val, locale.value) || '—';
}
</script>
