import { onBeforeUnmount, reactive, ref, watch } from 'vue';
import { extractApiError } from '../utils/apiError';

/**
 * Reusable server-side paginated list composable with debounced search,
 * race-condition protection, and automatic timer cleanup.
 *
 * @param {Function} fetcher async ({ page, per_page, search, ... }) => { data, meta }
 * @param {Object} options
 */
export function usePaginatedList(fetcher, { perPage = 15, debounceMs = 300, fallbackError = '' } = {}) {
  const items = ref([]);
  const loading = ref(false);
  const error = ref('');
  const search = ref('');
  const meta = reactive({
    current_page: 1,
    last_page: 1,
    per_page: perPage,
    total: 0,
  });

  let requestSeq = 0;
  let searchTimer = null;

  async function load(page = meta.current_page, extraParams = {}) {
    const seq = ++requestSeq;
    loading.value = true;
    error.value = '';

    try {
      const res = await fetcher({
        page,
        per_page: meta.per_page,
        search: search.value?.trim() || undefined,
        ...extraParams,
      });

      if (seq !== requestSeq) return;

      const payload = res?.data ?? res;
      items.value = payload?.data ?? [];
      if (payload?.meta) {
        meta.current_page = payload.meta.current_page ?? page;
        meta.last_page = payload.meta.last_page ?? 1;
        meta.per_page = payload.meta.per_page ?? meta.per_page;
        meta.total = payload.meta.total ?? 0;
      }
    } catch (err) {
      if (seq === requestSeq) {
        error.value = extractApiError(err, fallbackError);
      }
    } finally {
      if (seq === requestSeq) {
        loading.value = false;
      }
    }
  }

  function changePage(page, extraParams = {}) {
    if (page < 1 || page > meta.last_page || page === meta.current_page) return;
    load(page, extraParams);
  }

  function scheduleReload(extraParams = {}) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      load(1, extraParams);
    }, debounceMs);
  }

  watch(search, () => {
    scheduleReload();
  });

  onBeforeUnmount(() => {
    clearTimeout(searchTimer);
  });

  return {
    items,
    loading,
    error,
    search,
    meta,
    load,
    changePage,
    scheduleReload,
  };
}
