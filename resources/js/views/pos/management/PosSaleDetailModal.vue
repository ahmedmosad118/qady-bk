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
              <h3 class="font-extrabold text-base sm:text-lg leading-tight text-[var(--text)]">{{ $t('pos.saleDetail.title') }}</h3>
              <span v-if="sale" class="font-mono text-sm px-2.5 py-0.5 rounded-lg bg-[var(--bg)] border border-[var(--border)] font-black text-[var(--text)]" dir="ltr">
                {{ sale.invoice_number }}
              </span>
            </div>
            <p class="text-xs text-[var(--muted)] mt-0.5">
              {{ $t('common.system') }}
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
            <span>{{ $t('pos.saleDetail.inventoryDocBtn', { number: sale.inventory_documents[0].document_number }) }}</span>
            <ExternalLink class="w-3 h-3 text-[var(--muted)]" />
          </button>

          <button
            type="button"
            class="h-9 px-3.5 rounded-xl bg-[var(--text)] text-[var(--card)] text-xs font-bold hover:opacity-90 transition flex items-center gap-1.5 shadow-xs cursor-pointer shrink-0"
            @click="emit('print', sale)"
          >
            <Printer class="w-4 h-4" />
            <span>{{ $t('pos.saleDetail.printReceipt') }}</span>
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Body Content -->
    <div>
      <!-- Loading State -->
      <div v-if="loading" class="py-16 text-center">
        <div class="inline-block w-8 h-8 border-3 border-[var(--crimson)] border-t-transparent rounded-full animate-spin"></div>
        <p class="text-xs font-bold text-[var(--muted)] mt-3">{{ $t('pos.saleDetail.loading') }}</p>
      </div>

      <div v-else-if="sale" class="space-y-6">
          <!-- Tab 1: Invoice Information -->
          <div v-if="activeTab === 'info'" class="space-y-4">
            <!-- Key Financial Summary Cards -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.subtotalBeforeDiscount') }}</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block">
                  {{ Number(sale.subtotal || 0).toFixed(2) }} <span class="text-xs font-medium">{{ $t('common.currency') }}</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.totalDiscounts') }}</span>
                <b class="text-base font-extrabold text-[var(--text)] mt-1 block">
                  {{ totalDiscounts.toFixed(2) }} <span class="text-xs font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.netTotal') }}</span>
                <b class="text-lg font-black text-[var(--text)] mt-1 block">
                  {{ Number(sale.grand_total || 0).toFixed(2) }} <span class="text-xs font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </b>
              </div>

              <div class="p-3.5 rounded-2xl bg-[var(--card)] border border-[var(--border)]">
                <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.paymentMethod') }}</span>
                <div class="flex items-center gap-1.5 mt-1">
                  <span class="inline-block w-2 h-2 rounded-full bg-emerald-500"></span>
                  <b class="text-sm font-bold text-[var(--text)]">{{ paymentMethodLabel(sale.payment_method) }}</b>
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
                      {{ $t('pos.saleDetail.returnsBannerTitle', { count: sale.returns.length }) }}
                      <span class="ms-1.5 rounded-full px-2 py-0.5 text-[10px] font-extrabold bg-amber-500/10 text-amber-700 dark:text-amber-300 border border-amber-500/20">
                        {{ isFullyReturned ? $t('pos.salesPage.fullReturn') : $t('pos.salesPage.partialReturn') }}
                      </span>
                    </h4>
                    <p class="text-[11px] font-semibold text-[var(--muted)] mt-0.5">
                      {{ $t('pos.saleDetail.totalRefunded') }} <b class="font-mono text-[var(--text)]">{{ totalReturned.toFixed(2) }} {{ $t('common.currency') }}</b>
                      <span class="mx-1.5">·</span>
                      {{ $t('pos.saleDetail.netAfterReturns') }} <b class="font-mono text-[var(--text)]">{{ netAfterReturns.toFixed(2) }} {{ $t('common.currency') }}</b>
                    </p>
                  </div>
                </div>

                <button
                  type="button"
                  class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer"
                  @click="activeTab = 'returns'"
                >
                  <span>{{ $t('pos.saleDetail.returnsDetailsBtn') }}</span>
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
                    ({{ rItem.refundAmount.toFixed(2) }} {{ $t('common.currency') }})
                  </span>
                </span>
              </div>
            </div>

            <!-- Detailed Specs Table -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <div class="px-4 py-3 bg-[var(--bar)]/50 border-b border-[var(--border)]">
                <h4 class="text-xs font-black text-[var(--text)]">{{ $t('pos.saleDetail.detailsHeader') }}</h4>
              </div>
              <div class="divide-y divide-[var(--border)] text-xs">
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.saleDetail.invoiceNumber') }}</span>
                  <span class="col-span-2 font-mono font-bold">{{ sale.invoice_number }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.saleDetail.soldAt') }}</span>
                  <span class="col-span-2 font-mono font-bold" dir="ltr">{{ formatDateTime(sale.sold_at, locale) }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.saleDetail.invoiceDiscount') }}</span>
                  <span class="col-span-2 font-bold text-[var(--text)]">
                    {{ Number(sale.invoice_discount_amount || 0).toFixed(2) }} {{ $t('common.currency') }}
                    <span v-if="sale.invoice_discount_value > 0" class="text-[11px] text-[var(--muted)]">
                      ({{ sale.invoice_discount_value }} {{ sale.invoice_discount_type === 'percentage' ? '%' : $t('common.currency') }})
                    </span>
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.saleDetail.itemsDiscounts') }}</span>
                  <span class="col-span-2 font-bold text-[var(--text)]">
                    {{ Number(sale.items_discount_total || 0).toFixed(2) }} {{ $t('common.currency') }}
                  </span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.saleDetail.clientUuid') }}</span>
                  <span class="col-span-2 font-mono text-[11px] text-[var(--muted)]">{{ sale.client_uuid || '—' }}</span>
                </div>
                <div class="grid grid-cols-3 px-4 py-2.5">
                  <span class="text-[var(--muted)] font-semibold">{{ $t('pos.saleDetail.notes') }}</span>
                  <span class="col-span-2 text-[var(--text)]">{{ sale.notes || $t('pos.saleDetail.noNotes') }}</span>
                </div>
              </div>
            </div>

            <!-- Sold Books / Items Table (Inline with Invoice Details) -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] overflow-hidden">
              <div class="flex items-center justify-between px-4 py-3 bg-[var(--bar)]/50 border-b border-[var(--border)]">
                <div class="flex items-center gap-2">
                  <BookOpen class="w-4 h-4 text-[var(--muted)]" />
                  <h4 class="text-xs font-black text-[var(--text)]">{{ $t('pos.saleDetail.soldItemsTitle') }}</h4>
                </div>
                <span class="rounded-full bg-[var(--card)] border border-[var(--border)] px-2 py-0.5 text-[11px] font-extrabold text-[var(--muted)]">
                  {{ sale.items?.length || 0 }} {{ $t('pos.saleDetail.itemUnit') }}
                </span>
              </div>
              <div class="overflow-x-auto">
                <table class="w-full text-start text-xs">
                  <thead>
                    <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                      <th class="px-3.5 py-2.5 text-start">#</th>
                      <th class="px-3.5 py-2.5 text-start">{{ $t('pos.saleDetail.colItem') }}</th>
                      <th class="px-3.5 py-2.5 text-start">{{ $t('pos.saleDetail.colBarcode') }}</th>
                      <th class="px-3.5 py-2.5 text-center">{{ $t('pos.saleDetail.colQty') }}</th>
                      <th class="px-3.5 py-2.5 text-end">{{ $t('pos.saleDetail.colUnitPrice') }}</th>
                      <th class="px-3.5 py-2.5 text-end">{{ $t('pos.saleDetail.colDiscount') }}</th>
                      <th class="px-3.5 py-2.5 text-end">{{ $t('pos.saleDetail.colTotal') }}</th>
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
                        <b class="text-[var(--text)] block">{{ item.product_snapshot?.name || item.product?.name || $t('pos.saleDetail.unnamedItem') }}</b>
                        <span v-if="item.original_unit_price && item.original_unit_price !== item.unit_price" class="text-[10px] text-[var(--muted)] block">
                          {{ $t('pos.saleDetail.manualPriceOverride', { price: item.original_unit_price }) }}
                        </span>
                        <span
                          v-if="returnedQtyBySaleItemId[item.id] > 0"
                          class="mt-1 inline-flex items-center gap-1 rounded-md border border-amber-500/20 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-400"
                        >
                          <RotateCcw class="w-2.5 h-2.5" />
                          <span>{{ $t('pos.saleDetail.returnedFraction', { returned: returnedQtyBySaleItemId[item.id], sold: Number(item.quantity || 0) }) }}</span>
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
                        {{ Number(item.total || 0).toFixed(2) }} {{ $t('common.currency') }}
                      </td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr class="bg-[var(--bar)]/50 font-bold border-t border-[var(--border)]">
                      <td colspan="3" class="px-3.5 py-3 text-start">{{ $t('pos.saleDetail.totalItemsFooter', { count: sale.items?.length || 0 }) }}</td>
                      <td class="px-3.5 py-3 text-center font-mono">{{ $t('pos.saleDetail.totalPiecesFooter', { count: totalQuantity }) }}</td>
                      <td colspan="2" class="px-3.5 py-3 text-end text-[var(--muted)]">{{ $t('pos.saleDetail.subtotalLabel') }}</td>
                      <td class="px-3.5 py-3 text-end font-mono font-black text-[var(--text)]">
                        {{ Number(sale.subtotal || 0).toFixed(2) }} {{ $t('common.currency') }}
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
                  <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.returnsCountCard') }}</span>
                  <b class="mt-1 block font-mono text-base font-black text-[var(--text)]">{{ sale.returns.length }}</b>
                </div>
                <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
                  <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.totalRefundedCard') }}</span>
                  <b class="mt-1 block font-mono text-base font-black text-[var(--text)]">
                    {{ totalReturned.toFixed(2) }} <span class="text-xs font-sans font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
                  </b>
                </div>
                <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-3.5">
                  <span class="text-[11px] font-bold text-[var(--muted)] block">{{ $t('pos.saleDetail.netAfterReturnsCard') }}</span>
                  <b class="mt-1 block font-mono text-base font-black text-[var(--text)]">
                    {{ netAfterReturns.toFixed(2) }} <span class="text-xs font-sans font-medium text-[var(--muted)]">{{ $t('common.currency') }}</span>
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
                        {{ formatDateTime(ret.returned_at, locale) }}
                        <span v-if="ret.creator?.name"> · {{ $t('pos.saleDetail.byCashier', { name: ret.creator.name }) }}</span>
                        <span v-if="ret.payment_method"> · {{ paymentMethodLabel(ret.payment_method) }}</span>
                      </p>
                    </div>
                  </div>

                  <div class="flex items-center gap-2">
                    <span class="font-mono text-xs font-black text-[var(--text)]">
                      -{{ Number(ret.total_returned || 0).toFixed(2) }} {{ $t('common.currency') }}
                    </span>
                    <button
                      type="button"
                      class="h-8 px-2.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[11px] font-bold text-[var(--text)] hover:bg-[var(--bg)] transition cursor-pointer flex items-center gap-1"
                      @click="emit('open-return', ret.id)"
                    >
                      <ExternalLink class="w-3 h-3 text-[var(--muted)]" />
                      <span>{{ $t('pos.saleDetail.viewReturnBtn') }}</span>
                    </button>
                  </div>
                </div>

                <div v-if="ret.notes" class="px-4 py-2 text-xs text-[var(--muted)] bg-[var(--bg)]/40 border-b border-[var(--border)]">
                  {{ $t('pos.saleDetail.notesPrefix') }} {{ ret.notes }}
                </div>

                <div class="overflow-x-auto">
                  <table class="w-full text-xs text-start">
                    <thead>
                      <tr class="border-b border-[var(--border)] bg-[var(--bg)]/40 text-[10px] font-bold text-[var(--muted)]">
                        <th class="px-4 py-2 text-start">{{ $t('pos.saleDetail.colReturnedItem') }}</th>
                        <th class="px-4 py-2 text-center">{{ $t('pos.saleDetail.colReturnedQty') }}</th>
                        <th class="px-4 py-2 text-end">{{ $t('pos.saleDetail.colUnitPrice') }}</th>
                        <th class="px-4 py-2 text-end">{{ $t('pos.saleDetail.colAllocatedDiscount') }}</th>
                        <th class="px-4 py-2 text-end">{{ $t('pos.saleDetail.colRefundedAmount') }}</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                      <tr v-for="rItem in ret.items" :key="rItem.id" class="hover:bg-[var(--bg)]/40">
                        <td class="px-4 py-2.5 font-bold text-[var(--text)]">
                          {{ rItem.product?.name || $t('posReturns.productFallback', { id: rItem.product_id }) }}
                          <span v-if="rItem.product?.barcode" class="block font-mono text-[10px] font-normal text-[var(--muted)]">
                            {{ rItem.product.barcode }}
                          </span>
                        </td>
                        <td class="px-4 py-2.5 text-center font-mono font-extrabold text-[var(--text)]">
                          {{ Number(rItem.quantity || 0) }}
                        </td>
                        <td class="px-4 py-2.5 text-end font-mono text-[var(--muted)]">
                          {{ Number(rItem.unit_price || 0).toFixed(2) }} {{ $t('common.currency') }}
                        </td>
                        <td class="px-4 py-2.5 text-end font-mono text-[var(--muted)]">
                          -{{ Number(rItem.allocated_discount || 0).toFixed(2) }} {{ $t('common.currency') }}
                        </td>
                        <td class="px-4 py-2.5 text-end font-mono font-black text-[var(--text)]">
                          {{ Number(rItem.refund_amount || 0).toFixed(2) }} {{ $t('common.currency') }}
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
              <h4 class="font-bold text-sm text-[var(--text)]">{{ $t('pos.saleDetail.noReturnsTitle') }}</h4>
              <p class="text-xs text-[var(--muted)] max-w-md mx-auto mt-1">
                {{ $t('pos.saleDetail.noReturnsDesc') }}
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
                  <p class="text-xs text-[var(--muted)]">{{ $t('pos.saleDetail.registeredCustomer') }}</p>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 text-xs">
                <div>
                  <span class="text-[var(--muted)] font-semibold block">{{ $t('pos.saleDetail.phone') }}</span>
                  <span class="font-mono font-bold mt-1 block" dir="ltr">{{ sale.customer.phone || $t('pos.saleDetail.notRegistered') }}</span>
                </div>
                <div>
                  <span class="text-[var(--muted)] font-semibold block">{{ $t('pos.saleDetail.email') }}</span>
                  <span class="font-mono mt-1 block" dir="ltr">{{ sale.customer.email || $t('pos.saleDetail.notRegistered') }}</span>
                </div>
                <div>
                  <span class="text-[var(--muted)] font-semibold block">{{ $t('pos.saleDetail.taxNumber') }}</span>
                  <span class="font-mono mt-1 block">{{ sale.customer.tax_number || '—' }}</span>
                </div>
                <div>
                  <span class="text-[var(--muted)] font-semibold block">{{ $t('pos.saleDetail.accountType') }}</span>
                  <span class="mt-1 block font-bold">
                    {{ sale.customer.type === 'company' ? $t('pos.saleDetail.typeCompany') : $t('pos.saleDetail.typeIndividual') }}
                  </span>
                </div>
              </div>
            </div>

            <div v-else class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--card)] p-8 text-center">
              <div class="w-14 h-14 mx-auto rounded-full bg-[var(--bar)] flex items-center justify-center text-[var(--muted)] mb-3">
                <UserX class="w-7 h-7" />
              </div>
              <h4 class="font-bold text-sm text-[var(--text)]">{{ $t('pos.saleDetail.walkInTitle') }}</h4>
              <p class="text-xs text-[var(--muted)] max-w-md mx-auto mt-1 leading-relaxed">
                {{ $t('pos.saleDetail.walkInDesc') }}
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
                  <h4 class="font-bold text-xs">{{ $t('pos.saleDetail.cashierTitle') }}</h4>
                </div>
                <div class="mt-3 space-y-2 text-xs">
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">{{ $t('common.name') }}:</span>
                    <b class="text-[var(--text)]">{{ sale.creator?.name || $t('pos.saleDetail.unknownUser') }}</b>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">{{ $t('pos.saleDetail.email') }}</span>
                    <span class="font-mono text-[var(--muted)]">{{ sale.creator?.email || '—' }}</span>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">{{ $t('common.createdAt') }}:</span>
                    <span class="font-mono" dir="ltr">{{ formatDateTime(sale.created_at, locale) }}</span>
                  </div>
                </div>
              </div>

              <!-- Shift Reference -->
              <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4">
                <div class="flex items-center justify-between pb-3 border-b border-[var(--border)]">
                  <div class="flex items-center gap-2.5">
                    <Clock class="w-5 h-5 text-[var(--muted)]" />
                    <h4 class="font-bold text-xs">{{ $t('pos.saleDetail.linkedShiftTitle') }}</h4>
                  </div>
                  <span
                    v-if="sale.shift"
                    class="px-2 py-0.5 rounded-full text-[10px] font-bold"
                    :class="sale.shift.closed_at ? 'bg-[var(--bar)] text-[var(--muted)]' : 'bg-emerald-50 text-emerald-700'"
                  >
                    {{ sale.shift.closed_at ? $t('pos.saleDetail.shiftClosed') : $t('pos.saleDetail.shiftOpen') }}
                  </span>
                </div>

                <div v-if="sale.shift" class="mt-3 space-y-2 text-xs">
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">{{ $t('pos.saleDetail.shiftNumber') }}</span>
                    <b class="font-mono text-[var(--text)]">{{ sale.shift.shift_number }}</b>
                  </div>
                  <div class="flex justify-between">
                    <span class="text-[var(--muted)]">{{ $t('pos.saleDetail.shiftOpenedAt') }}</span>
                    <span class="font-mono" dir="ltr">{{ formatDateTime(sale.shift.opened_at, locale) }}</span>
                  </div>
                  <div v-if="sale.shift.closed_at" class="flex justify-between">
                    <span class="text-[var(--muted)]">{{ $t('pos.saleDetail.shiftClosedAt') }}</span>
                    <span class="font-mono" dir="ltr">{{ formatDateTime(sale.shift.closed_at, locale) }}</span>
                  </div>
                  <div class="pt-2">
                    <button
                      type="button"
                      class="w-full py-1.5 rounded-xl border border-[var(--border)] bg-[var(--bar)] hover:bg-[var(--border)] text-xs font-bold transition flex items-center justify-center gap-1"
                      @click="emit('open-shift', sale.shift.id)"
                    >
                      <Layers class="w-3.5 h-3.5" />
                      <span>{{ $t('pos.saleDetail.viewShiftDetails') }}</span>
                    </button>
                  </div>
                </div>
                <div v-else class="mt-4 text-xs text-[var(--muted)] text-center py-2">
                  {{ $t('pos.saleDetail.noShiftInfo') }}
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
                        {{ docTypeLabel(invDoc.document_type) }} · {{ $t('pos.saleDetail.docDatePrefix') }} {{ formatDate(invDoc.document_date, locale) }}
                      </span>
                    </div>
                  </div>

                  <div class="flex items-center gap-2">
                    <span
                      class="px-2.5 py-1 rounded-full text-xs font-bold bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300"
                    >
                      {{ invDoc.status === 'posted' ? $t('pos.saleDetail.postedFinal') : invDoc.status }}
                    </span>

                    <button
                      type="button"
                      class="h-8 px-3 rounded-lg border border-[var(--border)] bg-[var(--card)] text-[var(--text)] hover:bg-[var(--bg)] text-xs font-bold transition flex items-center gap-1.5 cursor-pointer"
                      @click="goToInventoryDocument(invDoc)"
                    >
                      <ExternalLink class="w-3.5 h-3.5 text-[var(--muted)]" />
                      <span>{{ $t('pos.saleDetail.viewFullDoc') }}</span>
                    </button>
                  </div>
                </div>

                <!-- Warehouse & Details -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                  <div class="p-2.5 rounded-xl bg-[var(--bar)]/50">
                    <span class="text-[var(--muted)] block">{{ $t('pos.saleDetail.sourceWarehouse') }}</span>
                    <b class="text-[var(--text)] mt-0.5 block">{{ invDoc.source_warehouse?.name || $t('pos.saleDetail.mainWarehouse') }}</b>
                  </div>
                  <div class="p-2.5 rounded-xl bg-[var(--bar)]/50">
                    <span class="text-[var(--muted)] block">{{ $t('pos.saleDetail.byLabel') }}</span>
                    <b class="text-[var(--text)] mt-0.5 block">{{ invDoc.creator?.name || sale.creator?.name || $t('pos.salesPage.cashier') }}</b>
                  </div>
                  <div class="p-2.5 rounded-xl bg-[var(--bar)]/50">
                    <span class="text-[var(--muted)] block">{{ $t('pos.saleDetail.stockMovementStatus') }}</span>
                    <b class="text-emerald-600 mt-0.5 block">{{ $t('pos.saleDetail.stockDeducted') }}</b>
                  </div>
                </div>

                <!-- Document Items -->
                <div v-if="invDoc.items && invDoc.items.length > 0" class="border border-[var(--border)] rounded-xl overflow-hidden">
                  <table class="w-full text-xs text-start">
                    <thead>
                      <tr class="bg-[var(--bar)] text-[var(--muted)] font-bold border-b border-[var(--border)]">
                        <th class="px-3 py-2 text-start">{{ $t('pos.saleDetail.itemCol') }}</th>
                        <th class="px-3 py-2 text-start">{{ $t('pos.saleDetail.colBarcode') }}</th>
                        <th class="px-3 py-2 text-center">{{ $t('pos.saleDetail.issuedQtyCol') }}</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                      <tr v-for="item in invDoc.items" :key="item.id" class="hover:bg-[var(--bg)]/50">
                        <td class="px-3 py-2 font-medium">{{ item.product?.name || $t('pos.saleDetail.itemCol') }}</td>
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
              <h4 class="font-bold text-sm text-[var(--text)]">{{ $t('pos.saleDetail.noInvDocTitle') }}</h4>
              <p class="text-xs text-[var(--muted)] max-w-md mx-auto mt-1 leading-relaxed">
                {{ $t('pos.saleDetail.noInvDocDesc') }}
              </p>
            </div>
          </div>
        </div>
      </div>

    <!-- Drawer Footer -->
    <template #footer>
      <div class="flex items-center justify-between gap-3">
        <span class="text-xs text-[var(--muted)]">
          {{ $t('pos.saleDetail.footerBrand') }}
        </span>

        <button
          type="button"
          class="h-9 px-4 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] text-xs font-bold hover:bg-[var(--bg)] transition cursor-pointer"
          @click="emit('close')"
        >
          {{ $t('common.close') }}
        </button>
      </div>
    </template>
  </AppDrawer>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
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
import { formatDate, formatDateTime } from '../../../utils/date';
import AppDrawer from '../../../components/ui/AppDrawer.vue';

const router = useRouter();
const { t, te, locale } = useI18n();

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
  { id: 'info', label: t('pos.saleDetail.tabs.info'), icon: FileText },
  { id: 'returns', label: t('pos.saleDetail.tabs.returns'), icon: RotateCcw, badge: sale.value?.returns?.length || 0 },
  { id: 'customer', label: t('pos.saleDetail.tabs.customer'), icon: User },
  { id: 'user', label: t('pos.saleDetail.tabs.user'), icon: Clock },
  { id: 'inventory', label: t('pos.saleDetail.tabs.inventory'), icon: Package, badge: sale.value?.inventory_documents?.length },
]);

function paymentMethodLabel(pm) {
  if (!pm) return t('pos.paymentMethods.cash');
  if (pm.type && te(`pos.paymentMethods.${pm.type}`)) {
    return t(`pos.paymentMethods.${pm.type}`);
  }
  return pm.name || t('pos.paymentMethods.cash');
}

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
        productName: item.product?.name || t('posReturns.productFallback', { id: item.product_id }),
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
  if (type && te(`pos.saleDetail.docTypes.${type}`)) {
    return t(`pos.saleDetail.docTypes.${type}`);
  }
  return type || t('pos.saleDetail.docTypes.fallback');
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
