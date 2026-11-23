<template>
  <div class="mx-auto max-w-4xl space-y-4">
    <!-- Header -->
    <PageHeader :title="$t('warehouses.editWarehouse')" :subtitle="$t('warehouses.subtitle')">
      <template #actions>
        <span
          v-if="warehouse?.is_default"
          class="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-bold text-amber-600 dark:text-amber-400"
        >
          ★ {{ $t('warehouses.default') }}
        </span>
        <span
          class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-bold"
          :class="warehouse?.is_active
            ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
            : 'border-zinc-500/30 bg-zinc-500/10 text-zinc-500'"
        >
          {{ warehouse?.is_active ? $t('common.active') : $t('common.inactive') }}
        </span>
      </template>
    </PageHeader>

    <!-- Alert banner -->
    <div
      v-if="feedback.message"
      class="flex items-center gap-3 rounded-xl border p-4 text-sm font-semibold"
      :class="feedback.type === 'success'
        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
        : 'border-[var(--crimson)]/30 bg-[var(--crimson-soft)] text-[var(--crimson)]'"
    >
      <CheckCircle2 v-if="feedback.type === 'success'" class="h-5 w-5 shrink-0" />
      <AlertCircle v-else class="h-5 w-5 shrink-0" />
      <span class="flex-1">{{ feedback.message }}</span>
      <button type="button" class="text-xs underline" @click="feedback.message = ''">
        {{ $t('common.close') }}
      </button>
    </div>

    <!-- Loading state -->
    <div
      v-if="loading && !warehouse"
      class="flex h-64 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--card)]"
    >
      <div class="flex items-center gap-3 text-sm text-[var(--muted)]">
        <Loader2 class="h-5 w-5 animate-spin text-[var(--crimson)]" />
        {{ $t('common.loading') }}
      </div>
    </div>

    <!-- Form card -->
    <form
      v-else-if="warehouse"
      class="space-y-6 rounded-2xl border border-[var(--border)] bg-[var(--card)] p-6 sm:p-8"
      @submit.prevent="saveWarehouse"
    >
      <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
        <!-- Warehouse Code -->
        <div>
          <label class="block text-sm font-bold text-[var(--text)]">
            {{ $t('warehouses.fields.code') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="form.code"
            type="text"
            required
            class="mt-2 h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-4 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            :placeholder="$t('warehouses.fields.codePlaceholder')"
          />
          <p v-if="errors.code" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.code[0] }}</p>
        </div>

        <!-- Warehouse Name -->
        <div>
          <label class="block text-sm font-bold text-[var(--text)]">
            {{ $t('warehouses.fields.name') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <input
            v-model="form.name"
            type="text"
            required
            class="mt-2 h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-4 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            :placeholder="$t('warehouses.fields.namePlaceholder')"
          />
          <p v-if="errors.name" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.name[0] }}</p>
        </div>

        <!-- Phone -->
        <div>
          <label class="block text-sm font-bold text-[var(--text)]">
            {{ $t('warehouses.fields.phone') }}
          </label>
          <input
            v-model="form.phone"
            type="text"
            class="mt-2 h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-4 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            :placeholder="$t('warehouses.fields.phonePlaceholder')"
          />
          <p v-if="errors.phone" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.phone[0] }}</p>
        </div>

        <!-- Manager Name -->
        <div>
          <label class="block text-sm font-bold text-[var(--text)]">
            {{ $t('warehouses.fields.managerName') }}
          </label>
          <input
            v-model="form.manager_name"
            type="text"
            class="mt-2 h-11 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] px-4 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            :placeholder="$t('warehouses.fields.managerNamePlaceholder')"
          />
          <p v-if="errors.manager_name" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.manager_name[0] }}</p>
        </div>

        <!-- Address (Required) -->
        <div class="sm:col-span-2">
          <label class="block text-sm font-bold text-[var(--text)]">
            {{ $t('warehouses.fields.address') }} <span class="text-[var(--crimson)]">*</span>
          </label>
          <textarea
            v-model="form.address"
            rows="3"
            required
            class="mt-2 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-4 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            :placeholder="$t('warehouses.fields.addressPlaceholder')"
          />
          <p v-if="errors.address" class="mt-1 text-xs text-[var(--crimson)]">{{ errors.address[0] }}</p>
        </div>

        <!-- Description (Optional) -->
        <div class="sm:col-span-2">
          <label class="block text-sm font-bold text-[var(--text)]">
            {{ $t('warehouses.fields.description') }}
          </label>
          <textarea
            v-model="form.description"
            rows="2"
            class="mt-2 w-full rounded-xl border border-[var(--border)] bg-[var(--bg)] p-4 text-sm text-[var(--text)] outline-none transition focus:border-[var(--crimson)] focus:ring-4 focus:ring-[var(--crimson-soft)]"
            :placeholder="$t('warehouses.fields.descriptionPlaceholder')"
          />
        </div>
      </div>

      <!-- Action buttons -->
      <div class="flex items-center justify-end gap-3 border-t border-[var(--border)] pt-5">
        <button
          type="submit"
          :disabled="saving"
          class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-[var(--crimson)] px-6 text-sm font-bold text-white shadow-sm transition hover:bg-[var(--crimson-hover)] disabled:opacity-60"
        >
          <Loader2 v-if="saving" class="h-4 w-4 animate-spin" />
          <Save v-else class="h-4 w-4" />
          <span>{{ $t('common.save') }}</span>
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  AlertCircle,
  CheckCircle2,
  Loader2,
  Save,
} from 'lucide-vue-next';
import api from '../../services/api';
import PageHeader from '../../components/ui/PageHeader.vue';

const { t } = useI18n();
const route = useRoute();
const loading = ref(true);
const saving = ref(false);
const warehouse = ref(null);
const feedback = reactive({
  type: 'success',
  message: '',
});
const errors = ref({});

const form = reactive({
  code: '',
  name: '',
  address: '',
  phone: '',
  manager_name: '',
  description: '',
});

async function loadWarehouse() {
  loading.value = true;
  feedback.message = '';
  errors.value = {};

  try {
    const warehouseId = route.params.id;
    const endpoint = warehouseId ? `/warehouses/${warehouseId}` : '/warehouses/default';
    const response = await api.get(endpoint);

    if (response.data && response.data.data) {
      warehouse.value = response.data.data;
      form.code = warehouse.value.code || '';
      form.name = warehouse.value.name || '';
      form.address = warehouse.value.address || '';
      form.phone = warehouse.value.phone || '';
      form.manager_name = warehouse.value.manager_name || '';
      form.description = warehouse.value.description || '';
    }
  } catch (err) {
    feedback.type = 'error';
    feedback.message = err?.response?.data?.message || t('warehouses.loadError');
  } finally {
    loading.value = false;
  }
}

async function saveWarehouse() {
  if (!warehouse.value?.id) return;

  saving.value = true;
  feedback.message = '';
  errors.value = {};

  try {
    const response = await api.put(`/warehouses/${warehouse.value.id}`, form);
    if (response.data?.data) {
      warehouse.value = response.data.data;
    }
    feedback.type = 'success';
    feedback.message = response.data?.message || t('warehouses.updateSuccess');
  } catch (err) {
    feedback.type = 'error';
    if (err?.response?.status === 422 && err.response.data?.errors) {
      errors.value = err.response.data.errors;
      feedback.message = t('warehouses.validationError');
    } else {
      feedback.message = err?.response?.data?.message || t('warehouses.saveError');
    }
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadWarehouse();
});
</script>
