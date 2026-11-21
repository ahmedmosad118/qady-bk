<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/55 backdrop-blur-xs p-3 sm:p-5"
    @click.self="emitClose"
  >
    <div class="w-full max-w-4xl max-h-[92vh] flex flex-col bg-card border border-line rounded-2xl shadow-2xl overflow-hidden">
      <!-- Modal Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-line shrink-0">
        <div>
          <h2 class="text-lg font-bold text-ink flex items-center gap-2">
            <ReceiptText class="w-5 h-5 text-crimson" />
            <span>{{ isEdit ? $t('expenses.editExpense') : $t('expenses.newExpense') }}</span>
          </h2>
          <p class="text-xs text-muted mt-0.5">
            <template v-if="isEdit && form.expense_number">
              {{ $t('expenses.expenseNumber') }}:
              <span class="font-mono font-semibold text-ink">{{ form.expense_number }}</span>
            </template>
            <template v-else>
              {{ $t('expenses.autoNumberHint') }}
            </template>
          </p>
        </div>

        <button
          type="button"
          @click="emitClose"
          class="p-1.5 rounded-lg text-muted hover:text-ink hover:bg-canvas cursor-pointer"
        >
          <X class="w-5 h-5" />
        </button>
      </div>

      <!-- Modal Body -->
      <form @submit.prevent="submitForm" class="flex-1 overflow-y-auto p-6 space-y-6">
        <!-- Read-only posted guard warning -->
        <div
          v-if="isEdit && expense?.status === 'posted'"
          class="flex items-center gap-2.5 p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-700 dark:text-amber-300 text-sm"
        >
          <AlertTriangle class="w-5 h-5 shrink-0" />
          <span>{{ $t('expenses.postedReadonlyNotice') }}</span>
        </div>

        <!-- Validation Error Summary -->
        <div
          v-if="generalError"
          class="p-3.5 rounded-xl bg-red-500/10 border border-red-500/30 text-red-600 dark:text-red-400 text-sm"
        >
          {{ generalError }}
        </div>

        <!-- Header Fields Grid -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Expense Category -->
          <div>
            <label class="block text-sm font-medium text-ink mb-1.5">
              {{ $t('expenses.category') }} <span class="text-crimson">*</span>
            </label>
            <SearchableSelect
              v-model="form.expense_category_id"
              :fetch-options="fetchExpenseCategoryOptions"
              :selected-option-data="selectedCategoryOption"
              :paginate="10"
              :placeholder="$t('expenses.selectCategory')"
              :disabled="isLocked"
            />
            <p v-if="fieldError('expense_category_id')" class="text-xs text-red-500 mt-1">
              {{ fieldError('expense_category_id') }}
            </p>
          </div>

          <!-- Optional Contact -->
          <div>
            <label class="block text-sm font-medium text-ink mb-1.5">
              {{ $t('expenses.contact') }}
              <span class="text-xs text-muted font-normal">({{ $t('expenses.optional') }})</span>
            </label>
            <SearchableSelect
              v-model="form.contact_id"
              :fetch-options="fetchContactOptions"
              :selected-option-data="selectedContactOption"
              :placeholder="$t('expenses.selectContact')"
              :clearable="true"
              :paginate="10"
              :disabled="isLocked"
            />
            <p v-if="fieldError('contact_id')" class="text-xs text-red-500 mt-1">
              {{ fieldError('contact_id') }}
            </p>
          </div>

          <!-- Expense Date -->
          <div>
            <label class="block text-sm font-medium text-ink mb-1.5">
              {{ $t('expenses.expenseDate') }} <span class="text-crimson">*</span>
            </label>
            <input
              v-model="form.expense_date"
              type="date"
              required
              :disabled="isLocked"
              class="w-full px-3.5 py-2 rounded-xl border bg-canvas text-ink text-sm focus:outline-none focus:ring-2 focus:ring-crimson/30"
              :class="fieldError('expense_date') ? 'border-red-500' : 'border-line'"
            />
            <p v-if="fieldError('expense_date')" class="text-xs text-red-500 mt-1">
              {{ fieldError('expense_date') }}
            </p>
          </div>
        </div>

        <!-- Header Notes -->
        <div>
          <label class="block text-sm font-medium text-ink mb-1.5">
            {{ $t('expenses.notes') }}
          </label>
          <textarea
            v-model="form.notes"
            rows="2"
            maxlength="2000"
            :disabled="isLocked"
            :placeholder="$t('expenses.notesPlaceholder')"
            class="w-full px-3.5 py-2.5 rounded-xl border border-line bg-canvas text-ink text-sm focus:outline-none focus:ring-2 focus:ring-crimson/30"
          />
          <p v-if="fieldError('notes')" class="text-xs text-red-500 mt-1">
            {{ fieldError('notes') }}
          </p>
        </div>

        <!-- Expense Items Section -->
        <div class="space-y-3">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-sm font-bold text-ink">
                {{ $t('expenses.items') }} ({{ form.items.length }})
              </h3>
              <p class="text-xs text-muted">{{ $t('expenses.itemsHint') }}</p>
            </div>
            <button
              v-if="!isLocked"
              type="button"
              @click="addItem"
              class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-crimson/10 text-crimson text-xs font-semibold hover:bg-crimson/20 transition-colors cursor-pointer"
            >
              <Plus class="w-4 h-4" />
              <span>{{ $t('expenses.addItem') }}</span>
            </button>
          </div>

          <p v-if="fieldError('items')" class="text-xs text-red-500">
            {{ fieldError('items') }}
          </p>

          <div class="space-y-4">
            <div
              v-for="(item, index) in form.items"
              :key="item._uid"
              class="p-4 rounded-2xl border border-line bg-canvas/60 space-y-3"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-bold uppercase tracking-wider text-muted">
                  {{ $t('expenses.itemRow', { number: index + 1 }) }}
                </span>
                <button
                  v-if="!isLocked"
                  type="button"
                  :disabled="form.items.length <= 1"
                  @click="removeItem(index)"
                  :title="form.items.length <= 1 ? $t('expenses.minOneItemRequired') : $t('expenses.removeItem')"
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium transition-colors"
                  :class="form.items.length <= 1
                    ? 'text-muted/40 cursor-not-allowed'
                    : 'text-red-500 hover:bg-red-500/10 cursor-pointer'"
                >
                  <Trash2 class="w-3.5 h-3.5" />
                  <span>{{ $t('expenses.remove') }}</span>
                </button>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-12 gap-3">
                <div class="md:col-span-5">
                  <label class="block text-xs font-medium text-ink mb-1">
                    {{ $t('expenses.itemName') }} <span class="text-crimson">*</span>
                  </label>
                  <input
                    v-model="item.name"
                    type="text"
                    required
                    maxlength="255"
                    :disabled="isLocked"
                    :placeholder="$t('expenses.itemNamePlaceholder')"
                    class="w-full px-3 py-2 rounded-xl border bg-card text-ink text-sm focus:outline-none focus:ring-2 focus:ring-crimson/30"
                    :class="fieldError(`items.${index}.name`) ? 'border-red-500' : 'border-line'"
                  />
                  <p v-if="fieldError(`items.${index}.name`)" class="text-xs text-red-500 mt-1">
                    {{ fieldError(`items.${index}.name`) }}
                  </p>
                </div>

                <div class="md:col-span-4">
                  <label class="block text-xs font-medium text-ink mb-1">
                    {{ $t('expenses.description') }}
                  </label>
                  <input
                    v-model="item.description"
                    type="text"
                    maxlength="2000"
                    :disabled="isLocked"
                    :placeholder="$t('expenses.itemDescPlaceholder')"
                    class="w-full px-3 py-2 rounded-xl border border-line bg-card text-ink text-sm focus:outline-none focus:ring-2 focus:ring-crimson/30"
                  />
                </div>

                <div class="md:col-span-3">
                  <label class="block text-xs font-medium text-ink mb-1">
                    {{ $t('expenses.amount') }} <span class="text-crimson">*</span>
                  </label>
                  <input
                    v-model="item.amount"
                    type="number"
                    step="0.01"
                    min="0.01"
                    required
                    :disabled="isLocked"
                    placeholder="0.00"
                    class="w-full px-3 py-2 rounded-xl border bg-card text-ink text-sm font-mono focus:outline-none focus:ring-2 focus:ring-crimson/30"
                    :class="fieldError(`items.${index}.amount`) ? 'border-red-500' : 'border-line'"
                  />
                  <p v-if="fieldError(`items.${index}.amount`)" class="text-xs text-red-500 mt-1">
                    {{ fieldError(`items.${index}.amount`) }}
                  </p>
                </div>
              </div>

              <!-- Item Attachments -->
              <div class="pt-2 border-t border-line/70 space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="text-xs font-medium text-muted flex items-center gap-1.5">
                    <Paperclip class="w-3.5 h-3.5" />
                    <span>{{ $t('expenses.itemAttachments') }}</span>
                  </span>

                  <label
                    v-if="!isLocked"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-dashed border-crimson/50 text-crimson text-xs font-medium hover:bg-crimson/5 cursor-pointer transition-colors"
                  >
                    <Upload class="w-3.5 h-3.5" />
                    <span>{{ $t('expenses.attachFiles') }}</span>
                    <input
                      type="file"
                      multiple
                      accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.jpg,.jpeg,.png,.gif,.webp,.pdf"
                      class="hidden"
                      @change="onFilesSelected(index, $event)"
                    />
                  </label>
                </div>

                <!-- Existing Saved Attachments (on edit) -->
                <div v-if="item.existingAttachments.length > 0" class="flex flex-wrap gap-2">
                  <div
                    v-for="att in item.existingAttachments"
                    :key="att.id"
                    class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-xl border text-xs transition-colors"
                    :class="isAttachmentMarkedRemoved(item, att.id)
                      ? 'border-red-500/40 bg-red-500/5 text-red-500 line-through opacity-75'
                      : 'border-line bg-card text-ink'"
                  >
                    <FileText v-if="isPdf(att.mime_type)" class="w-3.5 h-3.5 text-crimson shrink-0" />
                    <ImageIcon v-else class="w-3.5 h-3.5 text-gold shrink-0" />
                    <button
                      type="button"
                      @click="previewExistingAttachment(item.id, att)"
                      class="max-w-[160px] truncate hover:text-crimson cursor-pointer"
                      :title="att.original_name"
                    >
                      {{ att.original_name }}
                    </button>
                    <button
                      type="button"
                      @click="downloadExistingAttachment(item.id, att)"
                      :title="$t('expenses.downloadAttachment')"
                      class="text-muted hover:text-ink cursor-pointer"
                    >
                      <Download class="w-3.5 h-3.5" />
                    </button>
                    <button
                      v-if="!isLocked"
                      type="button"
                      @click="toggleRemoveExistingAttachment(item, att.id)"
                      :title="isAttachmentMarkedRemoved(item, att.id) ? $t('expenses.undoRemove') : $t('expenses.remove')"
                      class="text-muted hover:text-red-500 cursor-pointer"
                    >
                      <RotateCcw v-if="isAttachmentMarkedRemoved(item, att.id)" class="w-3.5 h-3.5" />
                      <X v-else class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>

                <!-- Newly Staged Files -->
                <div v-if="item.newFiles.length > 0" class="flex flex-wrap gap-2">
                  <div
                    v-for="(file, fileIdx) in item.newFiles"
                    :key="`${file.name}-${fileIdx}`"
                    class="inline-flex items-center gap-2 px-2.5 py-1.5 rounded-xl border border-crimson/30 bg-crimson/5 text-ink text-xs"
                  >
                    <Upload class="w-3 h-3 text-crimson shrink-0" />
                    <span class="max-w-[180px] truncate">{{ file.name }}</span>
                    <span class="text-muted">({{ formatFileSize(file.size) }})</span>
                    <button
                      v-if="!isLocked"
                      type="button"
                      @click="removeNewFile(index, fileIdx)"
                      class="text-muted hover:text-red-500 cursor-pointer"
                    >
                      <X class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </div>

                <p v-if="fieldError(`items.${index}.attachments`)" class="text-xs text-red-500">
                  {{ fieldError(`items.${index}.attachments`) }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Computed Totals Summary -->
        <div class="p-4 rounded-2xl border border-line bg-canvas/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          <div class="text-xs text-muted">
            {{ $t('expenses.totalsCalculatedByBackend') }}
          </div>
          <div class="flex items-center gap-6">
            <div>
              <span class="text-xs text-muted block">{{ $t('expenses.subtotal') }}</span>
              <span class="text-base font-bold font-mono text-ink">{{ computedTotal.toFixed(2) }}</span>
            </div>
            <div class="h-8 w-px bg-line"></div>
            <div>
              <span class="text-xs text-muted block">{{ $t('expenses.total') }}</span>
              <span class="text-xl font-extrabold font-mono text-crimson">{{ computedTotal.toFixed(2) }}</span>
            </div>
          </div>
        </div>

        <!-- Footer Actions -->
        <div class="flex items-center justify-end gap-3 pt-2 border-t border-line">
          <button
            type="button"
            @click="emitClose"
            class="px-4 py-2.5 rounded-xl border border-line bg-canvas text-ink text-sm font-medium hover:bg-card cursor-pointer"
          >
            {{ $t('common.cancel') }}
          </button>
          <button
            v-if="!isLocked"
            type="submit"
            :disabled="saving"
            class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-crimson text-white text-sm font-semibold hover:opacity-90 disabled:opacity-50 cursor-pointer shadow-sm"
          >
            <Loader2 v-if="saving" class="w-4 h-4 animate-spin" />
            <span>{{ isEdit ? $t('expenses.saveChanges') : $t('expenses.saveAsDraft') }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertTriangle,
  Download,
  FileText,
  Image as ImageIcon,
  Loader2,
  Paperclip,
  Plus,
  ReceiptText,
  RotateCcw,
  Trash2,
  Upload,
  X,
} from 'lucide-vue-next';
import SearchableSelect from '../../components/common/SearchableSelect.vue';
import { createExpenseCategoryFetcher, createContactFetcher } from '../../utils/selectFetchers';
import api from '../../services/api';
import { showError, showSuccess } from '../../services/alert';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  expense: {
    type: Object,
    default: null,
  },
  categories: {
    type: Array,
    default: () => [],
  },
  contacts: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'saved', 'preview-attachment']);

const { t } = useI18n();

let uidCounter = 1;
const nextUid = () => `exp-item-${uidCounter++}`;

const saving = ref(false);
const errors = ref({});
const generalError = ref('');

const form = reactive({
  expense_number: '',
  expense_category_id: '',
  contact_id: '',
  expense_date: '',
  notes: '',
  items: [],
});

const isEdit = computed(() => Boolean(props.expense?.id));
const isLocked = computed(() => isEdit.value && props.expense?.status !== 'draft');

const fetchExpenseCategoryOptions = createExpenseCategoryFetcher();
const fetchContactOptions = createContactFetcher({ activeOnly: false });

const selectedCategoryOption = computed(() => {
  const cat = props.expense?.category || props.categories.find((c) => c.id === form.expense_category_id);
  return cat ? { value: cat.id, label: cat.name } : null;
});

const selectedContactOption = computed(() => {
  const c = props.expense?.contact || props.contacts.find((item) => item.id === form.contact_id);
  return c ? { value: c.id, label: c.name } : null;
});

const computedTotal = computed(() =>
  form.items.reduce((sum, item) => {
    const val = parseFloat(item.amount);
    return sum + (Number.isFinite(val) && val > 0 ? val : 0);
  }, 0)
);

function todayIsoDate() {
  const now = new Date();
  const y = now.getFullYear();
  const m = String(now.getMonth() + 1).padStart(2, '0');
  const d = String(now.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function createEmptyItem() {
  return {
    _uid: nextUid(),
    id: null,
    name: '',
    description: '',
    amount: '',
    existingAttachments: [],
    removeAttachmentIds: [],
    newFiles: [],
  };
}

function populateForm() {
  errors.value = {};
  generalError.value = '';

  if (props.expense) {
    form.expense_number = props.expense.expense_number || '';
    form.expense_category_id = props.expense.expense_category_id ?? '';
    form.contact_id = props.expense.contact_id ?? '';
    form.expense_date = (props.expense.expense_date || '').slice(0, 10) || todayIsoDate();
    form.notes = props.expense.notes || '';
    form.items = (props.expense.items || []).map((item) => ({
      _uid: nextUid(),
      id: item.id,
      name: item.name || '',
      description: item.description || '',
      amount: item.amount != null ? String(item.amount) : '',
      existingAttachments: [...(item.attachments || [])],
      removeAttachmentIds: [],
      newFiles: [],
    }));
    if (form.items.length === 0) {
      form.items = [createEmptyItem()];
    }
  } else {
    form.expense_number = '';
    form.expense_category_id = '';
    form.contact_id = '';
    form.expense_date = todayIsoDate();
    form.notes = '';
    form.items = [createEmptyItem()];
  }
}

watch(
  () => [props.show, props.expense],
  ([visible]) => {
    if (visible) {
      populateForm();
    }
  },
  { immediate: true }
);

function addItem() {
  form.items.push(createEmptyItem());
}

function removeItem(index) {
  if (form.items.length <= 1) return;
  form.items.splice(index, 1);
}

function onFilesSelected(index, event) {
  const files = Array.from(event.target?.files || []);
  if (files.length > 0) {
    form.items[index].newFiles.push(...files);
  }
  if (event.target) {
    event.target.value = '';
  }
}

function removeNewFile(itemIndex, fileIndex) {
  form.items[itemIndex].newFiles.splice(fileIndex, 1);
}

function isAttachmentMarkedRemoved(item, attachmentId) {
  return item.removeAttachmentIds.includes(attachmentId);
}

function toggleRemoveExistingAttachment(item, attachmentId) {
  const idx = item.removeAttachmentIds.indexOf(attachmentId);
  if (idx === -1) {
    item.removeAttachmentIds.push(attachmentId);
  } else {
    item.removeAttachmentIds.splice(idx, 1);
  }
}

function isPdf(mimeType) {
  return String(mimeType || '').toLowerCase().includes('pdf');
}

function formatFileSize(bytes) {
  if (!bytes) return '0 B';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

function previewExistingAttachment(itemId, attachment) {
  emit('preview-attachment', {
    expenseId: props.expense.id,
    itemId,
    attachment,
  });
}

async function downloadExistingAttachment(itemId, attachment) {
  try {
    const res = await api.get(
      `/expenses/${props.expense.id}/items/${itemId}/attachments/${attachment.id}/download`,
      { responseType: 'blob' }
    );
    const url = URL.createObjectURL(res.data);
    const link = document.createElement('a');
    link.href = url;
    link.download = attachment.original_name || 'attachment';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  }
}

function fieldError(key) {
  const list = errors.value?.[key];
  if (Array.isArray(list) && list.length > 0) return list[0];
  if (errors.value) {
    const prefixMatch = Object.keys(errors.value).find((k) => k.startsWith(`${key}.`));
    if (prefixMatch && Array.isArray(errors.value[prefixMatch])) {
      return errors.value[prefixMatch][0];
    }
  }
  return '';
}

function emitClose() {
  if (!saving.value) {
    emit('close');
  }
}

async function submitForm() {
  if (isLocked.value) return;

  errors.value = {};
  generalError.value = '';

  if (!form.expense_category_id) {
    errors.value = { expense_category_id: [t('expenses.categoryRequired')] };
    return;
  }

  saving.value = true;
  try {
    const payload = new FormData();
    payload.append('expense_category_id', String(form.expense_category_id));
    payload.append('contact_id', form.contact_id ? String(form.contact_id) : '');
    payload.append('expense_date', form.expense_date);
    payload.append('notes', form.notes || '');

    form.items.forEach((item, idx) => {
      if (item.id != null) {
        payload.append(`items[${idx}][id]`, String(item.id));
      }
      payload.append(`items[${idx}][name]`, item.name);
      payload.append(`items[${idx}][description]`, item.description || '');
      payload.append(`items[${idx}][amount]`, String(item.amount));

      item.removeAttachmentIds.forEach((attId) => {
        payload.append(`items[${idx}][remove_attachment_ids][]`, String(attId));
      });

      item.newFiles.forEach((file) => {
        payload.append(`items[${idx}][attachments][]`, file);
      });
    });

    if (isEdit.value) {
      payload.append('_method', 'PUT');
      const res = await api.post(`/expenses/${props.expense.id}`, payload);
      showSuccess({ text: t('expenses.updated') });
      emit('saved', res.data?.data);
    } else {
      const res = await api.post('/expenses', payload);
      showSuccess({ text: t('expenses.created') });
      emit('saved', res.data?.data);
    }
  } catch (err) {
    if (err?.response?.status === 422 && err.response?.data?.errors) {
      errors.value = err.response.data.errors;
      generalError.value = err.response?.data?.message || '';
    } else {
      showError({ text: err?.response?.data?.message || t('common.error') });
    }
  } finally {
    saving.value = false;
  }
}
</script>
