<template>
  <AppDrawer
    :open="open"
    width-class="max-w-2xl sm:max-w-3xl lg:max-w-4xl xl:max-w-5xl"
    side="end"
    @close="emit('close')"
  >
    <!-- Custom Drawer Header -->
    <template #header>
      <div class="flex items-start justify-between gap-4">
        <!-- Document Identifiers -->
        <div class="flex items-start gap-3">
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg)] text-[var(--muted)] shadow-xs"
          >
            <FileText class="h-6 w-6" />
          </div>

          <div>
            <div class="flex flex-wrap items-center gap-2.5">
              <h2 class="text-lg font-black tracking-tight text-[var(--text)] sm:text-xl font-mono" dir="ltr">
                {{ document?.document_number || '—' }}
              </h2>

              <!-- Copy document number button -->
              <button
                v-if="document?.document_number"
                type="button"
                class="inline-flex items-center gap-1 rounded-lg border border-[var(--border)] bg-[var(--bg)] px-2 py-0.5 text-[11px] font-medium text-[var(--muted)] transition-colors hover:text-[var(--text)]"
                :title="$t('common.copy')"
                @click="copyDocumentNumber"
              >
                <Check v-if="copied" class="h-3 w-3 text-emerald-500" />
                <Copy v-else class="h-3 w-3" />
                <span>{{ copied ? $t('common.copied') : $t('common.copy') }}</span>
              </button>

              <!-- Status Badge -->
              <span
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold shadow-xs"
                :class="statusBadgeClass(document?.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full bg-current"></span>
                {{ statusLabel(document?.status) }}
              </span>
            </div>

            <!-- Subtitle -->
            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]">
              <span class="font-bold text-[var(--text)]">{{ typeLabel(document?.document_type) }}</span>
              <span>•</span>
              <span class="inline-flex items-center gap-1">
                <Calendar class="h-3.5 w-3.5 text-[var(--muted)]" />
                {{ formatDate(document?.document_date) }}
              </span>
              <span>•</span>
              <span class="inline-flex items-center gap-1">
                <Building2 class="h-3.5 w-3.5 text-[var(--muted)]" />
                {{ document?.source_warehouse?.name || '—' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Header Actions: Print & Close -->
        <div class="flex items-center gap-1">
          <button
            type="button"
            class="print:hidden rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            :title="$t('common.print')"
            @click="printDocument"
          >
            <Printer class="h-5 w-5" />
          </button>

          <button
            type="button"
            class="print:hidden rounded-xl p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            :aria-label="$t('common.close')"
            @click="emit('close')"
          >
            <X class="h-5 w-5" />
          </button>
        </div>
      </div>
    </template>

    <!-- Top Sticky Action Bar (All actions placed at the TOP) -->
    <template #actions>
      <div class="space-y-3">
        <!-- Primary Workflow Buttons Row -->
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-[var(--muted)]">
              {{ $t('common.actions') }}:
            </span>

            <!-- Status Indicator if finalized -->
            <span
              v-if="document?.status === 'posted'"
              class="inline-flex items-center gap-1 rounded-md bg-purple-500/10 px-2 py-0.5 text-xs font-semibold text-purple-700 dark:text-purple-300"
            >
              <CheckCheck class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.detail.postedFinal') }}</span>
            </span>

            <span
              v-else-if="document?.status === 'reversed'"
              class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-300"
            >
              <RotateCcw class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.detail.reversedDoc') }}</span>
            </span>

            <span
              v-else-if="document?.status === 'cancelled'"
              class="inline-flex items-center gap-1 rounded-md bg-zinc-500/10 px-2 py-0.5 text-xs font-semibold text-zinc-500"
            >
              <Ban class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.detail.cancelledDoc') }}</span>
            </span>
          </div>

          <!-- Action Buttons Group -->
          <div class="flex flex-wrap items-center gap-2">
            <!-- Edit button for Draft / Rejected (Disabled/Hidden for Purchase Invoice linked GRs) -->
            <button
              v-if="document?.referenceable_type !== 'purchase_invoice' && (document?.status === 'draft' || document?.status === 'rejected')"
              type="button"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)]"
              @click="emit('edit', document)"
            >
              <Pencil class="h-3.5 w-3.5" />
              <span>{{ $t('common.edit') }}</span>
            </button>

            <!-- Submit for approval (Draft or Rejected) -->
            <button
              v-if="document?.status === 'draft' || document?.status === 'rejected'"
              type="button"
              :disabled="actionLoading"
              class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-blue-700 disabled:opacity-60"
              @click="performAction('submit')"
            >
              <Loader2 v-if="actionLoading && activeAction === 'submit'" class="h-3.5 w-3.5 animate-spin" />
              <Send v-else class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.submitForApproval') }}</span>
            </button>

            <!-- Approve (Pending Approval) -->
            <button
              v-if="document?.status === 'pending_approval'"
              type="button"
              :disabled="actionLoading"
              class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-xs transition-colors hover:bg-emerald-700 disabled:opacity-60"
              @click="performAction('approve')"
            >
              <Loader2 v-if="actionLoading && activeAction === 'approve'" class="h-3.5 w-3.5 animate-spin" />
              <CheckCircle v-else class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.approve') }}</span>
            </button>

            <!-- Reject (Pending Approval) -->
            <button
              v-if="document?.status === 'pending_approval'"
              type="button"
              :disabled="actionLoading"
              class="inline-flex items-center gap-1.5 rounded-xl border border-red-500/40 bg-red-500/10 px-3.5 py-1.5 text-xs font-bold text-red-600 transition-colors hover:bg-red-500/20 dark:text-red-400"
              @click="openReasonPrompt('reject')"
            >
              <XCircle class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.reject') }}</span>
            </button>

            <!-- Post (Approved) -->
            <button
              v-if="document?.status === 'approved'"
              type="button"
              :disabled="actionLoading"
              class="inline-flex items-center gap-1.5 rounded-xl bg-[var(--text)] px-4 py-1.5 text-xs font-bold text-[var(--card)] shadow-xs transition-opacity hover:opacity-90 disabled:opacity-60 cursor-pointer"
              @click="performAction('post')"
            >
              <Loader2 v-if="actionLoading && activeAction === 'post'" class="h-3.5 w-3.5 animate-spin" />
              <CheckCheck v-else class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.post') }}</span>
            </button>

            <!-- Reverse (Posted) -->
            <button
              v-if="document?.status === 'posted'"
              type="button"
              :disabled="actionLoading"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3.5 py-1.5 text-xs font-bold text-[var(--text)] shadow-xs transition-colors hover:bg-[var(--bg)] cursor-pointer"
              @click="openReasonPrompt('reverse')"
            >
              <RotateCcw class="h-3.5 w-3.5 text-[var(--muted)]" />
              <span>{{ $t('inventory.workflow.reverse') }}</span>
            </button>

            <!-- Cancel (Draft or Pending, hidden for Purchase Invoice linked GRs) -->
            <button
              v-if="document?.referenceable_type !== 'purchase_invoice' && (document?.status === 'draft' || document?.status === 'pending_approval')"
              type="button"
              :disabled="actionLoading"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 text-xs font-bold text-zinc-500 transition-colors hover:bg-[var(--bg)] disabled:opacity-60"
              @click="performAction('cancel')"
            >
              <Loader2 v-if="actionLoading && activeAction === 'cancel'" class="h-3.5 w-3.5 animate-spin" />
              <Ban v-else class="h-3.5 w-3.5" />
              <span>{{ $t('common.cancel') }}</span>
            </button>

            <span
              v-if="document?.referenceable_type === 'purchase_invoice' && (document?.status === 'draft' || document?.status === 'pending_approval' || document?.status === 'rejected')"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 text-[11px] font-semibold text-[var(--muted)]"
            >
              <AlertCircle class="h-3.5 w-3.5 text-[var(--muted)]" />
              <span>{{ $t('inventory.workflow.linkedGrEditCancelHint') }}</span>
            </span>
          </div>
        </div>

        <!-- Inline Reason Prompt Drawer (Appears right under top actions!) -->
        <Transition
          enter-active-class="transition duration-200 ease-out"
          enter-from-class="transform -translate-y-2 opacity-0"
          enter-to-class="transform translate-y-0 opacity-100"
          leave-active-class="transition duration-150 ease-in"
          leave-from-class="transform translate-y-0 opacity-100"
          leave-to-class="transform -translate-y-2 opacity-0"
        >
          <div
            v-if="showReasonDialog"
            class="rounded-xl border p-4 shadow-sm"
            :class="[
              reasonDialogType === 'reject'
                ? 'border-red-500/40 bg-red-500/5 dark:bg-red-500/10'
                : 'border-amber-500/40 bg-amber-500/5 dark:bg-amber-500/10'
            ]"
          >
            <div class="flex items-center justify-between">
              <label class="block text-xs font-bold text-[var(--text)]">
                {{ reasonDialogType === 'reject' ? $t('inventory.workflow.enterRejectionReason') : $t('inventory.workflow.enterReversalReason') }}
                <span class="text-[var(--crimson)]">*</span>
              </label>
              <button
                type="button"
                class="text-xs text-[var(--muted)] hover:text-[var(--text)]"
                @click="showReasonDialog = false"
              >
                {{ $t('common.cancel') }}
              </button>
            </div>

            <textarea
              v-model="actionReason"
              rows="2"
              class="mt-2 w-full rounded-xl border border-[var(--border)] bg-[var(--card)] p-2.5 text-xs text-[var(--text)] outline-none focus:border-[var(--crimson)]"
              :placeholder="$t('inventory.workflow.reasonPlaceholder')"
              autofocus
            />

            <div class="mt-2.5 flex items-center justify-end gap-2">
              <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-xs font-semibold text-[var(--muted)] hover:bg-[var(--card)]"
                @click="showReasonDialog = false"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                type="button"
                :disabled="actionLoading || !actionReason.trim()"
                class="inline-flex items-center gap-1 rounded-lg px-4 py-1.5 text-xs font-bold text-white shadow-xs disabled:opacity-50"
                :class="reasonDialogType === 'reject' ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700'"
                @click="confirmReasonAction"
              >
                <Loader2 v-if="actionLoading" class="h-3 w-3 animate-spin" />
                <span>{{ reasonDialogType === 'reject' ? $t('inventory.workflow.reject') : $t('inventory.workflow.reverse') }}</span>
              </button>
            </div>
          </div>
        </Transition>

        <!-- Top Error Banner -->
        <div
          v-if="actionError"
          class="flex items-center gap-2 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-xs font-medium text-red-600 dark:text-red-400"
        >
          <AlertCircle class="h-4 w-4 shrink-0" />
          <span class="flex-1">{{ actionError }}</span>
          <button
            type="button"
            class="text-red-600 hover:opacity-80"
            @click="actionError = ''"
          >
            <X class="h-4 w-4" />
          </button>
        </div>
      </div>
    </template>

    <!-- Drawer Body Content (Beautifully themed cards & tables) -->
    <div class="space-y-6 text-sm text-[var(--text)]">
      <!-- 1. Quick Stats Highlights -->
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <!-- Document Type Card -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Package class="h-4 w-4 text-[var(--crimson)]" />
            <span>{{ $t('inventory.fields.type') }}</span>
          </div>
          <p class="mt-1 text-sm font-extrabold text-[var(--text)]">
            {{ typeLabel(document?.document_type) }}
          </p>
          <p v-if="document?.adjustment_direction" class="mt-0.5 text-xs text-[var(--muted)]">
            {{ directionLabel(document.adjustment_direction) }}
          </p>
          <p v-else-if="document?.return_direction" class="mt-0.5 text-xs text-[var(--muted)]">
            {{ directionLabel(document.return_direction) }}
          </p>
          <p v-else class="mt-0.5 text-xs text-[var(--muted)]">
            {{ formatDate(document?.document_date) }}
          </p>
        </div>

        <!-- Warehouses Flow Card -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <Building2 class="h-4 w-4 text-[var(--gold)]" />
            <span>{{ $t('inventory.detail.warehousePath') }}</span>
          </div>
          <div class="mt-1 flex items-center gap-2 text-xs font-bold text-[var(--text)]">
            <span class="rounded-lg bg-[var(--bg)] px-2 py-1">
              {{ document?.source_warehouse?.name || '—' }}
            </span>
            <span class="inline-block text-[var(--muted)] rtl:rotate-180">➔</span>
            <span class="rounded-lg bg-[var(--bg)] px-2 py-1">
              {{ document?.destination_warehouse?.name || $t('inventory.detail.externalOrNone') }}
            </span>
          </div>
          <p class="mt-1 text-[11px] text-[var(--muted)]">
            {{ document?.destination_warehouse ? $t('inventory.detail.interWarehouseTransfer') : $t('inventory.detail.singleWarehouseMovement') }}
          </p>
        </div>

        <!-- Total Items & Quantities Card -->
        <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs">
          <div class="flex items-center gap-2 text-xs font-bold text-[var(--muted)]">
            <FileText class="h-4 w-4 text-[var(--muted)]" />
            <span>{{ $t('inventory.detail.quantitiesSummary') }}</span>
          </div>
          <div class="mt-1 flex items-baseline gap-2">
            <span class="text-xl font-black text-[var(--text)]">
              {{ totalQuantity.toLocaleString() }}
            </span>
            <span class="text-xs font-semibold text-[var(--muted)]">{{ $t('inventory.detail.unitsRequired') }}</span>
          </div>
          <p class="mt-0.5 text-xs text-[var(--muted)]">
            {{ $t('inventory.detail.itemsInDoc', { count: document?.items?.length || 0, unit: $t('inventory.fields.items') }) }}
          </p>
        </div>
      </div>

      <!-- Linked Reference Box (Purchase Invoice, POS Return, POS Sale, or Stocktake) -->
      <div
        v-if="linkedReference"
        class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg)]/60 p-4"
      >
        <div class="flex items-center gap-3">
          <div
            class="w-10 h-10 rounded-xl flex items-center justify-center font-bold shrink-0 bg-[var(--card)] border border-[var(--border)] text-[var(--muted)]"
          >
            <RotateCcw v-if="linkedReference.type === 'pos_return'" class="w-5 h-5" />
            <ClipboardCheck v-else-if="linkedReference.type === 'stocktake'" class="w-5 h-5" />
            <Receipt v-else class="w-5 h-5" />
          </div>
          <div>
            <div class="flex items-center gap-2">
              <span class="text-xs font-bold text-[var(--muted)]">
                {{ linkedReference.label }}
              </span>
              <span
                class="font-mono text-sm font-black text-[var(--text)]"
                dir="ltr"
              >
                {{ linkedReference.number }}
              </span>
            </div>
            <p class="text-[11px] text-[var(--muted)] mt-0.5">
              {{ linkedReference.hint }}
            </p>
          </div>
        </div>

        <button
          type="button"
          class="h-8 px-3.5 rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--text)] hover:bg-[var(--bg)] text-xs font-bold transition flex items-center gap-1.5 cursor-pointer shadow-xs"
          @click="linkedReference.action()"
        >
          <ExternalLink class="w-3.5 h-3.5 text-[var(--muted)]" />
          <span>{{ linkedReference.actionLabel }}</span>
        </button>
      </div>

      <!-- 2. Conditional Reasons / Alerts -->
      <!-- Rejection Alert -->
      <div
        v-if="document?.rejection_reason"
        class="flex items-start gap-3 rounded-2xl border border-rose-500/25 bg-rose-500/5 p-4 text-rose-700 dark:text-rose-400"
      >
        <XCircle class="mt-0.5 h-5 w-5 shrink-0" />
        <div>
          <span class="block text-xs font-bold">{{ $t('inventory.fields.rejectionReason') }}</span>
          <p class="mt-1 text-sm font-semibold text-[var(--text)]">{{ document.rejection_reason }}</p>
        </div>
      </div>

      <!-- Reversal Alert -->
      <div
        v-if="document?.reversal_reason"
        class="flex items-start gap-3 rounded-2xl border border-amber-500/25 bg-amber-500/5 p-4 text-amber-700 dark:text-amber-400"
      >
        <RotateCcw class="mt-0.5 h-5 w-5 shrink-0" />
        <div>
          <span class="block text-xs font-bold">{{ $t('inventory.fields.reversalReason') }}</span>
          <p class="mt-1 text-sm font-semibold text-[var(--text)]">{{ document.reversal_reason }}</p>
        </div>
      </div>

      <!-- Write Off Reason -->
      <div
        v-if="document?.write_off_reason"
        class="flex items-start gap-3 rounded-2xl border border-rose-500/25 bg-rose-500/5 p-4 text-rose-700 dark:text-rose-400"
      >
        <AlertCircle class="mt-0.5 h-5 w-5 shrink-0" />
        <div>
          <span class="block text-xs font-bold">{{ $t('inventory.fields.writeOffReason') }}</span>
          <p class="mt-1 text-sm font-semibold text-[var(--text)]">{{ document.write_off_reason }}</p>
        </div>
      </div>

      <!-- General Notes -->
      <div
        v-if="document?.notes"
        class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-4 shadow-xs"
      >
        <span class="block text-xs font-bold text-[var(--muted)]">{{ $t('inventory.fields.notes') }}</span>
        <p class="mt-1 text-sm text-[var(--text)] leading-relaxed">{{ document.notes }}</p>
      </div>

      <!-- 3. Items Table -->
      <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-xs overflow-hidden">
        <div class="flex items-center justify-between border-b border-[var(--border)] px-5 py-3.5 bg-[var(--bg)]/50">
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-extrabold text-[var(--text)]">
              {{ $t('inventory.fields.itemsList') }}
            </h3>
            <span class="rounded-full bg-[var(--bg)] border border-[var(--border)] px-2.5 py-0.5 text-xs font-bold text-[var(--text)]">
              {{ document?.items?.length || 0 }} {{ $t('inventory.fields.items') }}
            </span>
          </div>

          <div class="text-xs font-bold text-[var(--muted)]">
            {{ $t('inventory.detail.totalQuantities') }} <span class="font-extrabold text-[var(--text)]">{{ totalQuantity.toLocaleString() }}</span>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-start text-sm">
            <thead class="border-b border-[var(--border)] bg-[var(--bg)] text-xs font-bold text-[var(--muted)]">
              <tr>
                <th class="w-12 px-4 py-3 text-center">#</th>
                <th class="px-4 py-3">{{ $t('inventory.fields.product') }}</th>
                <th class="px-4 py-3">{{ $t('inventory.fields.barcode') }}</th>
                <th class="px-4 py-3 text-center">{{ $t('inventory.fields.quantity') }}</th>
                <th class="px-4 py-3">{{ $t('inventory.fields.lineNotes') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-[var(--border)]">
              <tr
                v-for="(item, idx) in document?.items || []"
                :key="item.id || idx"
                class="transition-colors hover:bg-[var(--bg)]/60"
              >
                <td class="px-4 py-3 text-center font-bold text-xs text-[var(--muted)]">
                  {{ idx + 1 }}
                </td>
                <td class="px-4 py-3">
                  <div class="font-bold text-[var(--text)]">
                    {{ item.product?.name || '—' }}
                  </div>
                </td>
                <td class="px-4 py-3">
                  <span class="rounded-md bg-[var(--bg)] px-2 py-0.5 font-mono text-xs font-semibold text-[var(--muted)]" dir="ltr">
                    {{ item.product?.barcode || '—' }}
                  </span>
                </td>
                <td class="px-4 py-3 text-center">
                  <span class="inline-flex min-w-10 items-center justify-center rounded-lg bg-[var(--bg)] border border-[var(--border)] px-2.5 py-1 text-sm font-black text-[var(--text)]">
                    {{ Number(item.quantity).toLocaleString() }}
                  </span>
                </td>
                <td class="px-4 py-3 text-xs text-[var(--muted)]">
                  {{ item.notes || '—' }}
                </td>
              </tr>
              <tr v-if="!document?.items || document.items.length === 0">
                <td colspan="5" class="py-8 text-center text-xs text-[var(--muted)]">
                  {{ $t('inventory.detail.noItems') }}
                </td>
              </tr>
            </tbody>
            <tfoot v-if="document?.items && document.items.length > 0" class="border-t border-[var(--border)] bg-[var(--bg)]/70 text-xs font-bold">
              <tr>
                <td colspan="3" class="px-4 py-3 text-[var(--muted)] text-end">
                  {{ $t('inventory.detail.grandTotalQuantities') }}
                </td>
                <td class="px-4 py-3 text-center font-black text-sm text-[var(--text)]">
                  {{ totalQuantity.toLocaleString() }}
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- 4. Workflow Audit Trail Timeline -->
      <div class="rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 shadow-xs">
        <h4 class="mb-4 flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-[var(--muted)]">
          <Clock class="h-4 w-4 text-[var(--gold)]" />
          <span>{{ $t('inventory.workflow.auditTrail') }}</span>
        </h4>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <!-- Created -->
          <div class="rounded-xl border border-[var(--border)] bg-[var(--bg)] p-3">
            <div class="flex items-center gap-1.5 text-xs text-[var(--muted)]">
              <User class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.created') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.creator?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.created_at) }}
            </p>
          </div>

          <!-- Reviewed -->
          <div
            v-if="document?.reviewed_at"
            class="rounded-xl border border-blue-500/20 bg-blue-500/5 p-3"
          >
            <div class="flex items-center gap-1.5 text-xs text-blue-600 dark:text-blue-400">
              <CheckCircle class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.reviewed') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.reviewer?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.reviewed_at) }}
            </p>
          </div>

          <!-- Approved -->
          <div
            v-if="document?.approved_at"
            class="rounded-xl border border-emerald-500/20 bg-emerald-500/5 p-3"
          >
            <div class="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
              <CheckCircle class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.approved') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.approver?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.approved_at) }}
            </p>
          </div>

          <!-- Posted -->
          <div
            v-if="document?.posted_at"
            class="rounded-xl border border-purple-500/20 bg-purple-500/5 p-3"
          >
            <div class="flex items-center gap-1.5 text-xs text-purple-600 dark:text-purple-400">
              <CheckCheck class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.posted') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.poster?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.posted_at) }}
            </p>
          </div>

          <!-- Rejected -->
          <div
            v-if="document?.rejected_at"
            class="rounded-xl border border-red-500/20 bg-red-500/5 p-3"
          >
            <div class="flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400">
              <XCircle class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.rejected') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.rejecter?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.rejected_at) }}
            </p>
          </div>

          <!-- Cancelled -->
          <div
            v-if="document?.cancelled_at"
            class="rounded-xl border border-zinc-500/20 bg-zinc-500/5 p-3"
          >
            <div class="flex items-center gap-1.5 text-xs text-zinc-500">
              <Ban class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.cancelled') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.canceller?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.cancelled_at) }}
            </p>
          </div>

          <!-- Reversed -->
          <div
            v-if="document?.reversed_at"
            class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-3"
          >
            <div class="flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400">
              <RotateCcw class="h-3.5 w-3.5" />
              <span>{{ $t('inventory.workflow.reversed') }}</span>
            </div>
            <p class="mt-1 font-bold text-xs text-[var(--text)]">
              {{ document?.reverser?.name || '—' }}
            </p>
            <p class="mt-0.5 text-[11px] text-[var(--muted)]">
              {{ formatDateTime(document?.reversed_at) }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </AppDrawer>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  Ban,
  Building2,
  Calendar,
  Check,
  CheckCheck,
  CheckCircle,
  ClipboardCheck,
  Clock,
  Copy,
  ExternalLink,
  FileText,
  Loader2,
  Package,
  Pencil,
  Printer,
  Receipt,
  RotateCcw,
  Send,
  User,
  X,
  XCircle,
} from 'lucide-vue-next';
import api from '../../services/api';
import AppDrawer from '../../components/ui/AppDrawer.vue';
import { formatDateTime as formatAppDateTime } from '../../utils/date';

const props = defineProps({
  open: { type: Boolean, default: false },
  document: { type: Object, default: null },
});

const emit = defineEmits(['close', 'updated', 'edit', 'open-pos-sale', 'open-pos-return']);
const router = useRouter();
const { t, locale } = useI18n();

const actionLoading = ref(false);
const activeAction = ref('');
const actionError = ref('');
const showReasonDialog = ref(false);
const reasonDialogType = ref('reject');
const actionReason = ref('');
const copied = ref(false);

const normalizedReferenceType = computed(() => {
  const raw = props.document?.referenceable_type;
  if (!raw) return null;
  if (raw === 'purchase_invoice' || raw === 'App\\Models\\PurchaseInvoice') return 'purchase_invoice';
  if (raw === 'pos_sale' || raw === 'App\\Models\\PosSale') return 'pos_sale';
  if (raw === 'pos_return' || raw === 'App\\Models\\PosReturn') return 'pos_return';
  if (raw === 'stocktake' || raw === 'App\\Models\\Stocktake') return 'stocktake';
  return raw;
});

const linkedReference = computed(() => {
  const doc = props.document;
  const type = normalizedReferenceType.value;
  if (!doc || !type) return null;

  const refId = doc.referenceable?.id || doc.referenceable_id;
  if (!refId && !doc.referenceable) return null;

  const map = {
    purchase_invoice: {
      type: 'purchase_invoice',
      label: t('inventory.fields.linkedPurchaseInvoice'),
      number: doc.referenceable?.invoice_number || `#${refId}`,
      hint: t('inventory.fields.linkedPurchaseInvoiceHint'),
      actionLabel: t('purchaseInvoices.viewPurchaseInvoice'),
      action: () => goToPurchaseInvoice(refId),
    },
    pos_sale: {
      type: 'pos_sale',
      label: t('inventory.fields.linkedPosSale'),
      number: doc.referenceable?.invoice_number || `#${refId}`,
      hint: t('inventory.fields.linkedPosSaleHint'),
      actionLabel: t('inventory.fields.openInvoiceDetails'),
      action: () => emit('open-pos-sale', refId),
    },
    pos_return: {
      type: 'pos_return',
      label: `${t('posReturns.linkedReceipt')} / ${t('posReturns.title')}`,
      number: doc.referenceable?.return_number || `#${refId}`,
      hint: t('posReturns.linkedReturnHint'),
      actionLabel: t('posReturns.openReturnDetails'),
      action: () => emit('open-pos-return', refId),
    },
    stocktake: {
      type: 'stocktake',
      label: t('inventory.sources.stocktakeDetailLabel'),
      number: doc.referenceable?.stocktake_number || `#${refId}`,
      hint: t('inventory.sources.stocktakeDetailHint'),
      actionLabel: t('inventory.sources.stocktakeDetailAction'),
      action: () => goToStocktake(refId),
    },
  };

  return map[type] || null;
});

const totalQuantity = computed(() => {
  if (!props.document?.items) return 0;
  return props.document.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
});

function copyDocumentNumber() {
  if (!props.document?.document_number) return;
  if (navigator?.clipboard?.writeText) {
    navigator.clipboard.writeText(props.document.document_number);
    copied.value = true;
    setTimeout(() => {
      copied.value = false;
    }, 2000);
  }
}

function printDocument() {
  window.print();
}

function goToPurchaseInvoice(invoiceId) {
  if (!invoiceId) return;
  emit('close');
  router.push({
    path: '/purchase-invoices',
    query: { open: String(invoiceId) },
  });
}

function goToStocktake(stocktakeId) {
  if (!stocktakeId) return;
  emit('close');
  router.push({
    path: '/inventory/stocktakes',
    query: { open: String(stocktakeId) },
  });
}

function typeLabel(type) {
  if (!type) return '—';
  const labels = {
    goods_receipt: t('inventory.types.goods_receipt'),
    goods_delivery: t('inventory.types.goods_delivery'),
    warehouse_transfer: t('inventory.types.warehouse_transfer'),
    stock_adjustment: t('inventory.types.stock_adjustment'),
    inventory_return: t('inventory.types.inventory_return'),
    stock_write_off: t('inventory.types.stock_write_off'),
    opening_balance: t('inventory.types.opening_balance'),
  };
  return labels[type] || type;
}

function statusLabel(status) {
  if (!status) return '—';
  const labels = {
    draft: t('inventory.statuses.draft'),
    pending_approval: t('inventory.statuses.pending_approval'),
    approved: t('inventory.statuses.approved'),
    posted: t('inventory.statuses.posted'),
    rejected: t('inventory.statuses.rejected'),
    cancelled: t('inventory.statuses.cancelled'),
    reversed: t('inventory.statuses.reversed'),
  };
  return labels[status] || status;
}

function statusBadgeClass(status) {
  switch (status) {
    case 'draft':
      return 'bg-zinc-500/10 text-zinc-600 dark:text-zinc-400 border border-zinc-500/30';
    case 'pending_approval':
      return 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border border-blue-500/30';
    case 'approved':
      return 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30';
    case 'posted':
      return 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border border-purple-500/30';
    case 'rejected':
      return 'bg-red-500/10 text-red-600 dark:text-red-400 border border-red-500/30';
    case 'cancelled':
      return 'bg-zinc-500/10 text-zinc-400 border border-zinc-500/20';
    case 'reversed':
      return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border border-amber-500/30';
    default:
      return 'bg-zinc-500/10 text-zinc-500';
  }
}

function directionLabel(dir) {
  if (!dir) return '—';
  const labels = {
    increase: t('inventory.directions.increaseBadge'),
    decrease: t('inventory.directions.decreaseBadge'),
    inbound: t('inventory.directions.inboundBadge'),
    outbound: t('inventory.directions.outboundBadge'),
  };
  return labels[dir] || dir;
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  return dateStr.substring(0, 10);
}

function formatDateTime(dtStr) {
  return formatAppDateTime(dtStr, locale.value) || '—';
}

function openReasonPrompt(type) {
  reasonDialogType.value = type;
  actionReason.value = '';
  showReasonDialog.value = true;
}

async function confirmReasonAction() {
  if (!actionReason.value.trim()) return;
  if (reasonDialogType.value === 'reject') {
    await performAction('reject', { rejection_reason: actionReason.value.trim() });
  } else if (reasonDialogType.value === 'reverse') {
    await performAction('reverse', { reversal_reason: actionReason.value.trim() });
  }
  showReasonDialog.value = false;
}

async function performAction(action, payload = {}) {
  if (!props.document?.id) return;
  actionLoading.value = true;
  activeAction.value = action;
  actionError.value = '';

  try {
    const res = await api.post(`/inventory-documents/${props.document.id}/${action}`, payload);
    emit('updated', res.data?.data);
  } catch (err) {
    actionError.value = err?.response?.data?.message || err?.response?.data?.errors?.status?.[0] || t('inventory.detail.actionFailed');
  } finally {
    actionLoading.value = false;
    activeAction.value = '';
  }
}
</script>
