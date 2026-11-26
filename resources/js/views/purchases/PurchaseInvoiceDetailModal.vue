<template>
  <AppDrawer
    :open="open"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl"
    side="end"
    @close="emit('close')"
  >
    <!-- Drawer Header -->
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-3">
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
          >
            <FileText class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono">
                {{ invoice?.invoice_number || '—' }}
              </h2>

              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="statusBadgeClass(invoice?.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel(invoice?.status) }}
              </span>
            </div>

            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span>{{ $t('purchaseInvoices.supplier') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ invoice?.supplier?.name || '—' }}</span>
              <span>•</span>
              <span class="font-mono">{{ formatDateOnly(invoice?.invoice_date) }}</span>
            </div>
          </div>
        </div>

        <button
          type="button"
          class="rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
          :title="$t('common.close')"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>
    </template>

    <!-- Action Bar -->
    <template #actions>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <span class="text-xs font-bold text-[var(--muted)]">
            {{ $t('common.actions') }}:
          </span>

          <span
            v-if="invoice?.status === 'posted'"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.postedNotice') }}</span>
          </span>

          <span
            v-else-if="isLockedByPosting"
            class="inline-flex items-center gap-1 rounded-lg bg-emerald-500/10 px-2.5 py-1 text-xs font-bold text-emerald-700 dark:text-emerald-300"
          >
            <Lock class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.lockedNotice') }}</span>
          </span>

          <span
            v-else-if="invoice?.status === 'cancelled'"
            class="inline-flex items-center gap-1 rounded-lg bg-rose-500/10 px-2.5 py-1 text-xs font-bold text-rose-700 dark:text-rose-300"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.cancelledNotice') }}</span>
          </span>
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <button
            v-if="invoice?.goods_receipt?.id"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)] cursor-pointer"
            @click="goToGoodsReceipt"
          >
            <PackageCheck class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.openGoodsReceipt') }}</span>
            <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
          </button>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
            @click="printInvoice"
          >
            <Printer class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.print') }}</span>
          </button>

          <button
            v-if="canPay"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--crimson)] px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-[var(--crimson-hover)] disabled:opacity-50 cursor-pointer"
            :disabled="actionLoading"
            @click="emit('pay', invoice)"
          >
            <Banknote class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.payInvoice') }}</span>
          </button>

          <button
            v-if="invoice?.status === 'draft'"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-emerald-700 disabled:opacity-50"
            :disabled="actionLoading"
            @click="emit('post', invoice)"
          >
            <CheckCircle2 class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.post') }}</span>
          </button>

          <button
            v-if="canModify"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
            :disabled="actionLoading"
            @click="emit('edit', invoice)"
          >
            <Edit2 class="h-3.5 w-3.5 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.edit') }}</span>
          </button>

          <button
            v-if="canModify"
            type="button"
            class="inline-flex items-center gap-1.5 rounded-xl border border-rose-500/30 bg-rose-50 px-3.5 py-1.5 text-xs font-bold text-rose-700 transition-colors hover:bg-rose-100 dark:bg-rose-950/30 dark:text-rose-400"
            :disabled="actionLoading"
            @click="emit('cancel', invoice)"
          >
            <XCircle class="h-3.5 w-3.5" />
            <span>{{ $t('purchaseInvoices.cancel') }}</span>
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Content -->
    <div v-if="invoice" class="space-y-6 text-sm text-[var(--text)]">
      <!-- Tabs Navigation -->
      <div class="print:hidden flex flex-wrap items-center gap-2 border-b border-[var(--border)] pb-3">
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition-colors cursor-pointer"
          :class="activeTab === 'details'
            ? 'bg-[var(--crimson)] text-white shadow-xs'
            : 'border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:text-[var(--text)]'"
          @click="activeTab = 'details'"
        >
          <FileText class="h-3.5 w-3.5" />
          <span>{{ $t('purchaseInvoices.tabs.details') }}</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition-colors cursor-pointer"
          :class="activeTab === 'payments'
            ? 'bg-[var(--crimson)] text-white shadow-xs'
            : 'border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:text-[var(--text)]'"
          @click="switchToPaymentsTab"
        >
          <Banknote class="h-3.5 w-3.5" />
          <span>{{ $t('purchaseInvoices.tabs.payments') }}</span>
          <span
            v-if="paymentsMeta.total > 0"
            class="rounded-full px-2 py-0.5 font-mono text-[10px] font-black"
            :class="activeTab === 'payments' ? 'bg-white/20 text-white' : 'bg-[var(--card)] text-[var(--text)]'"
          >
            {{ paymentsMeta.total }}
          </span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-bold transition-colors cursor-pointer"
          :class="activeTab === 'journalEntry'
            ? 'bg-[var(--crimson)] text-white shadow-xs'
            : 'border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:text-[var(--text)]'"
          @click="activeTab = 'journalEntry'"
        >
          <BookOpen class="h-3.5 w-3.5" />
          <span>{{ $t('purchaseInvoices.tabs.journalEntry') }}</span>
        </button>
      </div>

      <!-- Tab 1: Invoice Details -->
      <div v-show="activeTab === 'details'" class="space-y-6">
      <!-- Summary Cards -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <!-- Supplier Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Truck class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.supplier') }}</span>
          </div>
          <p class="mt-2 text-base font-extrabold text-[var(--text)] truncate">{{ invoice.supplier?.name || '—' }}</p>
          <p v-if="invoice.supplier?.phone || invoice.supplier?.mobile" class="mt-1 text-xs font-mono text-[var(--muted)]">
            {{ invoice.supplier?.mobile || invoice.supplier?.phone }}
          </p>
          <p v-if="invoice.supplier?.tax_number" class="mt-0.5 text-xs text-[var(--muted)]">
            {{ $t('purchaseInvoices.taxNumber') }}: {{ invoice.supplier.tax_number }}
          </p>
        </div>

        <!-- Dates & PO Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Calendar class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.dates') }}</span>
          </div>
          <div class="mt-2 space-y-1">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.invoiceDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(invoice.invoice_date) }}</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.dueDate') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">{{ formatDateOnly(invoice.due_date) }}</span>
            </div>
            <div v-if="invoice.purchase_order" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.purchaseOrder') }}:</span>
              <button
                type="button"
                class="inline-flex items-center gap-1 font-bold font-mono text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                :title="$t('purchaseInvoices.openPurchaseOrder')"
                @click="goToPurchaseOrder"
              >
                <span>{{ invoice.purchase_order.order_number }}</span>
                <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
              </button>
            </div>
          </div>
        </div>

        <!-- Linked Goods Receipt & Creator Card -->
        <div class="rounded-xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <PackageCheck class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('purchaseInvoices.goodsReceipt') }}</span>
          </div>
          <div class="mt-2 space-y-1.5">
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.goodsReceiptNumber') }}:</span>
              <button
                v-if="invoice.goods_receipt?.id"
                type="button"
                class="inline-flex items-center gap-1 font-bold font-mono text-[var(--text)] hover:text-[var(--crimson)] hover:underline cursor-pointer"
                @click="goToGoodsReceipt"
              >
                <span>{{ invoice.goods_receipt.document_number }}</span>
                <ExternalLink class="h-3 w-3 text-[var(--muted)]" />
              </button>
              <span v-else class="font-bold font-mono text-[var(--text)]">—</span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.goodsReceiptStatus') }}:</span>
              <span
                class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-bold"
                :class="grStatusBadgeClass(invoice.goods_receipt?.status)"
              >
                {{ grStatusLabel(invoice.goods_receipt?.status) }}
              </span>
            </div>
            <div class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.creator') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ invoice.creator?.name || '—' }}</span>
            </div>
            <div v-if="invoice.journal" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.journal') }}:</span>
              <span class="font-bold text-[var(--text)]">{{ invoice.journal.code }} — {{ invoice.journal.name }}</span>
            </div>
            <div v-if="invoice.journal_entry" class="flex items-center justify-between text-xs">
              <span class="text-[var(--muted)]">{{ $t('purchaseInvoices.journalEntry') }}:</span>
              <span class="font-bold font-mono text-[var(--text)]">
                {{ invoice.journal_entry.entry_number }}
                ({{ $t(`journalEntries.statuses.${invoice.journal_entry.status}`) }})
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Items Table -->
      <div>
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-extrabold text-[var(--text)] flex items-center gap-2">
            <span>{{ $t('purchaseInvoices.items') }}</span>
            <span class="rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">
              {{ invoice.items?.length || 0 }}
            </span>
          </h3>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 py-3 px-4 text-center">#</th>
                <th class="py-3 px-4 text-start">{{ $t('purchaseInvoices.product') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseInvoices.quantity') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseInvoices.unitPrice') }}</th>
                <th class="py-3 px-4 text-center">{{ $t('purchaseInvoices.discount') }}</th>
                <th class="py-3 px-4 text-end">{{ $t('purchaseInvoices.lineTotal') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr
                v-for="(item, idx) in invoice.items"
                :key="item.id || idx"
                class="transition-colors hover:bg-[var(--bg)]/50"
              >
                <td class="py-3 px-4 text-center text-[var(--muted)] font-mono">{{ idx + 1 }}</td>
                <td class="py-3 px-4">
                  <div class="font-bold text-[var(--text)]">
                    {{ item.product_snapshot?.name || item.product?.name || '—' }}
                  </div>
                  <div v-if="item.product_snapshot?.barcode || item.product?.barcode" class="text-[11px] text-[var(--muted)] font-mono">
                    {{ item.product_snapshot?.barcode || item.product?.barcode }}
                  </div>
                </td>
                <td class="py-3 px-4 text-center font-bold text-[var(--text)]">
                  {{ formatQty(item.quantity) }}
                  <span class="text-[10px] font-normal text-[var(--muted)]">
                    {{ $t('purchaseInvoices.unitPiece') }}
                  </span>
                </td>
                <td class="py-3 px-4 text-center font-mono">
                  {{ formatMoney(item.unit_price) }}
                </td>
                <td class="py-3 px-4 text-center">
                  <span v-if="Number(item.discount_amount) > 0" class="font-bold text-[var(--muted)]">
                    -{{ formatMoney(item.discount_amount) }}
                    <span v-if="item.discount_type === 'percentage'" class="text-[10px]">
                      ({{ Number(item.discount_value) }}%)
                    </span>
                  </span>
                  <span v-else class="text-[var(--muted)]">—</span>
                </td>
                <td class="py-3 px-4 text-end font-bold font-mono text-[var(--text)]">
                  {{ formatMoney(item.line_total) }} {{ $t('common.currency') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Additional Expenses Table -->
      <div v-if="invoice.expenses?.length">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-base font-extrabold text-[var(--text)] flex items-center gap-2">
            <span>{{ $t('purchaseInvoices.expenses') }}</span>
            <span class="rounded-full bg-[var(--bg)] px-2.5 py-0.5 text-xs font-bold text-[var(--muted)]">
              {{ invoice.expenses.length }}
            </span>
          </h3>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--border)] bg-[var(--card)] shadow-xs">
          <table class="w-full text-start text-xs">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
              <tr>
                <th class="w-10 py-3 px-4 text-center">#</th>
                <th class="py-3 px-4 text-start">{{ $t('purchaseInvoices.expenseName') }}</th>
                <th class="py-3 px-4 text-start">{{ $t('purchaseInvoices.expenseNotes') }}</th>
                <th class="py-3 px-4 text-end">{{ $t('purchaseInvoices.expenseAmount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr v-for="(expense, idx) in invoice.expenses" :key="expense.id || idx">
                <td class="py-2.5 px-4 text-center text-[var(--muted)] font-mono">{{ idx + 1 }}</td>
                <td class="py-2.5 px-4 font-bold text-[var(--text)]">{{ expense.name }}</td>
                <td class="py-2.5 px-4 text-[var(--muted)]">{{ expense.notes || '—' }}</td>
                <td class="py-2.5 px-4 text-end font-mono font-bold text-[var(--text)]">
                  {{ formatMoney(expense.amount) }} {{ $t('common.currency') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Attachment -->
      <PurchaseAttachmentBox
        resource-prefix="purchase-invoices"
        ns="purchaseInvoices"
        :document="invoice"
      />

      <!-- Notes Banner -->
      <div
        v-if="invoice.notes"
        class="rounded-2xl border border-[var(--border)] bg-[var(--bg)]/60 p-4 text-xs leading-relaxed text-[var(--text)]"
      >
        <div class="flex items-center gap-1.5 font-bold text-[var(--muted)] mb-1">
          <FileText class="h-4 w-4" />
          <span>{{ $t('purchaseInvoices.notes') }}</span>
        </div>
        <p class="whitespace-pre-line text-xs font-medium">{{ invoice.notes }}</p>
      </div>

      <!-- Financial Totals Summary -->
      <div class="flex justify-end">
        <div class="w-full sm:w-96 space-y-2 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center justify-between text-xs">
            <span class="font-bold text-[var(--muted)]">{{ $t('purchaseInvoices.subtotal') }}:</span>
            <span class="font-bold font-mono text-[var(--text)]">{{ formatMoney(invoice.subtotal) }} {{ $t('common.currency') }}</span>
          </div>

          <div
            v-if="itemDiscountsTotal > 0"
            class="flex items-center justify-between text-xs text-[var(--muted)]"
          >
            <span class="font-bold">{{ $t('purchaseInvoices.itemDiscounts') }}:</span>
            <span class="font-bold font-mono">- {{ formatMoney(itemDiscountsTotal) }} {{ $t('common.currency') }}</span>
          </div>

          <div
            v-if="Number(invoice.discount_amount) > 0"
            class="flex items-center justify-between text-xs text-[var(--muted)]"
          >
            <span class="font-bold">
              {{ $t('purchaseInvoices.invoiceDiscount') }}
              <span v-if="invoice.discount_type === 'percentage'" class="text-[11px] font-normal">({{ Number(invoice.discount_value) }}%)</span>:
            </span>
            <span class="font-bold font-mono">- {{ formatMoney(invoice.discount_amount) }} {{ $t('common.currency') }}</span>
          </div>

          <div
            v-if="Number(invoice.additional_expenses_total) > 0"
            class="flex items-center justify-between text-xs text-blue-600 dark:text-blue-400"
          >
            <span class="font-bold">{{ $t('purchaseInvoices.expensesTotal') }}:</span>
            <span class="font-bold font-mono">+ {{ formatMoney(invoice.additional_expenses_total) }} {{ $t('common.currency') }}</span>
          </div>

          <div class="border-t border-[var(--border)] my-1"></div>

            <div class="flex items-baseline justify-between pt-1">
            <span class="text-base font-extrabold text-[var(--text)]">{{ $t('purchaseInvoices.total') }}</span>
            <div class="flex items-baseline gap-1">
              <span class="text-2xl font-black font-mono text-[var(--text)]">{{ formatMoney(invoice.total) }}</span>
              <span class="text-xs font-bold text-[var(--muted)]">{{ $t('common.currency') }}</span>
            </div>
          </div>
        </div>
      </div>
      </div>

      <!-- Tab 2: Payments -->
      <div v-show="activeTab === 'payments'" class="space-y-4">
        <DataTable
          :items="invoicePayments"
          :loading="paymentsLoading"
          :error="paymentsError"
          :colspan="7"
          :meta="paymentsMeta"
          :per-page="paymentsPerPage"
          :empty-title="$t('purchaseInvoices.noPaymentsTitle')"
          :empty-subtitle="$t('purchaseInvoices.noPaymentsSubtitle')"
          @update:per-page="paymentsPerPage = $event"
          @change-per-page="loadInvoicePayments(1)"
          @change-page="loadInvoicePayments"
          @retry="loadInvoicePayments(paymentsMeta.current_page)"
        >
          <template #header>
            <tr>
              <th class="px-4 py-3 text-start">{{ $t('supplierPayments.paymentNumber') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('supplierPayments.paymentDate') }}</th>
              <th class="px-4 py-3 text-start">{{ $t('supplierPayments.paymentMethod') }}</th>
              <th class="px-4 py-3 text-start">{{ $t('supplierPayments.journal') }}</th>
              <th class="px-4 py-3 text-end">{{ $t('supplierPayments.amount') }}</th>
              <th class="px-4 py-3 text-center">{{ $t('supplierPayments.status') }}</th>
              <th class="px-4 py-3 text-end">{{ $t('common.actions') }}</th>
            </tr>
          </template>

          <template #row="{ item: payment }">
            <td class="px-4 py-3.5 font-mono text-xs font-bold whitespace-nowrap">
              <button
                type="button"
                class="text-[var(--text)] hover:text-[var(--crimson)] transition-colors cursor-pointer"
                @click="openPaymentDetail(payment)"
              >
                {{ payment.payment_number }}
              </button>
            </td>

            <td class="px-4 py-3.5 text-center font-mono text-xs text-[var(--muted)] whitespace-nowrap">
              {{ formatDateOnly(payment.payment_date) }}
            </td>

            <td class="px-4 py-3.5 text-xs text-[var(--muted)]">
              {{ payment.payment_method?.name || '—' }}
            </td>

            <td class="px-4 py-3.5 text-xs text-[var(--muted)]">
              {{ payment.journal?.name || '—' }}
            </td>

            <td class="px-4 py-3.5 text-end font-mono text-xs font-black text-[var(--text)] whitespace-nowrap">
              {{ formatMoney(payment.amount) }} {{ $t('common.currency') }}
            </td>

            <td class="px-4 py-3.5 text-center whitespace-nowrap">
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold"
                :class="paymentStatusBadgeClass(payment.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ $t(`supplierPayments.statuses.${payment.status}`) }}
              </span>
            </td>

            <td class="px-4 py-3.5 text-end">
              <div class="inline-flex items-center justify-end gap-1">
                <button
                  type="button"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                  :title="$t('supplierPayments.view')"
                  @click="openPaymentDetail(payment)"
                >
                  <Eye class="h-4 w-4" />
                </button>
                <button
                  v-if="payment.status === 'draft'"
                  type="button"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
                  :title="$t('supplierPayments.edit')"
                  @click="openPaymentEdit(payment)"
                >
                  <Edit2 class="h-4 w-4" />
                </button>
                <button
                  v-if="payment.status === 'draft'"
                  type="button"
                  class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-[var(--crimson)] hover:bg-[var(--crimson-soft)] cursor-pointer"
                  :title="$t('common.delete')"
                  @click="handleDeletePayment(payment)"
                >
                  <Trash2 class="h-4 w-4" />
                </button>
              </div>
            </td>
          </template>
        </DataTable>
      </div>

      <!-- Tab 3: Journal Entry -->
      <div v-show="activeTab === 'journalEntry'" class="space-y-4">
        <div
          v-if="!invoice.journal_entry"
          class="rounded-2xl border border-dashed border-[var(--border)] bg-[var(--bg)] px-6 py-12 text-center"
        >
          <BookOpen class="mx-auto h-8 w-8 text-[var(--muted)]" />
          <p class="mt-3 text-sm font-extrabold text-[var(--text)]">
            {{ $t('purchaseInvoices.noJournalEntryTitle') }}
          </p>
          <p class="mt-1 text-xs text-[var(--muted)]">
            {{ $t('purchaseInvoices.noJournalEntrySubtitle') }}
          </p>
        </div>

        <template v-else>
          <!-- Journal Entry Header -->
          <div class="grid grid-cols-1 gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 sm:grid-cols-2 lg:grid-cols-4 text-xs">
            <div>
              <span class="block text-[var(--muted)]">{{ $t('supplierPayments.entryNumber') }}</span>
              <span class="font-mono text-sm font-black text-[var(--text)]">{{ invoice.journal_entry.entry_number }}</span>
            </div>
            <div>
              <span class="block text-[var(--muted)]">{{ $t('supplierPayments.paymentDate') }}</span>
              <span class="font-mono font-bold text-[var(--text)]">{{ formatDateOnly(invoice.journal_entry.entry_date) }}</span>
            </div>
            <div>
              <span class="block text-[var(--muted)]">{{ $t('supplierPayments.journal') }}</span>
              <span class="font-bold text-[var(--text)]">{{ invoice.journal_entry.journal?.name || invoice.journal?.name || '—' }}</span>
            </div>
            <div>
              <span class="block text-[var(--muted)]">{{ $t('supplierPayments.entryStatus') }}</span>
              <span class="font-bold text-[var(--text)]">{{ $t(`journalEntries.statuses.${invoice.journal_entry.status}`) }}</span>
            </div>
            <div v-if="invoice.journal_entry.reference">
              <span class="block text-[var(--muted)]">{{ $t('purchaseInvoices.entryReference') }}</span>
              <span class="font-mono font-semibold text-[var(--text)]">{{ invoice.journal_entry.reference }}</span>
            </div>
            <div v-if="invoice.journal_entry.posted_at">
              <span class="block text-[var(--muted)]">{{ $t('purchaseInvoices.postedAt') }}</span>
              <span class="font-mono font-semibold text-[var(--text)]">{{ formatDateTime(invoice.journal_entry.posted_at) }}</span>
            </div>
            <div v-if="invoice.journal_entry.description" class="sm:col-span-2">
              <span class="block text-[var(--muted)]">{{ $t('purchaseInvoices.entryDescription') }}</span>
              <span class="text-[var(--text)]">{{ invoice.journal_entry.description }}</span>
            </div>
          </div>

          <!-- Journal Entry Lines Table -->
          <div class="overflow-x-auto rounded-2xl border border-[var(--border)] bg-[var(--card)]">
            <table class="w-full text-start text-xs">
              <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
                <tr>
                  <th class="px-4 py-3 text-center">{{ $t('purchaseInvoices.lineNumber') }}</th>
                  <th class="px-4 py-3 text-start">{{ $t('purchaseInvoices.accountCode') }}</th>
                  <th class="px-4 py-3 text-start">{{ $t('purchaseInvoices.accountName') }}</th>
                  <th class="px-4 py-3 text-start">{{ $t('purchaseInvoices.lineLabel') }}</th>
                  <th class="px-4 py-3 text-end">{{ $t('journalEntries.debit') }}</th>
                  <th class="px-4 py-3 text-end">{{ $t('journalEntries.credit') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-[var(--border)]">
                <tr v-for="line in invoice.journal_entry.lines || []" :key="line.id">
                  <td class="px-4 py-3 text-center font-mono text-[var(--muted)]">{{ line.line_number }}</td>
                  <td class="px-4 py-3 font-mono font-bold text-[var(--text)]">{{ line.account?.code || '—' }}</td>
                  <td class="px-4 py-3 font-semibold text-[var(--text)]">{{ line.account?.name || '—' }}</td>
                  <td class="px-4 py-3 text-[var(--muted)]">{{ line.label || '—' }}</td>
                  <td class="px-4 py-3 text-end font-mono font-bold text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(line.debit) }}
                  </td>
                  <td class="px-4 py-3 text-end font-mono font-bold text-[var(--text)] whitespace-nowrap">
                    {{ formatMoney(line.credit) }}
                  </td>
                </tr>
              </tbody>
              <tfoot class="border-t border-[var(--border)] bg-[var(--bg)] font-black text-[var(--text)]">
                <tr>
                  <td colspan="4" class="px-4 py-3 text-end">
                    {{ $t('journalEntries.totalDebit') }} / {{ $t('journalEntries.totalCredit') }}
                  </td>
                  <td class="px-4 py-3 text-end font-mono whitespace-nowrap">
                    {{ formatMoney(journalEntryTotals.debit) }}
                  </td>
                  <td class="px-4 py-3 text-end font-mono whitespace-nowrap">
                    {{ formatMoney(journalEntryTotals.credit) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </template>
      </div>
    </div>

    <!-- Payment Read-Only Detail Modal -->
    <SupplierPaymentDetailModal
      :open="paymentDetailOpen"
      :payment="selectedPayment"
      :read-only="true"
      @close="paymentDetailOpen = false"
    />

    <!-- Payment Edit Modal (Draft Supplier Payment) -->
    <SupplierPaymentFormModal
      :open="paymentFormOpen"
      :payment="editingPayment"
      locked-payment-type="send"
      @close="paymentFormOpen = false"
      @saved="onPaymentUpdated"
    />
  </AppDrawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import DataTable from '../../components/ui/DataTable.vue';
import PurchaseAttachmentBox from '../../components/purchases/PurchaseAttachmentBox.vue';
import SupplierPaymentDetailModal from './SupplierPaymentDetailModal.vue';
import SupplierPaymentFormModal from './SupplierPaymentFormModal.vue';
import { confirmDelete } from '../../services/alert';
import { deletePayment, fetchPayments } from '../../services/paymentsApi';
import { useNotificationStore } from '../../stores/notification';
import { formatDateTime } from '../../utils/date';
import {
  FileText,
  Truck,
  Calendar,
  PackageCheck,
  Printer,
  Edit2,
  XCircle,
  CheckCircle2,
  Lock,
  ExternalLink,
  Banknote,
  BookOpen,
  Eye,
  Trash2,
  X,
} from 'lucide-vue-next';

const props = defineProps({
  open: { type: Boolean, default: false },
  invoice: { type: Object, default: null },
  actionLoading: { type: Boolean, default: false },
  paymentsRefreshKey: { type: Number, default: 0 },
});

const emit = defineEmits(['close', 'edit', 'post', 'cancel', 'pay', 'refresh']);
const router = useRouter();
const { t } = useI18n();
const notificationStore = useNotificationStore();

const activeTab = ref('details');
const invoicePayments = ref([]);
const paymentsLoading = ref(false);
const paymentsError = ref('');
const paymentsPerPage = ref(10);
const paymentsMeta = ref({ current_page: 1, last_page: 1, per_page: 10, total: 0 });

const paymentDetailOpen = ref(false);
const selectedPayment = ref(null);
const paymentFormOpen = ref(false);
const editingPayment = ref(null);

const journalEntryTotals = computed(() => {
  const lines = props.invoice?.journal_entry?.lines || [];
  let debitCents = 0;
  let creditCents = 0;
  for (const line of lines) {
    debitCents += Math.round((Number(line.debit) || 0) * 100);
    creditCents += Math.round((Number(line.credit) || 0) * 100);
  }
  return {
    debit: debitCents / 100,
    credit: creditCents / 100,
  };
});

const paymentStatusBadgeClass = (status) => {
  if (status === 'posted') return 'bg-[var(--success-soft)] text-[var(--success)]';
  if (status === 'cancelled') return 'bg-[var(--crimson-soft)] text-[var(--crimson)]';
  return 'bg-[var(--gold-soft)] text-[var(--gold-strong)]';
};

const loadInvoicePayments = async (page = 1) => {
  if (!props.invoice?.id) return;
  paymentsLoading.value = true;
  paymentsError.value = '';
  try {
    const res = await fetchPayments({
      purchase_invoice_id: props.invoice.id,
      page,
      per_page: paymentsPerPage.value,
    });
    invoicePayments.value = res.data?.data ?? [];
    paymentsMeta.value = res.data?.meta ?? {
      current_page: page,
      last_page: 1,
      per_page: paymentsPerPage.value,
      total: 0,
    };
  } catch (err) {
    paymentsError.value = err.response?.data?.message || t('purchaseInvoices.paymentsLoadError');
  } finally {
    paymentsLoading.value = false;
  }
};

const switchToPaymentsTab = () => {
  activeTab.value = 'payments';
  loadInvoicePayments(1);
};

const openPaymentDetail = (payment) => {
  selectedPayment.value = payment;
  paymentDetailOpen.value = true;
};

const openPaymentEdit = (payment) => {
  editingPayment.value = payment;
  paymentFormOpen.value = true;
};

const onPaymentUpdated = () => {
  loadInvoicePayments(paymentsMeta.value.current_page || 1);
  emit('refresh');
};

const handleDeletePayment = async (payment) => {
  const confirmed = await confirmDelete({
    title: t('supplierPayments.confirmDeleteTitle'),
    text: t('supplierPayments.confirmDeleteDesc', { number: payment.payment_number }),
  });
  if (!confirmed) return;

  try {
    await deletePayment(payment.id);
    notificationStore.success(t('supplierPayments.deletedSuccess'));
    await loadInvoicePayments(paymentsMeta.value.current_page || 1);
    emit('refresh');
  } catch (err) {
    notificationStore.error(err.response?.data?.message || t('common.genericError'));
  }
};

watch(
  () => [props.open, props.invoice?.id],
  ([isOpen, invoiceId]) => {
    if (isOpen && invoiceId) {
      activeTab.value = 'details';
      loadInvoicePayments(1);
    } else if (!isOpen) {
      invoicePayments.value = [];
      paymentsMeta.value = { current_page: 1, last_page: 1, per_page: paymentsPerPage.value, total: 0 };
    }
  }
);

watch(
  () => props.paymentsRefreshKey,
  () => {
    if (props.open && props.invoice?.id) {
      loadInvoicePayments(paymentsMeta.value.current_page || 1);
    }
  }
);

const isLockedByPosting = computed(() => {
  const grStatus = props.invoice?.goods_receipt?.status;
  return grStatus === 'posted' || grStatus === 'reversed';
});

const canModify = computed(() => {
  return props.invoice?.status === 'draft' && !isLockedByPosting.value;
});

const canPay = computed(() => {
  return props.invoice?.status === 'posted' && Number(props.invoice?.outstanding_amount ?? 0) > 0;
});

const itemDiscountsTotal = computed(() => {
  return (props.invoice?.items || []).reduce((sum, i) => sum + (Number(i.discount_amount) || 0), 0);
});

function statusLabel(status) {
  if (!status) return '—';
  return t(`purchaseInvoices.statuses.${status}`) || status;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft':
      return 'bg-amber-500/10 text-amber-700 border border-amber-500/20 dark:text-amber-400';
    case 'posted':
      return 'bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 dark:text-emerald-400';
    case 'cancelled':
      return 'bg-rose-500/10 text-rose-700 border border-rose-500/20 dark:text-rose-400';
    default:
      return 'bg-gray-500/10 text-gray-700 border border-gray-500/20 dark:text-gray-400';
  }
}

function grStatusLabel(status) {
  if (!status) return '—';
  return t(`purchaseInvoices.grStatuses.${status}`) || status;
}

function grStatusBadgeClass(status) {
  switch (status) {
    case 'posted':
      return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    case 'cancelled':
    case 'rejected':
      return 'bg-rose-500/10 text-rose-700 dark:text-rose-400';
    case 'pending_approval':
    case 'approved':
      return 'bg-blue-500/10 text-blue-700 dark:text-blue-400';
    default:
      return 'bg-amber-500/10 text-amber-700 dark:text-amber-400';
  }
}

function formatMoney(amount) {
  return (Number(amount) || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatQty(qty) {
  const num = Number(qty) || 0;
  return Number.isInteger(num) ? num.toString() : num.toFixed(3);
}

function formatDateOnly(val) {
  if (!val) return t('purchaseInvoices.notSpecified');
  return String(val).substring(0, 10);
}

function printInvoice() {
  window.print();
}

function goToGoodsReceipt() {
  const gr = props.invoice?.goods_receipt;
  if (!gr?.id) return;
  emit('close');
  router.push({
    path: '/inventory/goods-receipt',
    query: {
      search: gr.document_number || undefined,
      open: String(gr.id),
    },
  });
}

function goToPurchaseOrder() {
  const po = props.invoice?.purchase_order;
  if (!po?.id) return;
  emit('close');
  router.push({
    name: 'purchase-orders',
    query: {
      open: String(po.id),
    },
  });
}
</script>
