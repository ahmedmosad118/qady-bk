import { computed, ref } from 'vue';

/**
 * Reusable row-selection state for paginated tables and grouped lists.
 *
 * @param {import('vue').Ref<Array<object>>} itemsRef
 * @param {object} [options]
 * @param {(item: object) => boolean} [options.canSelect]
 */
export function useBulkSelection(itemsRef, options = {}) {
  const canSelect = options.canSelect ?? (() => true);
  const selectedIds = ref([]);

  const selectableItems = computed(() =>
    (itemsRef.value || []).filter((item) => canSelect(item))
  );

  const allPageSelected = computed(
    () =>
      selectableItems.value.length > 0 &&
      selectableItems.value.every((item) => selectedIds.value.includes(item.id))
  );

  const somePageSelected = computed(() =>
    selectableItems.value.some((item) => selectedIds.value.includes(item.id))
  );

  const isSelected = (item) => selectedIds.value.includes(item.id);

  const toggleAllOnPage = () => {
    const pageIds = selectableItems.value.map((item) => item.id);
    if (allPageSelected.value) {
      selectedIds.value = selectedIds.value.filter((id) => !pageIds.includes(id));
      return;
    }

    selectedIds.value = Array.from(new Set([...selectedIds.value, ...pageIds]));
  };

  const toggleRow = (item) => {
    if (!canSelect(item)) return;

    if (selectedIds.value.includes(item.id)) {
      selectedIds.value = selectedIds.value.filter((id) => id !== item.id);
      return;
    }

    selectedIds.value = [...selectedIds.value, item.id];
  };

  const clearSelection = () => {
    selectedIds.value = [];
  };

  const removeId = (id) => {
    selectedIds.value = selectedIds.value.filter((entry) => entry !== id);
  };

  return {
    selectedIds,
    selectableItems,
    allPageSelected,
    somePageSelected,
    isSelected,
    toggleAllOnPage,
    toggleRow,
    clearSelection,
    removeId,
  };
}

/**
 * Delete multiple records sequentially via an existing single-record DELETE endpoint,
 * tracking successes and the first domain/validation error message.
 *
 * @param {Array<number|string>} ids
 * @param {(id: number|string) => Promise<unknown>} deleteRequest
 */
export async function deleteRecordsSequentially(ids, deleteRequest) {
  let deletedCount = 0;
  let firstErrorMessage = '';

  for (const id of ids) {
    try {
      await deleteRequest(id);
      deletedCount += 1;
    } catch (err) {
      if (!firstErrorMessage) {
        firstErrorMessage = err?.response?.data?.message || '';
      }
    }
  }

  return {
    deletedCount,
    failedCount: ids.length - deletedCount,
    firstErrorMessage,
  };
}
