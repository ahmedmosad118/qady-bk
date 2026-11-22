import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { ref } from 'vue';
import {
  deleteRecordsSequentially,
  useBulkSelection,
} from '../../resources/js/composables/useBulkSelection.js';

describe('useBulkSelection', () => {
  it('selects only eligible rows and toggles page selection cleanly', () => {
    const items = ref([
      { id: 1, status: 'draft' },
      { id: 2, status: 'posted' },
      { id: 3, status: 'draft' },
    ]);

    const {
      selectedIds,
      selectableItems,
      allPageSelected,
      somePageSelected,
      isSelected,
      toggleAllOnPage,
      toggleRow,
      clearSelection,
      removeId,
    } = useBulkSelection(items, {
      canSelect: (item) => item.status === 'draft',
    });

    assert.deepEqual(
      selectableItems.value.map((i) => i.id),
      [1, 3]
    );
    assert.equal(allPageSelected.value, false);
    assert.equal(somePageSelected.value, false);

    // Non-selectable row is ignored
    toggleRow(items.value[1]);
    assert.deepEqual(selectedIds.value, []);

    // Select one row
    toggleRow(items.value[0]);
    assert.deepEqual(selectedIds.value, [1]);
    assert.equal(isSelected(items.value[0]), true);
    assert.equal(somePageSelected.value, true);
    assert.equal(allPageSelected.value, false);

    // Toggle all on page completes selection of eligible rows only
    toggleAllOnPage();
    assert.deepEqual(selectedIds.value, [1, 3]);
    assert.equal(allPageSelected.value, true);

    // Toggle all again clears current page rows
    toggleAllOnPage();
    assert.deepEqual(selectedIds.value, []);
    assert.equal(allPageSelected.value, false);

    // removeId & clearSelection
    toggleAllOnPage();
    removeId(1);
    assert.deepEqual(selectedIds.value, [3]);
    clearSelection();
    assert.deepEqual(selectedIds.value, []);
  });
});

describe('deleteRecordsSequentially', () => {
  it('counts successes and preserves the first error message on partial failures', async () => {
    const called = [];
    const result = await deleteRecordsSequentially([10, 20, 30], async (id) => {
      called.push(id);
      if (id === 20) {
        const err = new Error('Linked record');
        err.response = { data: { message: 'لا يمكن الحذف لارتباطه بفواتير' } };
        throw err;
      }
    });

    assert.deepEqual(called, [10, 20, 30]);
    assert.deepEqual(result, {
      deletedCount: 2,
      failedCount: 1,
      firstErrorMessage: 'لا يمكن الحذف لارتباطه بفواتير',
    });
  });
});
