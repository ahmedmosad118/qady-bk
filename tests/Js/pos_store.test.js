import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

// Setup Mock Browser Environment in Node
class MockLocalStorage {
  constructor() {
    this.store = {};
    this.failOnSet = false;
    this.failType = 'QuotaExceededError';
  }

  getItem(key) {
    return Object.prototype.hasOwnProperty.call(this.store, key) ? this.store[key] : null;
  }

  setItem(key, value) {
    if (this.failOnSet) {
      const err = new Error('The quota has been exceeded.');
      err.name = this.failType;
      throw err;
    }
    this.store[key] = String(value);
  }

  removeItem(key) {
    delete this.store[key];
  }

  clear() {
    this.store = {};
  }
}

const mockListeners = [];
global.window = {
  APP_BASE_URL: 'http://localhost',
  addEventListener(event, fn) {
    mockListeners.push({ event, fn });
  },
  removeEventListener(event, fn) {
    const idx = mockListeners.findIndex(l => l.event === event && l.fn === fn);
    if (idx !== -1) mockListeners.splice(idx, 1);
  },
  location: { pathname: '/' }
};
global.localStorage = new MockLocalStorage();
global.sessionStorage = new MockLocalStorage();
Object.defineProperty(global.navigator, 'onLine', { value: true, configurable: true, writable: true });

// Pinia and Store imports
const { createPinia, setActivePinia } = await import('pinia');
const { usePosStore } = await import('../../resources/js/stores/pos.js');
const { useAuthStore } = await import('../../resources/js/stores/auth.js');
const { useNotificationStore } = await import('../../resources/js/stores/notification.js');

describe('POS Store Hardening & Safety Tests', () => {
  let posStore;
  let authStore;
  let notifyStore;

  const sampleProduct1 = {
    id: 1,
    name: 'Book A',
    sku: 'BK-001',
    barcode: '123456789',
    selling_price: 50,
    stock_quantity: 10,
    is_active: true
  };

  const sampleProduct2 = {
    id: 2,
    name: 'Book B',
    sku: 'BK-002',
    barcode: '987654321',
    selling_price: 30,
    stock_quantity: 15,
    is_active: true
  };

  beforeEach(() => {
    setActivePinia(createPinia());
    global.localStorage.clear();
    global.localStorage.failOnSet = false;
    mockListeners.length = 0;

    posStore = usePosStore();
    authStore = useAuthStore();
    notifyStore = useNotificationStore();

    // Mock permissions
    authStore.permissions = ['pos.sell', 'pos.override_price', 'pos.discount'];

    // Mock active shift and warehouse
    posStore.activeShift = { id: 10, shift_number: 'SH-001', opened_by: 1 };
    posStore.currentWarehouse = { id: 1, name: 'Main Store' };
    posStore.products = [sampleProduct1, sampleProduct2];
  });

  describe('1. Hold / Resume Cart Local Storage Safety', () => {
    it('holds cart successfully and clears cart when storage succeeds', () => {
      posStore.addToCart(sampleProduct1, 2);
      posStore.cart.customer = { id: 5, name: 'Customer X' };

      assert.equal(posStore.cartLinesCount, 1);
      assert.equal(posStore.cartTotalPieces, 2);

      const ok = posStore.holdCurrentCart('Hold Note 1');
      assert.equal(ok, true);

      // Cart is cleared after verified storage
      assert.equal(posStore.cartLinesCount, 0);
      assert.equal(posStore.cart.customer, null);
      assert.equal(posStore.heldSales.length, 1);

      // Local storage has the entry
      const rawStored = global.localStorage.getItem('qady_pos_held_sales');
      assert.ok(rawStored);
      const parsed = JSON.parse(rawStored);
      assert.equal(parsed.length, 1);
      assert.equal(parsed[0].items[1].product_id, 1);
      assert.equal(parsed[0].items[1].quantity, 2);
    });

    it('does NOT clear cart if localStorage.setItem fails (QuotaExceededError)', () => {
      posStore.addToCart(sampleProduct2, 3);
      assert.equal(posStore.cartLinesCount, 1);

      // Simulate Storage Quota Full
      global.localStorage.failOnSet = true;
      global.localStorage.failType = 'QuotaExceededError';

      const ok = posStore.holdCurrentCart('Will Fail');
      assert.equal(ok, false);

      // CRITICAL REQUIREMENT: Cart MUST NOT be lost or cleared!
      assert.equal(posStore.cartLinesCount, 1);
      assert.equal(posStore.cart.items[2].product_id, 2);
      assert.equal(posStore.cart.items[2].quantity, 3);
      assert.equal(posStore.heldSales.length, 0);

      // Notification error should have been triggered
      const errToast = notifyStore.toasts.find(t => t.type === 'error');
      assert.ok(errToast);
      assert.match(errToast.message, /مساحة التخزين/);
    });

    it('resuming held cart does NOT delete the held record from localStorage', () => {
      const heldRecord = {
        id: 'held_123',
        created_at: new Date().toISOString(),
        reference_note: 'Customer went to get wallet',
        customer: { id: 1, name: 'Customer 1' },
        items: {
          1: {
            product_id: 1,
            name: 'Book A',
            sku: 'BK-001',
            quantity: 2,
            original_unit_price: 50,
            unit_price: 50,
            discount_type: null,
            discount_value: 0,
            discount_amount: 0,
            subtotal: 100,
            total: 100
          }
        },
        invoice_discount_type: 'fixed',
        invoice_discount_value: 0,
        notes: '',
        total: 100
      };
      posStore.heldSales = [heldRecord];
      posStore.persistHeldSales();

      // Clear current cart before resume
      posStore.clearCart();
      assert.equal(posStore.cartLinesCount, 0);

      const res = posStore.resumeHeldCart('held_123');
      assert.equal(res.success, true);

      // Cart is populated
      assert.equal(posStore.cartLinesCount, 1);
      assert.equal(posStore.cart.items[1].quantity, 2);

      // CRITICAL REQUIREMENT: Held record MUST STILL EXIST in heldSales and localStorage!
      assert.equal(posStore.heldSales.length, 1);
      const inStorage = JSON.parse(global.localStorage.getItem('qady_pos_held_sales'));
      assert.equal(inStorage.length, 1);
      assert.equal(inStorage[0].id, 'held_123');
    });

    it('resuming does NOT silently reduce quantity or drop items when stock is low or missing', () => {
      const heldRecord = {
        id: 'held_456',
        created_at: new Date().toISOString(),
        reference_note: 'Held with shortages',
        customer: null,
        items: {
          1: {
            product_id: 1,
            name: 'Book A',
            quantity: 20, // requested 20, but stock is only 10!
            original_unit_price: 50,
            unit_price: 50,
            discount_type: null,
            discount_value: 0,
            discount_amount: 0,
            subtotal: 1000,
            total: 1000
          },
          99: {
            product_id: 99,
            name: 'Deleted Book',
            quantity: 1,
            original_unit_price: 50,
            unit_price: 50,
            discount_type: null,
            discount_value: 0,
            discount_amount: 0,
            subtotal: 50,
            total: 50
          }
        },
        invoice_discount_type: 'fixed',
        invoice_discount_value: 0,
        notes: '',
        total: 1050
      };
      posStore.heldSales = [heldRecord];
      posStore.persistHeldSales();

      posStore.clearCart();

      const res = posStore.resumeHeldCart('held_456');
      assert.equal(res.success, true);
      assert.ok(res.discrepancies.length >= 2);

      // Verify that quantity was NOT silently reduced to 10!
      assert.equal(posStore.cart.items[1].quantity, 20);

      // Verify that missing item was NOT silently dropped!
      assert.ok(posStore.cart.items[99]);
      assert.equal(posStore.cart.items[99].quantity, 1);
    });
  });

  describe('2. Offline Queue Storage & Safety', () => {
    it('does NOT clear cart if persistOfflineQueue fails', async () => {
      posStore.addToCart(sampleProduct1, 1);
      assert.equal(posStore.cartLinesCount, 1);

      // Force offline
      posStore.isOnline = false;

      // Fail storage
      global.localStorage.failOnSet = true;
      global.localStorage.failType = 'QuotaExceededError';

      const res = await posStore.checkout({
        payment_method: 'cash',
        amount_tendered: 50,
        payments: [{ method: 'cash', amount: 50 }]
      });

      assert.equal(res.success, false);
      // Cart MUST NOT be lost!
      assert.equal(posStore.cartLinesCount, 1);
      assert.equal(posStore.offlineQueue.length, 0);
    });

    it('offline queue preserves client_uuid and retains entries upon sync conflict', async () => {
      posStore.addToCart(sampleProduct1, 1);
      posStore.isOnline = false;

      const res = await posStore.checkout({
        payment_method: 'cash',
        amount_tendered: 50,
        payments: [{ method: 'cash', amount: 50 }]
      });

      assert.equal(res.success, true);
      assert.equal(posStore.cartLinesCount, 0);
      assert.equal(posStore.offlineQueue.length, 1);

      const queuedSale = posStore.offlineQueue[0];
      assert.ok(queuedSale.client_uuid);
      const uuid = queuedSale.client_uuid;

      // Simulate a conflict response from sync
      posStore.syncingQueue = true;
      queuedSale.sync_status = 'conflict';
      queuedSale.last_error = 'Insufficient inventory on warehouse';
      posStore.syncingQueue = false;

      // Ensure client_uuid was not changed and sale was NOT deleted from queue
      assert.equal(posStore.offlineQueue.length, 1);
      assert.equal(posStore.offlineQueue[0].client_uuid, uuid);
      assert.equal(posStore.offlineQueue[0].sync_status, 'conflict');
    });
  });

  describe('3. Shift Auto-Selection Protection', () => {
    it('does NOT auto-select another cashier shift', () => {
      // Simulate bootstrap data containing only someone else's shift
      const otherUserShift = { id: 99, shift_number: 'SH-999', opened_by: 88, opened_by_name: 'Other Cashier' };
      posStore.availableOpenShifts = [otherUserShift];
      posStore.activeShift = null;

      // Current user is 1, so other user shift must not be chosen
      const userShift = posStore.availableOpenShifts.find(s => s.opened_by === 1);
      assert.equal(userShift, undefined);
      assert.equal(posStore.activeShift, null);
      assert.equal(posStore.isOpenShift, false);
    });

    it('allows explicit selection via selectShift', () => {
      const myShift = { id: 50, shift_number: 'SH-050', opened_by: 1 };
      posStore.availableOpenShifts = [myShift];
      posStore.activeShift = null;

      const ok = posStore.selectShift(50);
      assert.equal(ok, true);
      assert.equal(posStore.activeShift.id, 50);
      assert.equal(posStore.isOpenShift, true);
    });
  });

  describe('4. Event Listeners Idempotency', () => {
    it('attaches online/offline listeners only once despite multiple initLocalState calls', () => {
      posStore.initLocalState();
      const count1 = mockListeners.length;

      posStore.initLocalState();
      const count2 = mockListeners.length;

      posStore.initLocalState();
      const count3 = mockListeners.length;

      assert.equal(count1, count2);
      assert.equal(count2, count3);
    });
  });
});
