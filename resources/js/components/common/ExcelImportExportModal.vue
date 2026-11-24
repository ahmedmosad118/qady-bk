<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-[999] flex items-center justify-center overflow-y-auto bg-black/60 p-3 backdrop-blur-sm transition-opacity sm:p-6"
      :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
      role="dialog"
      aria-modal="true"
    >
      <div
        class="relative flex max-h-[92vh] w-full max-w-2xl flex-col rounded-2xl border border-[var(--border)] bg-[var(--card)] p-5 shadow-2xl dark:shadow-black/40 sm:p-7"
      >
        <!-- Header -->
        <div class="flex shrink-0 items-center justify-between gap-3 border-b border-[var(--border)] pb-4">
          <div class="flex items-center gap-3 min-w-0">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--crimson-soft)] text-[var(--crimson)]">
              <FileSpreadsheet class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
              <h3 class="truncate text-base font-extrabold text-[var(--text)]">
                {{ title || $t('excel.modalTitle') }}
              </h3>
              <p class="mt-0.5 truncate text-xs text-[var(--muted)]">
                {{ $t('excel.modalSubtitle') }}
              </p>
            </div>
          </div>
          <button
            type="button"
            class="rounded-lg p-2 text-[var(--muted)] transition-colors hover:bg-[var(--bg)] hover:text-[var(--text)]"
            :aria-label="$t('common.close')"
            :title="$t('common.close')"
            @click="handleClose"
          >
            <X class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <!-- Body -->
        <div class="custom-scrollbar flex-1 space-y-5 overflow-y-auto px-0.5 py-5">
          <!-- Action Cards (Template & Export) -->
          <div
            v-if="canImport || canExport"
            class="grid gap-3"
            :class="canImport && canExport ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1'"
          >
            <button
              v-if="canImport"
              type="button"
              :disabled="downloadingTemplate || importing"
              class="group flex flex-col items-center rounded-2xl border-2 border-[var(--border)] bg-[var(--bg)]/60 p-5 text-center transition-all hover:border-[var(--crimson)] hover:bg-[var(--card)] hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60"
              @click="handleDownloadTemplate"
            >
              <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)] transition-transform group-hover:scale-105">
                <span v-if="downloadingTemplate" class="h-5 w-5 animate-spin rounded-full border-2 border-[var(--crimson)]/30 border-t-[var(--crimson)]"></span>
                <Download v-else class="h-5 w-5" aria-hidden="true" />
              </span>
              <span class="text-sm font-bold text-[var(--text)]">
                {{ $t('excel.downloadTemplate') }}
              </span>
              <span class="mt-1 text-xs text-[var(--muted)]">
                {{ $t('excel.downloadTemplateDesc') }}
              </span>
            </button>

            <button
              v-if="canExport"
              type="button"
              :disabled="exportingData || importing"
              class="group flex flex-col items-center rounded-2xl border-2 border-[var(--border)] bg-[var(--bg)]/60 p-5 text-center transition-all hover:border-[var(--success)] hover:bg-[var(--card)] hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60"
              @click="handleExportData"
            >
              <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-full border border-[var(--success-border)] bg-[var(--success-soft)] text-[var(--success)] transition-transform group-hover:scale-105">
                <span v-if="exportingData" class="h-5 w-5 animate-spin rounded-full border-2 border-[var(--success)]/30 border-t-[var(--success)]"></span>
                <FileDown v-else class="h-5 w-5" aria-hidden="true" />
              </span>
              <span class="text-sm font-bold text-[var(--text)]">
                {{ $t('excel.exportData') }}
              </span>
              <span class="mt-1 text-xs text-[var(--muted)]">
                {{ hasActiveExportFilters ? $t('excel.exportFilteredDesc') : $t('excel.exportDataDesc') }}
              </span>
            </button>
          </div>

          <!-- Upload Section -->
          <template v-if="canImport">
            <div class="flex items-center gap-3">
              <div class="h-px flex-1 bg-[var(--border)]"></div>
              <span class="text-xs font-semibold text-[var(--muted)]">
                {{ $t('excel.orUploadFile') }}
              </span>
              <div class="h-px flex-1 bg-[var(--border)]"></div>
            </div>

            <!-- Row Errors -->
            <div
              v-if="errors.length"
              class="rounded-xl border border-[var(--crimson)]/30 bg-[var(--crimson-soft)] p-3.5 text-start"
              role="alert"
            >
              <div class="mb-2 flex items-center gap-2 text-xs font-bold text-[var(--crimson)]">
                <AlertCircle class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span>{{ $t('excel.errorsFoundTitle', { count: errors.length }) }}</span>
              </div>
              <ul class="max-h-40 list-disc space-y-1 overflow-y-auto ps-5 text-xs font-medium text-[var(--crimson)]">
                <li v-for="(errMsg, idx) in errors" :key="idx">
                  {{ errMsg }}
                </li>
              </ul>
            </div>

            <!-- Drag & Drop Zone -->
            <div
              v-if="!selectedFile"
              class="relative flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-5 py-8 text-center transition-colors"
              :class="isDragging
                ? 'border-[var(--crimson)] bg-[var(--crimson-soft)]/50'
                : 'border-[var(--border)] bg-[var(--bg)]/50 hover:border-[var(--crimson)]/60 hover:bg-[var(--bg)]'"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="handleDrop"
              @click="triggerFileBrowse"
            >
              <input
                ref="fileInputRef"
                type="file"
                class="hidden"
                accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                @change="handleFileChange"
              />
              <span class="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)]">
                <UploadCloud class="h-6 w-6" aria-hidden="true" />
              </span>
              <p class="text-sm font-bold text-[var(--text)]">
                {{ $t('excel.dragDropTitle') }}
              </p>
              <p class="mt-1 text-xs text-[var(--muted)]">
                {{ $t('excel.dragDropSubtitle') }}
              </p>
              <div class="mt-3 flex items-center gap-2">
                <span class="rounded-md border border-[var(--border)] bg-[var(--card)] px-2.5 py-0.5 font-mono text-[11px] font-semibold text-[var(--muted)]">
                  .xlsx
                </span>
                <span class="rounded-md border border-[var(--border)] bg-[var(--card)] px-2.5 py-0.5 font-mono text-[11px] font-semibold text-[var(--muted)]">
                  .xls
                </span>
              </div>
            </div>

            <!-- File Preview -->
            <div
              v-else
              class="flex items-center justify-between gap-3 rounded-2xl border-2 border-[var(--success-border)] bg-[var(--success-soft)]/50 p-4"
            >
              <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--success)] text-white">
                  <FileSpreadsheet class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 text-start">
                  <p class="truncate text-sm font-bold text-[var(--text)]" :title="selectedFile.name">
                    {{ selectedFile.name }}
                  </p>
                  <p class="text-xs text-[var(--muted)]">
                    {{ formatFileSize(selectedFile.size) }}
                  </p>
                </div>
              </div>
              <button
                type="button"
                :disabled="importing"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[var(--crimson-soft)] text-[var(--crimson)] transition-colors hover:bg-[var(--crimson)] hover:text-white disabled:opacity-50"
                :aria-label="$t('excel.removeFile')"
                :title="$t('excel.removeFile')"
                @click="clearFile"
              >
                <X class="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          </template>
        </div>

        <!-- Footer -->
        <div class="mt-1 flex shrink-0 items-center justify-end gap-3 border-t border-[var(--border)] pt-4">
          <button
            type="button"
            :disabled="importing"
            class="cursor-pointer rounded-xl border border-[var(--border)] px-5 py-2.5 text-sm font-semibold text-[var(--text)] transition-colors hover:bg-[var(--bg)] disabled:opacity-50"
            @click="handleClose"
          >
            {{ canImport ? $t('common.cancel') : $t('common.close') }}
          </button>

          <button
            v-if="canImport"
            type="button"
            :disabled="!selectedFile || importing"
            class="flex cursor-pointer items-center gap-2 rounded-xl bg-[var(--crimson)] px-6 py-2.5 text-sm font-bold text-white shadow-sm shadow-[var(--crimson)]/20 transition-colors hover:bg-[var(--crimson-hover)] disabled:cursor-not-allowed disabled:opacity-50"
            @click="handleImport"
          >
            <span v-if="importing" class="h-4 w-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
            <Upload v-else class="h-4 w-4" aria-hidden="true" />
            <span>{{ importing ? $t('excel.importing') : $t('excel.importBtn') }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  Download,
  FileDown,
  FileSpreadsheet,
  Upload,
  UploadCloud,
  X,
} from 'lucide-vue-next';
import excelService, { extractImportErrors, parseBlobErrorMessage } from '../../services/excelService';
import { useNotificationStore } from '../../stores/notification';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  type: {
    type: String,
    required: true,
  },
  title: {
    type: String,
    default: '',
  },
  exportParams: {
    type: Object,
    default: () => ({}),
  },
  canImport: {
    type: Boolean,
    default: true,
  },
  canExport: {
    type: Boolean,
    default: true,
  },
  templateFilename: {
    type: String,
    default: 'template.xlsx',
  },
  exportFilename: {
    type: String,
    default: 'export.xlsx',
  },
});

const emit = defineEmits(['close', 'imported']);

const { t } = useI18n();
const notificationStore = useNotificationStore();

const fileInputRef = ref(null);
const selectedFile = ref(null);
const isDragging = ref(false);
const downloadingTemplate = ref(false);
const exportingData = ref(false);
const importing = ref(false);
const errors = ref([]);

const ALLOWED_EXTENSIONS = ['.xlsx', '.xls'];

const hasActiveExportFilters = computed(() =>
  Object.values(props.exportParams || {}).some((value) => value !== undefined && value !== null && value !== '')
);

const resetState = () => {
  selectedFile.value = null;
  isDragging.value = false;
  errors.value = [];
  if (fileInputRef.value) {
    fileInputRef.value.value = '';
  }
};

watch(
  () => props.show,
  (isOpen) => {
    if (isOpen) {
      resetState();
    }
  }
);

const isAllowedFile = (file) => {
  const lower = (file?.name || '').toLowerCase();
  return ALLOWED_EXTENSIONS.some((ext) => lower.endsWith(ext));
};

const assignFile = (file) => {
  if (!file) return;

  if (!isAllowedFile(file)) {
    errors.value = [t('excel.invalidFileType')];
    return;
  }

  errors.value = [];
  selectedFile.value = file;
};

const triggerFileBrowse = () => {
  fileInputRef.value?.click();
};

const handleFileChange = (event) => {
  const file = event.target?.files?.[0];
  assignFile(file);
};

const handleDrop = (event) => {
  isDragging.value = false;
  const file = event.dataTransfer?.files?.[0];
  assignFile(file);
};

const clearFile = () => {
  resetState();
};

const handleClose = () => {
  if (importing.value) return;
  resetState();
  emit('close');
};

const formatFileSize = (bytes) => {
  if (!bytes) return '0 B';
  const k = 1024;
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(2))} ${units[i]}`;
};

const handleDownloadTemplate = async () => {
  downloadingTemplate.value = true;
  try {
    await excelService.downloadTemplate(props.type, props.templateFilename);
  } catch (err) {
    const message = await parseBlobErrorMessage(err, t('excel.downloadFailed'));
    notificationStore.error(message, t('common.errorTitle'));
  } finally {
    downloadingTemplate.value = false;
  }
};

const handleExportData = async () => {
  exportingData.value = true;
  try {
    await excelService.exportData(props.type, props.exportParams || {}, props.exportFilename);
  } catch (err) {
    const message = await parseBlobErrorMessage(err, t('excel.exportFailed'));
    notificationStore.error(message, t('common.errorTitle'));
  } finally {
    exportingData.value = false;
  }
};

const handleImport = async () => {
  if (!selectedFile.value || importing.value) return;

  importing.value = true;
  errors.value = [];

  try {
    const response = await excelService.importFile(props.type, selectedFile.value);
    notificationStore.success(
      response?.message || t('excel.importSuccess'),
      t('common.successTitle')
    );
    resetState();
    emit('imported', response?.data);
    emit('close');
  } catch (err) {
    errors.value = extractImportErrors(err, t('excel.importFailed'));
    notificationStore.error(t('excel.importFailed'), t('common.errorTitle'));
  } finally {
    importing.value = false;
  }
};
</script>
