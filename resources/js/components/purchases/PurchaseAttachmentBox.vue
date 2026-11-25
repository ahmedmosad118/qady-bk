<template>
  <div
    v-if="document?.id && (document.has_attachment || document.attachment_original_name)"
    class="rounded-2xl border border-[var(--border)] bg-[var(--bg)]/60 p-4"
  >
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-[var(--border)] bg-[var(--card)] text-[var(--crimson)]"
        >
          <Paperclip class="h-4 w-4" />
        </div>
        <div class="min-w-0">
          <p class="text-xs font-bold text-[var(--muted)]">
            {{ $t(`${ns}.attachmentTitle`) }}
          </p>
          <p class="text-xs font-extrabold text-[var(--text)] truncate">
            {{ document.attachment_original_name }}
          </p>
          <p v-if="formattedSize" class="text-[11px] font-mono text-[var(--muted)]">
            {{ formattedSize }}
          </p>
        </div>
      </div>

      <div class="flex items-center gap-2 shrink-0">
        <button
          v-if="isPreviewable"
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 text-xs font-bold text-[var(--text)] transition-colors hover:border-[var(--crimson)]/40 hover:text-[var(--crimson)] cursor-pointer"
          @click="openPreview"
        >
          <Eye class="h-3.5 w-3.5" />
          <span>{{ $t(`${ns}.viewAttachment`) }}</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--card)] px-3 py-1.5 text-xs font-bold text-[var(--text)] transition-colors hover:bg-[var(--bg)] cursor-pointer"
          :disabled="downloading"
          @click="downloadFile"
        >
          <Download class="h-3.5 w-3.5" />
          <span>{{ $t(`${ns}.downloadAttachment`) }}</span>
        </button>
      </div>
    </div>

    <!-- Authenticated Attachment Preview Modal -->
    <div
      v-if="preview.open"
      class="fixed inset-0 z-[70] flex items-center justify-center bg-black/75 backdrop-blur-xs p-4"
      @click.self="closePreview"
    >
      <div
        class="w-full max-w-4xl max-h-[90vh] rounded-2xl border border-[var(--border)] bg-[var(--card)] shadow-2xl flex flex-col overflow-hidden"
        :dir="$i18n.locale === 'ar' ? 'rtl' : 'ltr'"
      >
        <div class="flex items-center justify-between gap-3 border-b border-[var(--border)] px-5 py-3.5">
          <div class="min-w-0">
            <h4 class="text-sm font-extrabold text-[var(--text)] truncate">
              {{ document.attachment_original_name || $t(`${ns}.attachmentPreview`) }}
            </h4>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button
              type="button"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--border)] bg-[var(--bg)] px-3 py-1.5 text-xs font-bold text-[var(--text)] hover:text-[var(--crimson)] cursor-pointer"
              @click="downloadFile"
            >
              <Download class="h-3.5 w-3.5" />
              <span>{{ $t(`${ns}.downloadAttachment`) }}</span>
            </button>
            <button
              type="button"
              class="rounded-xl p-1.5 text-[var(--muted)] hover:bg-[var(--bg)] hover:text-[var(--text)] cursor-pointer"
              :title="$t('common.close')"
              @click="closePreview"
            >
              <X class="h-5 w-5" />
            </button>
          </div>
        </div>

        <div class="flex-1 overflow-auto bg-[var(--bg)] p-4 flex items-center justify-center min-h-[300px]">
          <div
            v-if="preview.loading"
            class="h-8 w-8 animate-spin rounded-full border-3 border-[var(--crimson)] border-t-transparent"
          ></div>
          <iframe
            v-else-if="preview.isPdf && preview.blobUrl"
            :src="preview.blobUrl"
            class="h-[72vh] w-full rounded-xl border border-[var(--border)] bg-white"
          />
          <img
            v-else-if="preview.blobUrl"
            :src="preview.blobUrl"
            :alt="document.attachment_original_name || $t(`${ns}.attachmentPreview`)"
            class="max-h-[72vh] max-w-full rounded-xl object-contain shadow-sm"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../../services/api';
import { showError } from '../../services/alert';
import { Paperclip, Eye, Download, X } from 'lucide-vue-next';

const props = defineProps({
  resourcePrefix: {
    type: String,
    required: true,
    validator: (v) => ['purchase-orders', 'purchase-invoices'].includes(v),
  },
  ns: {
    type: String,
    required: true,
    validator: (v) => ['purchaseOrders', 'purchaseInvoices'].includes(v),
  },
  document: { type: Object, default: null },
});

const { t } = useI18n();
const downloading = ref(false);

const preview = reactive({
  open: false,
  loading: false,
  blobUrl: '',
  isPdf: false,
});

const mimeLower = computed(() => String(props.document?.attachment_mime_type || '').toLowerCase());

const isPreviewable = computed(() => {
  return mimeLower.value.includes('pdf') || mimeLower.value.startsWith('image/');
});

const formattedSize = computed(() => {
  const bytes = Number(props.document?.attachment_size) || 0;
  if (bytes <= 0) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
});

async function openPreview() {
  if (!props.document?.id) return;
  closePreview();
  preview.open = true;
  preview.loading = true;
  preview.isPdf = mimeLower.value.includes('pdf');

  try {
    const res = await api.get(
      `/${props.resourcePrefix}/${props.document.id}/attachment/view`,
      { responseType: 'blob' }
    );
    const mime = props.document.attachment_mime_type || res.data?.type || 'application/octet-stream';
    const blob = new Blob([res.data], { type: mime });
    preview.blobUrl = URL.createObjectURL(blob);
  } catch (err) {
    closePreview();
    showError({ text: err?.response?.data?.message || t('common.error') });
  } finally {
    preview.loading = false;
  }
}

function closePreview() {
  if (preview.blobUrl) {
    URL.revokeObjectURL(preview.blobUrl);
  }
  preview.blobUrl = '';
  preview.open = false;
  preview.loading = false;
}

async function downloadFile() {
  if (!props.document?.id) return;
  downloading.value = true;
  try {
    const res = await api.get(
      `/${props.resourcePrefix}/${props.document.id}/attachment/download`,
      { responseType: 'blob' }
    );
    const blob = new Blob([res.data], {
      type: props.document.attachment_mime_type || res.data?.type || 'application/octet-stream',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = props.document.attachment_original_name || 'attachment';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  } catch (err) {
    showError({ text: err?.response?.data?.message || t('common.error') });
  } finally {
    downloading.value = false;
  }
}

onBeforeUnmount(() => {
  closePreview();
});
</script>
