<template>
  <div
    v-if="open"
    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 md:p-6 overflow-y-auto bg-black/60 backdrop-blur-xs"
    @click.self="emit('close')"
  >
    <div
      class="relative w-full max-w-5xl rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl transition-all my-auto"
      :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
    >
      <!-- Header -->
      <div class="flex items-start justify-between border-b border-[var(--border)] px-6 py-4">
        <div>
          <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl">
            {{ modalTitle }}
          </h2>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            {{ modalSubtitle }}
          </p>
        </div>
        <button
          type="button"
          class="rounded-xl p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
          @click="emit('close')"
        >
          <X class="h-5 w-5" />
        </button>
      </div>

      <!-- Form Body -->
      <form @submit.prevent="submitForm">
        <div class="max-h-[76vh] space-y-5 overflow-y-auto p-6 custom-scrollbar">
          <!-- Error Banner -->
          <div
            v-if="submitError"
            class="flex items-center gap-2 rounded-xl border border-[var(--crimson)]/20 bg-[var(--crimson-soft)] px-4 py-2.5 text-xs font-bold text-[var(--crimson)]"
          >
            <AlertCircle class="h-4 w-4 shrink-0" />
            <span>{{ submitError }}</span>
          </div>

          <!-- Source Mode Toggle (Purchase Invoice create only) -->
          <div
            v-if="isInvoice && !isEdit"
            class="rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-3.5"
          >
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span class="text-xs font-extrabold text-[var(--text)]">
                {{ $t('purchaseInvoices.invoiceType') }}
              </span>
              <div class="inline-flex rounded-xl border border-[var(--border)] bg-[var(--card)] p-0.5 text-xs font-bold">
                <button
                  type="button"
                  class="rounded-lg px-3 py-1.5 transition-colors"
                  :class="sourceMode === 'direct' ? 'bg-[var(--crimson)] text-white shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                  @click="setSourceMode('direct')"
                >
                  {{ $t('purchaseInvoices.directPurchase') }}
                </button>
                <button
                  type="button"
                  class="rounded-lg px-3 py-1.5 transition-colors"
                  :class="sourceMode === 'purchase_order' ? 'bg-[var(--crimson)] text-white shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                  @click="setSourceMode('purchase_order')"
                >
                  {{ $t('purchaseInvoices.fromPurchaseOrder') }}
                </button>
              </div>
            </div>

            <!-- Purchase Order Selector -->
            <div v-if="sourceMode === 'purchase_order'" class="mt-3">
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ $t('purchaseInvoices.selectPurchaseOrder') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.purchase_order_id"
                :fetch-options="fetchPurchaseOrderOptions"
                :selected-option-data="selectedPurchaseOrderOption"
                :paginate="10"
                :placeholder="$t('purchaseInvoices.selectPurchaseOrderPlaceholder')"
                :clearable="true"
                @update:model-value="onPurchaseOrderSelected"
              />
              <p v-if="errors.purchase_order_id" class="mt-1 text-xs text-[var(--crimson)]">
                {{ errors.purchase_order_id[0] }}
              </p>
            </div>
          </div>

          <!-- Top Metadata Grid -->
          <div class="grid grid-cols-1 gap-4" :class="isInvoice ? 'sm:grid-cols-2 lg:grid-cols-4' : 'sm:grid-cols-3'">
            <!-- Supplier -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ $t(`${ns}.supplier`) }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.supplier_id"
                :fetch-options="fetchSupplierOptions"
                :selected-option-data="selectedSupplierOption"
                :paginate="10"
                :placeholder="$t(`${ns}.selectSupplier`)"
                :clearable="false"
                :disabled="isInvoice && sourceMode === 'purchase_order' && !!form.purchase_order_id"
                @change="(_, opt) => { if (opt) selectedSupplierOption = opt; }"
              />
              <p v-if="errors.supplier_id" class="mt-1 text-xs text-[var(--crimson)]">
                {{ errors.supplier_id[0] }}
              </p>
            </div>

            <!-- Purchase Journal (Invoice only) -->
            <div v-if="isInvoice">
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ $t('purchaseInvoices.journal') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <SearchableSelect
                v-model="form.journal_id"
                :fetch-options="fetchJournalOptions"
                :selected-option-data="selectedJournalOption"
                :paginate="10"
                :placeholder="$t('purchaseInvoices.selectJournal')"
                :clearable="false"
                :disabled="isEdit && props.document?.status === 'posted'"
                @change="(_, opt) => { if (opt) selectedJournalOption = opt; }"
              />
              <p v-if="errors.journal_id" class="mt-1 text-xs text-[var(--crimson)]">
                {{ errors.journal_id[0] }}
              </p>
            </div>

            <!-- Primary Date (invoice_date or order_date) -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ isInvoice ? $t('purchaseInvoices.invoiceDate') : $t('purchaseOrders.orderDate') }} <span class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.primary_date"
                type="date"
                required
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
                @change="onPrimaryDateChange"
              />
              <p v-if="primaryDateError" class="mt-1 text-xs text-[var(--crimson)]">
                {{ primaryDateError }}
              </p>
            </div>

            <!-- Secondary Date (due_date required for invoice, expected_delivery_date optional for order) -->
            <div>
              <label class="mb-1.5 block text-xs font-bold text-[var(--text)]">
                {{ isInvoice ? $t('purchaseInvoices.dueDate') : $t('purchaseOrders.deliveryDate') }}
                <span v-if="isInvoice" class="text-[var(--crimson)]">*</span>
              </label>
              <input
                v-model="form.secondary_date"
                type="date"
                :required="isInvoice"
                :min="form.primary_date"
                class="h-10 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 text-xs text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
                @input="secondaryDateTouched = true"
              />
              <p v-if="secondaryDateError" class="mt-1 text-xs text-[var(--crimson)]">
                {{ secondaryDateError }}
              </p>
            </div>
          </div>

          <!-- Line Items Section -->
          <div class="space-y-3">
            <div class="flex items-center justify-between border-b border-[var(--border)] pb-2">
              <h3 class="text-sm font-extrabold text-[var(--text)]">
                {{ $t(`${ns}.items`) }} <span class="text-[var(--crimson)]">*</span>
              </h3>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] px-3 py-1.5 text-xs font-bold text-[var(--crimson)] transition-colors hover:bg-[var(--crimson-soft)]/80"
                @click="addItemRow"
              >
                <Plus class="h-3.5 w-3.5" />
                <span>{{ $t(`${ns}.addItem`) }}</span>
              </button>
            </div>

            <p v-if="mergeNotice" class="text-xs font-semibold text-amber-600 dark:text-amber-400">
              {{ mergeNotice }}
            </p>
            <p v-if="errors.items" class="text-xs text-[var(--crimson)]">{{ errors.items[0] }}</p>

            <!-- Items Table -->
            <div class="rounded-xl border border-[var(--border)] overflow-visible">
              <table class="w-full table-fixed text-start text-xs">
                <thead class="border-b border-[var(--border)] bg-[var(--bg)] font-bold text-[var(--muted)]">
                  <tr>
                    <th class="w-[4%] px-1 py-2.5 text-center">#</th>
                    <th class="w-[34%] px-2.5 py-2.5 text-start">{{ $t(`${ns}.product`) }}</th>
                    <th class="w-[11%] px-1 py-2.5 text-center">{{ $t(`${ns}.quantity`) }}</th>
                    <th class="w-[13%] px-1 py-2.5 text-center">{{ $t(`${ns}.unitPrice`) }}</th>
                    <th class="w-[22%] px-1 py-2.5 text-center">{{ $t(`${ns}.discount`) }}</th>
                    <th class="w-[12%] px-2 py-2.5 text-end">{{ $t(`${ns}.lineTotal`) }}</th>
                    <th class="w-[4%] px-1 py-2.5 text-center"></th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                  <tr v-for="(item, idx) in form.items" :key="idx" class="align-middle transition-colors hover:bg-[var(--bg)]/40">
                    <td class="px-1 py-2.5 text-center font-bold text-[var(--muted)]">{{ idx + 1 }}</td>

                    <!-- Product Select (Manual unit_price only - no auto-prefill) -->
                    <td class="px-2 py-2.5 min-w-0">
                      <SearchableSelect
                        v-model="item.product_id"
                        :fetch-options="fetchProductOptions"
                        :selected-option-data="item.product_option"
                        :paginate="10"
                        placement="top"
                        :placeholder="$t(`${ns}.selectProduct`)"
                        :clearable="false"
                        @change="(_, opt) => onProductSelected(idx, opt)"
                      />
                      <p v-if="errors[`items.${idx}.product_id`]" class="mt-1 text-[11px] text-[var(--crimson)] truncate">
                        {{ errors[`items.${idx}.product_id`][0] }}
                      </p>
                    </td>

                    <!-- Quantity -->
                    <td class="px-1 py-2.5 text-center">
                      <input
                        v-model.number="item.quantity"
                        type="number"
                        step="any"
                        min="0.001"
                        required
                        class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-1 text-center text-xs font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-1 focus:ring-[var(--crimson-soft)]"
                      />
                      <p v-if="errors[`items.${idx}.quantity`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                        {{ errors[`items.${idx}.quantity`][0] }}
                      </p>
                    </td>

                    <!-- Unit Price (Manual Entry, strictly > 0) -->
                    <td class="px-1 py-2.5 text-center">
                      <input
                        v-model.number="item.unit_price"
                        type="number"
                        step="0.01"
                        min="0.01"
                        placeholder="0.00"
                        required
                        class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-1 text-center text-xs font-mono font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-1 focus:ring-[var(--crimson-soft)]"
                      />
                      <p v-if="errors[`items.${idx}.unit_price`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                        {{ errors[`items.${idx}.unit_price`][0] }}
                      </p>
                    </td>

                    <!-- Item Discount -->
                    <td class="px-1 py-2.5 text-center">
                      <div class="flex items-center justify-center gap-1">
                        <input
                          v-model.number="item.discount_value"
                          type="number"
                          step="0.01"
                          min="0"
                          :max="item.discount_type === 'percentage' ? 100 : undefined"
                          class="h-9 w-14 rounded-lg border border-[var(--border)] bg-[var(--card)] px-1 text-center text-xs font-mono font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-1 focus:ring-[var(--crimson-soft)]"
                          placeholder="0"
                        />
                        <div class="inline-flex rounded-lg border border-[var(--border)] bg-[var(--bg)] p-0.5 text-[11px] font-bold shrink-0">
                          <button
                            type="button"
                            class="px-1.5 py-1 rounded-md transition-colors"
                            :class="item.discount_type === 'percentage' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                            @click="item.discount_type = 'percentage'"
                          >
                            %
                          </button>
                          <button
                            type="button"
                            class="px-1.5 py-1 rounded-md transition-colors"
                            :class="item.discount_type === 'fixed' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                            @click="item.discount_type = 'fixed'"
                          >
                            {{ $t('common.currency') }}
                          </button>
                        </div>
                      </div>
                    </td>

                    <!-- Line Total -->
                    <td class="px-2 py-2.5 text-end font-bold font-mono text-xs text-[var(--text)] whitespace-nowrap">
                      {{ formatMoney(calcLineTotal(item)) }} <span class="text-[10px] font-normal text-[var(--muted)]">{{ $t('common.currency') }}</span>
                    </td>

                    <!-- Remove Row -->
                    <td class="px-1 py-2.5 text-center">
                      <button
                        type="button"
                        class="rounded-lg p-1.5 text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 dark:hover:text-rose-400 disabled:opacity-30 transition-colors"
                        :disabled="form.items.length <= 1"
                        @click="removeItemRow(idx)"
                      >
                        <Trash2 class="h-4 w-4" />
                      </button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Additional Expenses Section (Purchase Invoice only) -->
          <div v-if="isInvoice" class="space-y-3 pt-2 border-t border-[var(--border)]">
            <div class="flex items-center justify-between">
              <h3 class="text-sm font-extrabold text-[var(--text)]">
                {{ $t('purchaseInvoices.expenses') }}
              </h3>
              <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 text-xs font-bold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 hover:text-[var(--crimson)]"
                @click="addExpenseRow"
              >
                <Plus class="h-3.5 w-3.5" />
                <span>{{ $t('purchaseInvoices.addExpense') }}</span>
              </button>
            </div>

            <p v-if="form.expenses.length === 0" class="text-xs text-[var(--muted)]">
              {{ $t('purchaseInvoices.noExpenses') }}
            </p>

            <div v-else class="space-y-2">
              <div
                v-for="(expense, expIdx) in form.expenses"
                :key="expIdx"
                class="grid grid-cols-1 gap-2 rounded-xl border border-[var(--border)] bg-[var(--bg)]/60 p-3 sm:grid-cols-12 items-start"
              >
                <div class="sm:col-span-5">
                  <input
                    v-model="expense.name"
                    type="text"
                    required
                    :placeholder="$t('purchaseInvoices.expenseNamePlaceholder')"
                    class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-xs text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  />
                  <p v-if="errors[`expenses.${expIdx}.name`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                    {{ errors[`expenses.${expIdx}.name`][0] }}
                  </p>
                </div>

                <div class="sm:col-span-3">
                  <input
                    v-model.number="expense.amount"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    :placeholder="$t('purchaseInvoices.expenseAmount')"
                    class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-center text-xs font-mono font-bold text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  />
                  <p v-if="errors[`expenses.${expIdx}.amount`]" class="mt-1 text-[11px] text-[var(--crimson)]">
                    {{ errors[`expenses.${expIdx}.amount`][0] }}
                  </p>
                </div>

                <div class="sm:col-span-3">
                  <input
                    v-model="expense.notes"
                    type="text"
                    :placeholder="$t('purchaseInvoices.expenseNotesPlaceholder')"
                    class="h-9 w-full rounded-lg border border-[var(--border)] bg-[var(--card)] px-3 text-xs text-[var(--text)] outline-none focus:border-[var(--crimson)]"
                  />
                </div>

                <div class="sm:col-span-1 flex justify-end">
                  <button
                    type="button"
                    class="rounded-lg p-2 text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/30 dark:hover:text-rose-400 transition-colors"
                    @click="removeExpenseRow(expIdx)"
                  >
                    <Trash2 class="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Summary & Document Discount Section -->
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 pt-2 border-t border-[var(--border)]">
            <!-- Document-Level Discount -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--bg)] p-4 space-y-3">
              <div class="text-xs font-extrabold text-[var(--text)]">
                {{ isInvoice ? $t('purchaseInvoices.invoiceDiscount') : $t('purchaseOrders.orderDiscount') }}
              </div>

              <div class="space-y-1.5">
                <label class="block text-[11px] font-bold text-[var(--muted)]">
                  {{ isInvoice ? $t('purchaseInvoices.discountValue') : $t('purchaseOrders.discountAmountLabel') }}
                </label>
                <div class="flex items-center gap-2">
                  <input
                    v-model.number="form.discount_value"
                    type="number"
                    step="0.01"
                    min="0"
                    :max="form.discount_type === 'percentage' ? 100 : undefined"
                    class="h-10 w-28 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 text-center text-sm font-mono font-bold text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)]"
                    placeholder="0"
                  />
                  <div class="inline-flex rounded-xl border border-[var(--border)] bg-[var(--card)] p-0.5 text-xs font-bold shrink-0">
                    <button
                      type="button"
                      class="px-3 py-1.5 rounded-lg transition-colors"
                      :class="form.discount_type === 'percentage' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                      @click="form.discount_type = 'percentage'"
                    >
                      %
                    </button>
                    <button
                      type="button"
                      class="px-3 py-1.5 rounded-lg transition-colors"
                      :class="form.discount_type === 'fixed' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900 shadow-xs' : 'text-[var(--muted)] hover:text-[var(--text)]'"
                      @click="form.discount_type = 'fixed'"
                    >
                      {{ $t('common.currency') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Financial Totals Breakdown -->
            <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 space-y-2">
              <div class="flex items-center justify-between text-xs">
                <span class="font-bold text-[var(--muted)]">{{ $t(`${ns}.subtotal`) }}</span>
                <span class="font-mono font-bold text-[var(--text)]">{{ formatMoney(calcGrossTotal) }} {{ $t('common.currency') }}</span>
              </div>

              <div
                v-if="calcItemDiscountsTotal > 0"
                class="flex items-center justify-between text-xs text-rose-600 dark:text-rose-400"
              >
                <span class="font-bold">{{ $t(`${ns}.itemDiscounts`) }}</span>
                <span class="font-mono font-bold">- {{ formatMoney(calcItemDiscountsTotal) }} {{ $t('common.currency') }}</span>
              </div>

              <div
                v-if="calcDocumentDiscountAmount > 0"
                class="flex items-center justify-between text-xs text-rose-600 dark:text-rose-400"
              >
                <span class="font-bold">
                  {{ isInvoice ? $t('purchaseInvoices.invoiceDiscount') : $t('purchaseOrders.orderDiscount') }}
                  <span class="text-[11px] font-normal">({{ form.discount_type === 'percentage' ? '%' + (form.discount_value || 0) : $t('purchaseInvoices.fixedLabel') }})</span>
                </span>
                <span class="font-mono font-bold">- {{ formatMoney(calcDocumentDiscountAmount) }} {{ $t('common.currency') }}</span>
              </div>

              <div
                v-if="isInvoice && calcExpensesTotal > 0"
                class="flex items-center justify-between text-xs text-blue-600 dark:text-blue-400"
              >
                <span class="font-bold">{{ $t('purchaseInvoices.expensesTotal') }}</span>
                <span class="font-mono font-bold">+ {{ formatMoney(calcExpensesTotal) }} {{ $t('common.currency') }}</span>
              </div>

              <div class="border-t border-[var(--border)] my-1"></div>

              <div class="flex items-baseline justify-between pt-1">
                <span class="text-base font-extrabold text-[var(--text)]">{{ $t(`${ns}.total`) }}</span>
                <div class="flex items-baseline gap-1">
                  <span class="text-2xl font-black font-mono text-[var(--text)]">{{ formatMoney(calcGrandTotal) }}</span>
                  <span class="text-xs font-bold text-[var(--muted)]">{{ $t('common.currency') }}</span>
                </div>
              </div>

              <div class="text-center text-[11px] text-[var(--muted)] pt-1">
                {{ isInvoice ? $t('purchaseInvoices.itemsSummary', { items: form.items.length, qty: totalQuantity }) : $t('purchaseOrders.itemsCountSummary', { items: form.items.length, qty: totalQuantity }) }}
              </div>
            </div>
          </div>

          <!-- Bottom Collapsible Tabs: Notes & Optional Attachment -->
          <div class="pt-2 border-t border-[var(--border)] space-y-3">
            <div class="flex flex-wrap items-center gap-2" role="tablist">
              <button
                type="button"
                role="tab"
                :aria-selected="activeExtraTab === 'notes'"
                class="inline-flex items-center gap-2 rounded-xl border px-3.5 py-2 text-xs font-bold transition-colors cursor-pointer"
                :class="activeExtraTab === 'notes'
                  ? 'border-[var(--crimson)] bg-[var(--crimson-soft)] text-[var(--crimson)]'
                  : 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:text-[var(--text)]'"
                @click="toggleExtraTab('notes')"
              >
                <StickyNote class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>{{ $t(`${ns}.notes`) }}</span>
                <span
                  v-if="form.notes && form.notes.trim()"
                  class="h-1.5 w-1.5 rounded-full bg-[var(--crimson)]"
                  aria-hidden="true"
                ></span>
                <ChevronDown
                  class="h-3.5 w-3.5 transition-transform duration-150"
                  :class="activeExtraTab === 'notes' ? 'rotate-180' : ''"
                  aria-hidden="true"
                />
              </button>

              <button
                v-if="isOrder"
                type="button"
                role="tab"
                :aria-selected="activeExtraTab === 'attachment'"
                class="inline-flex items-center gap-2 rounded-xl border px-3.5 py-2 text-xs font-bold transition-colors cursor-pointer"
                :class="activeExtraTab === 'attachment'
                  ? 'border-[var(--crimson)] bg-[var(--crimson-soft)] text-[var(--crimson)]'
                  : 'border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] hover:text-[var(--text)]'"
                @click="toggleExtraTab('attachment')"
              >
                <Paperclip class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>{{ $t('purchaseOrders.attachmentTitle') }}</span>
                <span class="text-[10px] font-normal opacity-75">({{ $t('purchaseOrders.optionalBadge') }})</span>
                <span
                  v-if="selectedFileName"
                  class="h-1.5 w-1.5 rounded-full bg-[var(--crimson)]"
                  aria-hidden="true"
                ></span>
                <ChevronDown
                  class="h-3.5 w-3.5 transition-transform duration-150"
                  :class="activeExtraTab === 'attachment' ? 'rotate-180' : ''"
                  aria-hidden="true"
                />
              </button>
            </div>

            <!-- Notes Panel -->
            <div v-show="activeExtraTab === 'notes'" role="tabpanel">
              <textarea
                v-model="form.notes"
                rows="3"
                class="w-full h-[98px] rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3 text-xs text-[var(--text)] outline-none transition placeholder:text-[var(--muted)] focus:border-[var(--crimson)] focus:ring-2 focus:ring-[var(--crimson-soft)] resize-none"
                :placeholder="$t(`${ns}.notesPlaceholder`)"
              />
            </div>

            <!-- Attachment Panel (Order only) -->
            <div v-if="isOrder" v-show="activeExtraTab === 'attachment'" role="tabpanel">
              <div
                class="group relative flex h-[98px] cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-[var(--border)] bg-[var(--bg)]/50 px-4 text-center transition-colors hover:border-[var(--crimson)] hover:bg-[var(--card)]"
                @click="triggerFileInput"
              >
                <input
                  ref="fileInputRef"
                  type="file"
                  class="hidden"
                  accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
                  @change="handleFileSelected"
                />

                <div v-if="!selectedFileName" class="flex flex-col items-center justify-center">
                  <UploadCloud class="h-6 w-6 text-[var(--muted)] transition-colors group-hover:text-[var(--crimson)] mb-1" />
                  <p class="text-xs font-bold text-[var(--text)]">
                    {{ $t('purchaseOrders.uploadClickOrDrag') }}
                  </p>
                  <p class="text-[10px] text-[var(--muted)] mt-0.5">
                    {{ $t('purchaseOrders.uploadFormatsHint') }}
                  </p>
                </div>

                <div v-else class="flex items-center gap-2 max-w-full px-2" @click.stop>
                  <FileText class="h-6 w-6 text-[var(--crimson)] shrink-0" />
                  <div class="text-start truncate flex-1 min-w-0">
                    <p class="text-xs font-bold text-[var(--text)] truncate">{{ selectedFileName }}</p>
                    <p class="text-[10px] text-emerald-600 font-semibold">{{ $t('purchaseOrders.readyToAttach') }}</p>
                  </div>
                  <button
                    type="button"
                    class="rounded-lg p-1 text-[var(--muted)] hover:bg-rose-50 hover:text-rose-600 transition-colors me-2 shrink-0"
                    :title="$t('purchaseOrders.removeFile')"
                    @click="removeSelectedFile"
                  >
                    <X class="h-4 w-4" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer Actions -->
        <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] bg-[var(--bg)] px-6 py-4">
          <button
            type="button"
            class="rounded-xl border border-[var(--border)] bg-[var(--card)] px-4 py-2 text-xs font-bold text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)]"
            @click="emit('close')"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            type="submit"
            class="inline-flex items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2 text-xs font-bold text-white shadow-sm shadow-[var(--crimson)]/20 hover:bg-[var(--crimson-hover)] disabled:opacity-50"
            :disabled="submitting"
          >
            <span v-if="submitting" class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white border-t-transparent"></span>
            <span>{{ saveButtonLabel }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import { createPaginatedFetcher, createProductFetcher, createContactFetcher } from '../../utils/selectFetchers';
import {
  X,
  Plus,
  Trash2,
  AlertCircle,
  UploadCloud,
  FileText,
  StickyNote,
  Paperclip,
  ChevronDown,
} from 'lucide-vue-next';

const props = defineProps({
  mode: {
    type: String,
    required: true,
    validator: (v) => ['invoice', 'order'].includes(v),
  },
  open: { type: Boolean, default: false },
  document: { type: Object, default: null },
  suppliers: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  purchaseOrders: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);
const { t } = useI18n();

const isInvoice = computed(() => props.mode === 'invoice');
const isOrder = computed(() => props.mode === 'order');
const ns = computed(() => (isInvoice.value ? 'purchaseInvoices' : 'purchaseOrders'));
const isEdit = computed(() => !!props.document?.id);

const modalTitle = computed(() => {
  if (isInvoice.value) {
    return isEdit.value ? t('purchaseInvoices.edit') : t('purchaseInvoices.create');
  }
  return isEdit.value ? t('purchaseOrders.editTitle') : t('purchaseOrders.createTitle');
});

const modalSubtitle = computed(() =>
  isInvoice.value ? t('purchaseInvoices.createSubtitle') : t('purchaseOrders.createSubtitle')
);

const saveButtonLabel = computed(() => {
  if (isEdit.value) {
    return t('common.saveChanges');
  }
  return isInvoice.value ? t('purchaseInvoices.save') : t('common.save');
});

const sourceMode = ref('direct');
const submitting = ref(false);
const submitError = ref('');
const mergeNotice = ref('');
const errors = ref({});
const secondaryDateTouched = ref(false);

// Collapsible bottom tabs ('notes' | 'attachment' | null)
const activeExtraTab = ref(null);
const fileInputRef = ref(null);
const selectedFileName = ref('');

function toggleExtraTab(tab) {
  activeExtraTab.value = activeExtraTab.value === tab ? null : tab;
}

function triggerFileInput() {
  fileInputRef.value?.click();
}

function handleFileSelected(event) {
  const file = event.target.files?.[0];
  if (file) {
    selectedFileName.value = file.name;
  }
}

function removeSelectedFile() {
  selectedFileName.value = '';
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
  }
}

const todayStr = () => new Date().toISOString().substring(0, 10);

const form = reactive({
  purchase_order_id: null,
  supplier_id: null,
  journal_id: null,
  primary_date: todayStr(),
  secondary_date: todayStr(),
  notes: '',
  discount_type: 'percentage',
  discount_value: 0,
  items: [
    { product_id: null, quantity: 1, unit_price: null, discount_type: 'percentage', discount_value: 0 },
  ],
  expenses: [],
});

const primaryDateError = computed(() =>
  isInvoice.value ? errors.value.invoice_date?.[0] : errors.value.order_date?.[0]
);

const secondaryDateError = computed(() =>
  isInvoice.value ? errors.value.due_date?.[0] : errors.value.expected_delivery_date?.[0]
);

const selectedSupplierOption = ref(null);
const selectedJournalOption = ref(null);
const selectedPurchaseOrderOption = ref(null);

const fetchSupplierOptions = createContactFetcher({ isSupplier: true });
const fetchJournalOptions = createPaginatedFetcher(
  '/accounting/journals',
  () => ({ type: 'Purchase', is_active: 1 }),
  (journal) => ({
    value: journal.id,
    label: `${journal.code} — ${journal.name}`,
  })
);
const fetchProductOptions = createProductFetcher({ activeOnly: true });
const fetchPurchaseOrderOptions = createPaginatedFetcher(
  '/purchase-orders',
  () => ({ status: 'converted' }),
  (po) => ({
    value: po.id,
    label: `${po.order_number} (${po.supplier?.name || '—'})`,
    raw: po,
  })
);

function setSourceMode(mode) {
  sourceMode.value = mode;
  if (mode === 'direct') {
    form.purchase_order_id = null;
  }
}

function onPrimaryDateChange() {
  if (isInvoice.value && !isEdit.value && !secondaryDateTouched.value) {
    form.secondary_date = form.primary_date;
  }
}

async function onPurchaseOrderSelected(poId) {
  if (!poId) return;

  try {
    const res = await api.get(`/purchase-orders/${poId}`);
    const po = res.data.data;
    if (!po) return;

    form.supplier_id = po.supplier_id;
    selectedSupplierOption.value = po.supplier
      ? { value: po.supplier_id, label: po.supplier.name }
      : null;
    form.discount_type = po.discount_type || 'percentage';
    form.discount_value = Number(po.discount_value) || 0;
    if (po.notes && !form.notes) {
      form.notes = po.notes;
    }

    if (Array.isArray(po.items) && po.items.length > 0) {
      form.items = po.items.map((i) => ({
        product_id: i.product_id,
        product_option: i.product ? { value: i.product_id, label: i.product.name, barcode: i.product.barcode } : null,
        quantity: Number(i.quantity) || 1,
        unit_price: Number(i.unit_price) > 0 ? Number(i.unit_price) : null,
        discount_type: i.discount_type || 'percentage',
        discount_value: Number(i.discount_value) || 0,
      }));
    }
  } catch {
    // Keep existing inputs if loading PO fails
  }
}

function onProductSelected(rowIndex, opt) {
  mergeNotice.value = '';
  const current = form.items[rowIndex];
  if (opt && current) {
    current.product_option = opt;
  }
  if (!current?.product_id) return;

  const existingIndex = form.items.findIndex(
    (item, idx) => idx !== rowIndex && item.product_id === current.product_id
  );

  if (existingIndex !== -1) {
    const existing = form.items[existingIndex];
    existing.quantity = Number(
      ((Number(existing.quantity) || 0) + (Number(current.quantity) || 1)).toFixed(3)
    );
    form.items.splice(rowIndex, 1);
    mergeNotice.value = t('purchaseInvoices.duplicateMergedNote');
  }
}

function addItemRow() {
  form.items.push({
    product_id: null,
    quantity: 1,
    unit_price: null,
    discount_type: 'percentage',
    discount_value: 0,
  });
}

function removeItemRow(index) {
  if (form.items.length > 1) {
    form.items.splice(index, 1);
  }
}

function addExpenseRow() {
  form.expenses.push({
    name: '',
    amount: 0,
    notes: '',
  });
}

function removeExpenseRow(index) {
  form.expenses.splice(index, 1);
}

// Calculations matching backend exact order
function calcLineGross(item) {
  return (Number(item.quantity) || 0) * (Number(item.unit_price) || 0);
}

function calcLineDiscount(item) {
  const gross = calcLineGross(item);
  const val = Number(item.discount_value) || 0;
  if (val <= 0) return 0;
  if (item.discount_type === 'percentage') {
    const pct = Math.min(100, Math.max(0, val));
    return (gross * pct) / 100;
  }
  return Math.min(gross, Math.max(0, val));
}

function calcLineTotal(item) {
  return Math.max(0, calcLineGross(item) - calcLineDiscount(item));
}

const calcGrossTotal = computed(() =>
  form.items.reduce((sum, item) => sum + calcLineGross(item), 0)
);

const calcItemDiscountsTotal = computed(() =>
  form.items.reduce((sum, item) => sum + calcLineDiscount(item), 0)
);

const calcNetLinesTotal = computed(() =>
  Math.max(0, calcGrossTotal.value - calcItemDiscountsTotal.value)
);

const calcDocumentDiscountAmount = computed(() => {
  const net = calcNetLinesTotal.value;
  const val = Number(form.discount_value) || 0;
  if (val <= 0) return 0;
  if (form.discount_type === 'percentage') {
    const pct = Math.min(100, Math.max(0, val));
    return (net * pct) / 100;
  }
  return Math.min(net, Math.max(0, val));
});

const calcExpensesTotal = computed(() =>
  isInvoice.value
    ? form.expenses.reduce((sum, exp) => sum + Math.max(0, Number(exp.amount) || 0), 0)
    : 0
);

const calcGrandTotal = computed(() =>
  Math.max(0, calcNetLinesTotal.value - calcDocumentDiscountAmount.value + calcExpensesTotal.value)
);

const totalQuantity = computed(() => {
  const total = form.items.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
  return Number.isInteger(total) ? total : total.toFixed(3);
});

function formatMoney(amount) {
  return (Number(amount) || 0).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

watch(
  () => [props.open, props.document, props.mode],
  ([val]) => {
    if (val) {
      initForm();
    }
  },
  { immediate: true }
);

function initForm() {
  submitError.value = '';
  mergeNotice.value = '';
  errors.value = {};
  secondaryDateTouched.value = false;
  activeExtraTab.value = null;
  selectedFileName.value = '';
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
  }

  const doc = props.document;
  if (doc && doc.id) {
    if (isInvoice.value) {
      sourceMode.value = doc.purchase_order_id ? 'purchase_order' : 'direct';
      form.purchase_order_id = doc.purchase_order_id || null;
      selectedPurchaseOrderOption.value = doc.purchase_order
        ? { value: doc.purchase_order_id, label: `${doc.purchase_order.order_number} (${doc.supplier?.name || '—'})` }
        : null;
      form.journal_id = doc.journal_id || null;
      selectedJournalOption.value = doc.journal
        ? { value: doc.journal.id, label: `${doc.journal.code} — ${doc.journal.name}` }
        : null;
      form.primary_date = doc.invoice_date ? String(doc.invoice_date).substring(0, 10) : todayStr();
      form.secondary_date = doc.due_date ? String(doc.due_date).substring(0, 10) : form.primary_date;
      secondaryDateTouched.value = true;
    } else {
      sourceMode.value = 'direct';
      form.purchase_order_id = null;
      selectedPurchaseOrderOption.value = null;
      form.primary_date = doc.order_date ? String(doc.order_date).substring(0, 10) : todayStr();
      form.secondary_date = doc.expected_delivery_date ? String(doc.expected_delivery_date).substring(0, 10) : null;
    }

    form.supplier_id = doc.supplier_id;
    selectedSupplierOption.value = doc.supplier
      ? { value: doc.supplier_id, label: doc.supplier.name }
      : null;
    form.notes = doc.notes || '';
    form.discount_type = doc.discount_type || 'percentage';
    form.discount_value = Number(doc.discount_value) || 0;

    form.items = doc.items?.length
      ? doc.items.map((i) => ({
          product_id: i.product_id,
          product_option: i.product ? { value: i.product_id, label: i.product.name, barcode: i.product.barcode } : null,
          quantity: Number(i.quantity) || 1,
          unit_price: Number(i.unit_price) > 0 ? Number(i.unit_price) : null,
          discount_type: i.discount_type || 'percentage',
          discount_value: Number(i.discount_value) || 0,
        }))
      : [{ product_id: null, product_option: null, quantity: 1, unit_price: null, discount_type: 'percentage', discount_value: 0 }];

    form.expenses = isInvoice.value && Array.isArray(doc.expenses)
      ? doc.expenses.map((e) => ({
          name: e.name || '',
          amount: Number(e.amount) || 0,
          notes: e.notes || '',
        }))
      : [];
  } else {
    sourceMode.value = 'direct';
    form.purchase_order_id = null;
    selectedPurchaseOrderOption.value = null;
    form.supplier_id = null;
    selectedSupplierOption.value = null;
    form.journal_id = null;
    selectedJournalOption.value = null;
    if (isInvoice.value) {
      loadDefaultPurchaseJournal();
    }
    const today = todayStr();
    form.primary_date = today;
    form.secondary_date = isInvoice.value ? today : null;
    form.notes = '';
    form.discount_type = 'percentage';
    form.discount_value = 0;
    form.items = [{ product_id: null, quantity: 1, unit_price: null, discount_type: 'percentage', discount_value: 0 }];
    form.expenses = [];
  }
}

async function loadDefaultPurchaseJournal() {
  try {
    const res = await api.get('/settings/accounting');
    const data = res.data?.data;
    const defaultId = data?.default_purchase_journal_id;
    if (!defaultId || form.journal_id !== null || isEdit.value) {
      return;
    }

    const match = (data?.purchase_journals || []).find((j) => Number(j.id) === Number(defaultId));
    if (match) {
      form.journal_id = match.id;
      selectedJournalOption.value = {
        value: match.id,
        label: `${match.code} — ${match.name}`,
      };
    }
  } catch {
    // Allow manual journal selection if settings lookup fails.
  }
}

async function submitForm() {
  submitError.value = '';
  errors.value = {};

  const validItems = form.items.filter((i) => i.product_id && Number(i.quantity) > 0);
  if (validItems.length === 0) {
    submitError.value = isInvoice.value
      ? t('purchaseInvoices.atLeastOneItem')
      : t('purchaseOrders.selectAtLeastOneItem');
    return;
  }

  let hasInvalidPrice = false;
  form.items.forEach((item, idx) => {
    if (item.product_id && Number(item.quantity) > 0 && !(Number(item.unit_price) > 0)) {
      errors.value[`items.${idx}.unit_price`] = [t(`${ns.value}.unitPriceMustBePositive`)];
      hasInvalidPrice = true;
    }
  });
  if (hasInvalidPrice) {
    submitError.value = t(`${ns.value}.unitPriceMustBePositive`);
    return;
  }

  submitting.value = true;

  const docDiscVal = Number(form.discount_value) || 0;
  const hasDocDisc = docDiscVal > 0;

  const mappedItems = validItems.map((i) => {
    const itemDiscVal = Number(i.discount_value) || 0;
    const hasItemDisc = itemDiscVal > 0;
    return {
      product_id: i.product_id,
      quantity: Number(i.quantity),
      unit_price: Number(i.unit_price),
      discount_type: hasItemDisc ? i.discount_type : null,
      discount_value: hasItemDisc ? itemDiscVal : (isInvoice.value ? 0 : null),
    };
  });

  const payload = isInvoice.value
    ? {
        supplier_id: form.supplier_id,
        journal_id: form.journal_id,
        invoice_date: form.primary_date,
        due_date: form.secondary_date || form.primary_date,
        notes: form.notes || null,
        discount_type: hasDocDisc ? form.discount_type : null,
        discount_value: hasDocDisc ? docDiscVal : 0,
        items: mappedItems,
        expenses: form.expenses
          .filter((e) => e.name?.trim())
          .map((e) => ({
            name: e.name.trim(),
            amount: Number(e.amount) || 0,
            notes: e.notes?.trim() || null,
          })),
      }
    : {
        supplier_id: form.supplier_id,
        order_date: form.primary_date,
        expected_delivery_date: form.secondary_date || null,
        notes: form.notes || null,
        discount_type: hasDocDisc ? form.discount_type : null,
        discount_value: hasDocDisc ? docDiscVal : null,
        items: mappedItems,
      };

  if (isInvoice.value && !isEdit.value && sourceMode.value === 'purchase_order' && form.purchase_order_id) {
    payload.purchase_order_id = form.purchase_order_id;
  }

  const endpoint = isInvoice.value ? '/purchase-invoices' : '/purchase-orders';

  try {
    const res = isEdit.value
      ? await api.put(`${endpoint}/${props.document.id}`, payload)
      : await api.post(endpoint, payload);

    emit('saved', res.data.data);
    emit('close');
  } catch (err) {
    if (err.response?.status === 422) {
      errors.value = err.response.data.errors || {};
      submitError.value = err.response.data.message || t('common.validationError');
    } else {
      submitError.value = err.response?.data?.message || (isInvoice.value ? t('purchaseInvoices.unexpectedError') : t('purchaseOrders.saveError'));
    }
  } finally {
    submitting.value = false;
  }
}
</script>
