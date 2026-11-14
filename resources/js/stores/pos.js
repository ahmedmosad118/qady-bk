import { defineStore } from 'pinia';
import api from '../services/api';
import { useAuthStore } from './auth';
import { useNotificationStore } from './notification';

const HELD_SALES_KEY = 'qady_pos_held_sales';
const OFFLINE_QUEUE_KEY = 'qady_pos_offline_queue';

export const usePosStore = defineStore('pos', {
  state: () => ({
    // Session & Shift
    activeShift: null,
    currentWarehouse: null,
    paymentMethods: [],
    
    // Catalog
    products: [],
    categories: [],
    selectedCategoryId: null,
    searchQuery: '',
    loadingProducts: false,
    
    // Customers
    customers: [],
    loadingCustomers: false,

    // Current Cart
    cart: {
      customer: null, // null = Walk-in customer
      items: {}, // key: productId => item details
      invoice_discount_type: 'fixed', // 'fixed' | 'percentage'
      invoice_discount_value: 0,
      notes: '',
      client_uuid: null,
    },

    // Held Sales (strictly Local Storage only!)
    heldSales: [],

    // Offline sales queue
    offlineQueue: [],

    // UI & Status
    isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
    submittingSale: false,
    lastCompletedSale: null,
  }),

  getters: {
    isOpenShift: (state) => !!state.activeShift && !state.activeShift.closed_at,
    
    cartItemsList: (state) => Object.values(state.cart.items),

    cartTotalPieces: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => acc + Number(item.quantity || 0), 0);
    },

    cartLinesCount: (state) => Object.keys(state.cart.items).length,

    cartSubtotal: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => {
        const qty = Number(item.quantity || 0);
        const price = Number(item.unit_price || 0);
        return acc + (qty * price);
      }, 0);
    },

    cartItemsDiscountTotal: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => {
        return acc + Number(item.discount_amount || 0);
      }, 0);
    },

    cartOriginalSubtotal: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => {
        const qty = Number(item.quantity || 0);
        const origPrice = Number(item.original_unit_price || item.unit_price || 0);
        return acc + (qty * origPrice);
      }, 0);
    },

    cartPriceOverrideSavings: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => {
        const qty = Number(item.quantity || 0);
        const orig = Number(item.original_unit_price || item.unit_price || 0);
        const current = Number(item.unit_price || 0);
        return acc + (orig > current ? (orig - current) * qty : 0);
      }, 0);
    },

    cartSumOfLineTotals: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => {
        return acc + Number(item.total || 0);
      }, 0);
    },

    cartInvoiceDiscountAmount: (state) => {
      const sumLines = Object.values(state.cart.items).reduce((acc, item) => acc + Number(item.total || 0), 0);
      if (sumLines <= 0) return 0;

      const type = state.cart.invoice_discount_type;
      const val = Number(state.cart.invoice_discount_value || 0);

      if (val <= 0) return 0;

      if (type === 'percentage') {
        const pct = Math.min(100, Math.max(0, val));
        return Math.round((sumLines * (pct / 100)) * 100) / 100;
      }

      return Math.min(sumLines, Math.round(val * 100) / 100);
    },

    cartGrandTotal: (state) => {
      const sumLines = Object.values(state.cart.items).reduce((acc, item) => acc + Number(item.total || 0), 0);
      const invDisc = state.cartInvoiceDiscountAmount;
      return Math.max(0, Math.round((sumLines - invDisc) * 100) / 100);
    },

    heldCount: (state) => state.heldSales.length,

    filteredProducts: (state) => {
      const q = state.searchQuery.trim().toLowerCase();
      const catId = state.selectedCategoryId;

      // Collect matched category IDs (including all descendant children)
      let matchedCategoryIds = null;
      if (catId) {
        matchedCategoryIds = new Set([catId]);
        const collectDescendants = (cats) => {
          for (const c of cats) {
            if (c.id === catId) {
              const addAll = (node) => {
                matchedCategoryIds.add(node.id);
                if (Array.isArray(node.children)) {
                  node.children.forEach(addAll);
                }
              };
              addAll(c);
              return true;
            }
            if (Array.isArray(c.children) && collectDescendants(c.children)) {
              return true;
            }
          }
          return false;
        };
        collectDescendants(state.categories);
      }

      return state.products.filter((p) => {
        if (matchedCategoryIds && !matchedCategoryIds.has(p.category_id)) {
          return false;
        }

        if (!q) return true;

        const nameMatch = p.name && p.name.toLowerCase().includes(q);
        const skuMatch = p.sku && p.sku.toLowerCase().includes(q);
        const barcodeMatch = p.barcode && p.barcode.toLowerCase().includes(q);

        return nameMatch || skuMatch || barcodeMatch;
      });
    },
  },

  actions: {
    /**
     * Initialize Local Storage state for Held Sales and Offline queue.
     */
    initLocalState() {
      try {
        const rawHeld = localStorage.getItem(HELD_SALES_KEY);
        this.heldSales = rawHeld ? JSON.parse(rawHeld) : [];
      } catch (e) {
        console.error('Error loading held sales from localStorage:', e);
        this.heldSales = [];
      }

      try {
        const rawQueue = localStorage.getItem(OFFLINE_QUEUE_KEY);
        this.offlineQueue = rawQueue ? JSON.parse(rawQueue) : [];
      } catch (e) {
        console.error('Error loading offline queue from localStorage:', e);
        this.offlineQueue = [];
      }

      // Online/Offline listener
      if (typeof window !== 'undefined') {
        window.addEventListener('online', () => {
          this.isOnline = true;
          this.syncOfflineQueue();
        });
        window.addEventListener('offline', () => {
          this.isOnline = false;
        });
      }
    },

    /**
     * Save held sales array strictly to localStorage.
     */
    persistHeldSales() {
      try {
        localStorage.setItem(HELD_SALES_KEY, JSON.stringify(this.heldSales));
      } catch (e) {
        console.error('Failed to persist held sales in localStorage:', e);
        useNotificationStore().error('تعذر حفظ الفواتير المعلقة في التخزين المحلي للمتصفح. تحقق من المساحة المتاحة.');
      }
    },

    /**
     * Save offline queue to localStorage.
     */
    persistOfflineQueue() {
      try {
        localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(this.offlineQueue));
      } catch (e) {
        console.error('Failed to persist offline queue in localStorage:', e);
      }
    },

    /**
     * Bootstrap POS environment: Catalog, Active Shifts, Warehouse, Payment Methods, Categories.
     */
    async bootstrap() {
      this.initLocalState();

      try {
        // 1. Fetch bootstrap catalog & open shifts
        const bootResp = await api.get('/pos/sync/bootstrap');
        const data = bootResp.data.data;

        this.products = (data.products || []).map((p) => {
          const qty = Number(p.stock_quantity ?? p.current_stock ?? 0);
          return {
            ...p,
            stock_quantity: qty,
            current_stock: qty,
            selling_price: Number(p.selling_price || 0),
          };
        });

        this.paymentMethods = data.payment_methods || [];
        this.currentWarehouse = data.warehouse || null;

        // Check if there is an active shift
        const openShifts = data.open_shifts || [];
        if (openShifts.length > 0) {
          // If current user opened a shift, pick it; otherwise pick the first open shift
          const authStore = useAuthStore();
          const userShift = openShifts.find((s) => s.opened_by === authStore.user?.id);
          this.activeShift = userShift || openShifts[0];
        } else {
          this.activeShift = null;
        }

        // 2. Fetch categories
        this.fetchCategories();

        // 3. Fetch customers
        this.fetchCustomers();

        // 4. Try syncing any pending offline sales if online
        if (this.isOnline && this.offlineQueue.length > 0) {
          this.syncOfflineQueue();
        }

        return { success: true };
      } catch (err) {
        console.error('POS bootstrap failed:', err);
        // If network failed, try fallback to cached bootstrap data
        return { success: false, error: err };
      }
    },

    async fetchCategories() {
      try {
        const resp = await api.get('/product-categories');
        this.categories = resp.data.data || [];
      } catch (err) {
        console.warn('Failed to load categories:', err);
      }
    },

    async fetchCustomers() {
      this.loadingCustomers = true;
      try {
        const resp = await api.get('/contacts', { params: { is_customer: 1, per_page: 100 } });
        this.customers = resp.data.data || [];
      } catch (err) {
        console.warn('Failed to load customers:', err);
      } finally {
        this.loadingCustomers = false;
      }
    },

    async reloadProducts(query = '', categoryId = null) {
      this.loadingProducts = true;
      try {
        const params = {};
        if (query) params.search = query;
        if (categoryId) params.category_id = categoryId;
        if (this.currentWarehouse?.id) params.warehouse_id = this.currentWarehouse.id;

        const resp = await api.get('/pos/products', { params });
        const list = resp.data.data || [];

        this.products = list.map((p) => {
          const whStock = p.warehouse_stocks && p.warehouse_stocks.length > 0
            ? Number(p.warehouse_stocks[0].quantity || 0)
            : Number(p.stock_quantity || 0);

          return {
            ...p,
            stock_quantity: whStock,
            selling_price: Number(p.selling_price || 0),
          };
        });
      } catch (err) {
        console.error('Failed to reload POS products:', err);
      } finally {
        this.loadingProducts = false;
      }
    },

    /**
     * Exact Barcode scanner lookup.
     */
    async lookupBarcode(barcode) {
      const code = String(barcode).trim();
      if (!code) return null;

      // 1. Check local loaded products first for instant response
      const local = this.products.find((p) => p.barcode === code);
      if (local) {
        this.addToCart(local);
        return local;
      }

      // 2. Query backend lookup
      try {
        const resp = await api.get('/pos/products/lookup', {
          params: {
            barcode: code,
            warehouse_id: this.currentWarehouse?.id,
          },
        });

        const product = resp.data.data;
        if (product) {
          const whStock = product.warehouse_stocks && product.warehouse_stocks.length > 0
            ? Number(product.warehouse_stocks[0].quantity || 0)
            : Number(product.stock_quantity || 0);

          const formatted = {
            ...product,
            stock_quantity: whStock,
            selling_price: Number(product.selling_price || 0),
          };

          // Cache in local list if not present
          if (!this.products.some((p) => p.id === formatted.id)) {
            this.products.unshift(formatted);
          }

          this.addToCart(formatted);
          return formatted;
        }
      } catch (err) {
        useNotificationStore().error(`لم يتم العثور على أي منتج مرتبط بالباركود: ${code}`);
        return null;
      }
    },

    /**
     * Add product to cart or increment quantity if exists.
     */
    addToCart(product, qtyToAdd = 1) {
      const notify = useNotificationStore();

      if (!this.isOpenShift) {
        notify.error('الوردية مغلقة. يجب فتح وردية جديدة قبل إضافة المنتجات والبيع.');
        return false;
      }

      const pId = product.id;
      const currentQty = this.cart.items[pId] ? Number(this.cart.items[pId].quantity) : 0;
      const newQty = currentQty + qtyToAdd;
      const availStock = Number(product.stock_quantity ?? 0);

      if (newQty > availStock) {
        notify.error(`الرصيد المتاح من '${product.name}' في المخزن هو ${availStock} فقط.`);
        return false;
      }

      if (this.cart.items[pId]) {
        this.cart.items[pId].quantity = newQty;
        this.recalcItemTotals(pId);
      } else {
        const origPrice = Number(product.selling_price || 0);
        this.cart.items[pId] = {
          product_id: pId,
          product: product,
          name: product.name,
          sku: product.sku,
          barcode: product.barcode,
          original_unit_price: origPrice,
          unit_price: origPrice,
          quantity: newQty,
          discount_type: null,
          discount_value: 0,
          discount_amount: 0,
          subtotal: origPrice * newQty,
          total: origPrice * newQty,
        };
        this.recalcItemTotals(pId);
      }

      return true;
    },

    /**
     * Update quantity for a cart line.
     */
    updateCartItemQty(productId, newQty) {
      const item = this.cart.items[productId];
      if (!item) return;

      const qty = Number(newQty);
      if (qty <= 0) {
        this.removeFromCart(productId);
        return;
      }

      const availStock = Number(item.product.stock_quantity ?? 0);
      if (qty > availStock) {
        useNotificationStore().error(`الرصيد المتاح من '${item.name}' هو ${availStock} فقط.`);
        item.quantity = availStock;
      } else {
        item.quantity = qty;
      }

      this.recalcItemTotals(productId);
    },

    /**
     * Update unit selling price for a cart line (Price Override).
     */
    updateCartItemPrice(productId, newPrice) {
      const item = this.cart.items[productId];
      if (!item) return;

      const authStore = useAuthStore();
      const parsed = Math.max(0, Number(newPrice || 0));

      // Check permission if overridden
      if (parsed !== item.original_unit_price) {
        if (!authStore.hasPermission('pos.override_price')) {
          useNotificationStore().error('ليس لديك صلاحية تعديل أو تجاوز سعر البيع.');
          return;
        }
      }

      item.unit_price = parsed;
      this.recalcItemTotals(productId);
    },

    /**
     * Update item-level discount.
     */
    updateCartItemDiscount(productId, type, value) {
      const item = this.cart.items[productId];
      if (!item) return;

      const authStore = useAuthStore();
      const parsedVal = Math.max(0, Number(value || 0));

      if (parsedVal > 0) {
        if (!authStore.hasPermission('pos.apply_item_discount')) {
          useNotificationStore().error('ليس لديك صلاحية تطبيق خصومات على الأصناف.');
          return;
        }
      }

      item.discount_type = parsedVal > 0 ? type : null;
      item.discount_value = parsedVal;
      this.recalcItemTotals(productId);
    },

    /**
     * Recalculate line totals and discount amount for a single item.
     */
    recalcItemTotals(productId) {
      const item = this.cart.items[productId];
      if (!item) return;

      const qty = Number(item.quantity || 0);
      const price = Number(item.unit_price || 0);
      const subtotal = Math.round((qty * price) * 100) / 100;
      item.subtotal = subtotal;

      let discAmt = 0;
      const dVal = Number(item.discount_value || 0);
      if (dVal > 0 && item.discount_type) {
        if (item.discount_type === 'percentage') {
          const pct = Math.min(100, Math.max(0, dVal));
          discAmt = Math.round((subtotal * (pct / 100)) * 100) / 100;
        } else {
          discAmt = Math.min(subtotal, Math.round(dVal * 100) / 100);
        }
      }

      item.discount_amount = discAmt;
      item.total = Math.max(0, Math.round((subtotal - discAmt) * 100) / 100);
    },

    /**
     * Remove item from cart.
     */
    removeFromCart(productId) {
      if (this.cart.items[productId]) {
        delete this.cart.items[productId];
      }
    },

    /**
     * Clear entire cart.
     */
    clearCart() {
      this.cart = {
        customer: null,
        items: {},
        invoice_discount_type: 'fixed',
        invoice_discount_value: 0,
        notes: '',
        client_uuid: null,
      };
    },

    /**
     * Update invoice-level discount.
     */
    setInvoiceDiscount(type, value) {
      const authStore = useAuthStore();
      const parsed = Math.max(0, Number(value || 0));

      if (parsed > 0) {
        if (!authStore.hasPermission('pos.apply_invoice_discount')) {
          useNotificationStore().error('ليس لديك صلاحية تطبيق خصم على إجمالي الفاتورة.');
          return;
        }
      }

      this.cart.invoice_discount_type = type;
      this.cart.invoice_discount_value = parsed;
    },

    setCustomer(customer) {
      this.cart.customer = customer;
    },

    /**
     * HOLD CART:
     * Saves the cart strictly to Local Storage.
     * Zero backend calls, no inventory reservations, no stock impact.
     */
    holdCurrentCart(referenceNote = '') {
      const notify = useNotificationStore();

      if (this.cartLinesCount === 0) {
        notify.info('لا توجد أصناف في السلة لتعليقها.');
        return false;
      }

      const heldEntry = {
        id: 'hold_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
        created_at: new Date().toISOString(),
        reference_note: referenceNote.trim() || `فاتورة معلقة #${this.heldSales.length + 1}`,
        customer: this.cart.customer,
        items: JSON.parse(JSON.stringify(this.cart.items)),
        invoice_discount_type: this.cart.invoice_discount_type,
        invoice_discount_value: this.cart.invoice_discount_value,
        notes: this.cart.notes,
        total: this.cartGrandTotal,
        pieces_count: this.cartTotalPieces,
        lines_count: this.cartLinesCount,
      };

      this.heldSales.unshift(heldEntry);
      this.persistHeldSales();

      this.clearCart();
      notify.success(`تم تعليق الفاتورة بنجاح في المتصفح (${heldEntry.reference_note})`);
      return true;
    },

    /**
     * RESUME HELD CART:
     * Restores cart from Local Storage, verifies product availability and current prices.
     */
    resumeHeldCart(heldId) {
      const notify = useNotificationStore();

      if (this.cartLinesCount > 0) {
        notify.error('يوجد أصناف في السلة الحالية. يرجى إتمامها أو تفريغها أو تعليقها أولاً.');
        return false;
      }

      const index = this.heldSales.findIndex((h) => h.id === heldId);
      if (index === -1) {
        notify.error('لم يتم العثور على الفاتورة المعلقة المحددة.');
        return false;
      }

      const held = this.heldSales[index];

      // Verify availability against current catalog
      const warnings = [];
      const restoredItems = {};

      for (const [pId, rawItem] of Object.entries(held.items)) {
        const prod = this.products.find((p) => p.id === Number(pId));
        if (!prod) {
          warnings.push(`المنتج '${rawItem.name}' لم يعد متوفراً في الكتالوج الحالي.`);
          continue;
        }

        const availStock = Number(prod.stock_quantity ?? 0);
        let qty = Number(rawItem.quantity);

        if (availStock < qty) {
          if (availStock <= 0) {
            warnings.push(`المنتج '${prod.name}' نفد رصيده من المخزن.`);
            continue;
          } else {
            warnings.push(`تم تخفيض كمية '${prod.name}' من ${qty} إلى الرصيد المتاح (${availStock}).`);
            qty = availStock;
          }
        }

        // Keep saved unit price and discounts from the held cart
        restoredItems[pId] = {
          ...rawItem,
          quantity: qty,
          product: prod,
        };
      }

      if (Object.keys(restoredItems).length === 0) {
        notify.error('تعذر استرجاع الفاتورة المعلقة لأن جميع أصنافها لم تعد متوفرة.');
        return false;
      }

      this.cart = {
        customer: held.customer,
        items: restoredItems,
        invoice_discount_type: held.invoice_discount_type || 'fixed',
        invoice_discount_value: Number(held.invoice_discount_value || 0),
        notes: held.notes || '',
        client_uuid: null,
      };

      // Recalculate all lines
      for (const pId of Object.keys(restoredItems)) {
        this.recalcItemTotals(pId);
      }

      // Remove from held list
      this.heldSales.splice(index, 1);
      this.persistHeldSales();

      if (warnings.length > 0) {
        notify.info(`تنبيهات استكمال الفاتورة:\n• ${warnings.join('\n• ')}`, 'تنبيه استكمال الفاتورة');
      } else {
        notify.success('تم استرجاع الفاتورة المعلقة بنجاح.');
      }

      return true;
    },

    /**
     * Delete a held sale manually from Local Storage.
     */
    deleteHeldCart(heldId) {
      this.heldSales = this.heldSales.filter((h) => h.id !== heldId);
      this.persistHeldSales();
      useNotificationStore().success('تم حذف الفاتورة المعلقة من المتصفح.');
    },

    /**
     * Finalize and submit official sale to Backend.
     */
    async checkout({ payment_method_id, paid_amount = 0, notes = '' }) {
      const notify = useNotificationStore();

      if (!this.isOpenShift) {
        notify.error('لا يمكن إتمام البيع. الوردية مغلقة حالياً.');
        return { success: false, message: 'الوردية مغلقة.' };
      }

      if (this.cartLinesCount === 0) {
        notify.error('السلة فارغة. يجب إضافة صنف واحد على الأقل.');
        return { success: false, message: 'السلة فارغة.' };
      }

      const clientUuid = this.cart.client_uuid || (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : ('pos_' + Date.now() + '_' + Math.random().toString(36).substring(2)));

      const payload = {
        client_uuid: clientUuid,
        shift_id: this.activeShift.id,
        warehouse_id: this.currentWarehouse?.id,
        customer_id: this.cart.customer?.id || null,
        payment_method_id: payment_method_id,
        invoice_discount_type: this.cart.invoice_discount_value > 0 ? this.cart.invoice_discount_type : null,
        invoice_discount_value: this.cart.invoice_discount_value > 0 ? this.cart.invoice_discount_value : null,
        notes: notes.trim() || this.cart.notes || null,
        items: Object.values(this.cart.items).map((it) => ({
          product_id: it.product_id,
          quantity: Number(it.quantity),
          unit_price: Number(it.unit_price),
          discount_type: it.discount_amount > 0 ? it.discount_type : null,
          discount_value: it.discount_amount > 0 ? Number(it.discount_value) : null,
        })),
      };

      this.submittingSale = true;

      try {
        if (this.isOnline) {
          const resp = await api.post('/pos/sales', payload);
          const sale = resp.data.data;

          this.lastCompletedSale = {
            ...sale,
            paid_amount: Number(paid_amount) || Number(sale.grand_total),
            change_amount: Math.max(0, (Number(paid_amount) || Number(sale.grand_total)) - Number(sale.grand_total)),
          };

          // Deduct local quantities in memory for instant feedback
          for (const it of payload.items) {
            const p = this.products.find((prod) => prod.id === it.product_id);
            if (p) {
              p.stock_quantity = Math.max(0, p.stock_quantity - it.quantity);
            }
          }

          // Clear cart on successful sale confirmation from server
          this.clearCart();
          notify.success(`تم حفظ وترحيل الفاتورة رقم ${sale.invoice_number} بنجاح.`);

          return { success: true, sale: this.lastCompletedSale };
        } else {
          // Offline mode: queue in localStorage
          this.offlineQueue.push({
            ...payload,
            created_at: new Date().toISOString(),
            grand_total: this.cartGrandTotal,
            paid_amount: Number(paid_amount) || this.cartGrandTotal,
          });
          this.persistOfflineQueue();

          const offlineSaleFake = {
            id: 'offline_' + Date.now(),
            invoice_number: 'OFFLINE-' + Date.now(),
            sold_at: new Date().toISOString(),
            is_offline: true,
            grand_total: this.cartGrandTotal,
            paid_amount: Number(paid_amount) || this.cartGrandTotal,
            change_amount: Math.max(0, (Number(paid_amount) || this.cartGrandTotal) - this.cartGrandTotal),
            customer: this.cart.customer,
            items: Object.values(this.cart.items).map((it) => ({
              product: it.product,
              product_snapshot: { name: it.name, sku: it.sku, barcode: it.barcode },
              quantity: it.quantity,
              unit_price: it.unit_price,
              total: it.total,
            })),
          };

          this.lastCompletedSale = offlineSaleFake;
          this.clearCart();
          notify.info('تم حفظ الفاتورة محلياً بنجاح (وضع العمل بدون إنترنت). سيتم الترحيل فور عودة الاتصال.');

          return { success: true, sale: offlineSaleFake };
        }
      } catch (err) {
        console.error('Sale checkout failed:', err);
        const msg = err.response?.data?.message || err.message || 'فشل إتمام البيع على الخادم.';
        notify.error(msg);
        return { success: false, message: msg };
      } finally {
        this.submittingSale = false;
      }
    },

    /**
     * Process pending offline queue to server.
     */
    async syncOfflineQueue() {
      if (!this.isOnline || this.offlineQueue.length === 0) return;

      const remaining = [];
      let syncedCount = 0;

      for (const item of this.offlineQueue) {
        try {
          const resp = await api.post('/pos/sync/sales', item);
          if (resp.data.status === 'synced') {
            syncedCount++;
          } else {
            remaining.push(item);
          }
        } catch (err) {
          console.warn('Offline sync item conflict/error:', err);
          remaining.push(item);
        }
      }

      this.offlineQueue = remaining;
      this.persistOfflineQueue();

      if (syncedCount > 0) {
        useNotificationStore().success(`تمت مزامنة ${syncedCount} فاتورة أوفلاين مع الخادم بنجاح.`);
      }
    },

    /**
     * Open a new shift.
     */
    async openShift(openingBalance = 0, notes = '') {
      const notify = useNotificationStore();
      try {
        const resp = await api.post('/pos/shifts', {
          opening_balance: Number(openingBalance || 0),
          notes: notes.trim() || null,
        });

        this.activeShift = resp.data.data;
        notify.success(`تم فتح وردية جديدة رقم: ${this.activeShift.shift_number}`);

        // Ensure catalog, categories and warehouse info are fully loaded
        if (this.products.length === 0 || this.categories.length === 0) {
          await this.bootstrap();
        }

        return { success: true, shift: this.activeShift };
      } catch (err) {
        const msg = err.response?.data?.message || 'فشل فتح الوردية.';
        notify.error(msg);
        return { success: false, message: msg };
      }
    },

    /**
     * Close currently active shift.
     */
    async closeShift(actualCash, notes = '') {
      const notify = useNotificationStore();

      if (!this.activeShift) {
        notify.error('لا توجد وردية مفتوحة لإغلاقها.');
        return { success: false };
      }

      try {
        const resp = await api.post(`/pos/shifts/${this.activeShift.id}/close`, {
          actual_cash: Number(actualCash || 0),
          notes: notes.trim() || null,
        });

        const summary = resp.data.data;
        this.activeShift = null;
        notify.success('تم إغلاق الوردية وتوثيق الفروقات المالية بنجاح.');
        return { success: true, summary };
      } catch (err) {
        const msg = err.response?.data?.message || 'فشل إغلاق الوردية.';
        notify.error(msg);
        return { success: false, message: msg };
      }
    },

    /**
     * Record cash expense.
     */
    async recordExpense({ amount, name, description }) {
      const notify = useNotificationStore();
      if (!this.activeShift) return { success: false };

      try {
        await api.post(`/pos/shifts/${this.activeShift.id}/expenses`, {
          amount: Number(amount),
          name: name.trim(),
          description: description?.trim() || null,
        });

        notify.success(`تم تسجيل مصروف نقدي بقيمة ${amount} ج.م بنجاح.`);
        return { success: true };
      } catch (err) {
        const msg = err.response?.data?.message || 'فشل تسجيل المصروف.';
        notify.error(msg);
        return { success: false, message: msg };
      }
    },

    /**
     * Record cash withdrawal.
     */
    async recordWithdrawal({ amount, name, description }) {
      const notify = useNotificationStore();
      if (!this.activeShift) return { success: false };

      try {
        await api.post(`/pos/shifts/${this.activeShift.id}/withdrawals`, {
          amount: Number(amount),
          name: name.trim(),
          description: description?.trim() || null,
        });

        notify.success(`تم تسجيل سحب نقدي بقيمة ${amount} ج.م بنجاح.`);
        return { success: true };
      } catch (err) {
        const msg = err.response?.data?.message || 'فشل تسجيل السحب النقدي.';
        notify.error(msg);
        return { success: false, message: msg };
      }
    },

    /**
     * Fetch shift summary details for the Close Shift modal.
     */
    async getShiftSummary(shiftId = null) {
      const id = shiftId || this.activeShift?.id;
      if (!id) return null;

      try {
        const resp = await api.get(`/pos/shifts/${id}`);
        return resp.data.data;
      } catch (err) {
        console.error('Failed to get shift summary:', err);
        return null;
      }
    },

    /**
     * Lookup an existing invoice by invoice number for returns.
     */
    async lookupInvoice(invoiceNumber) {
      const term = String(invoiceNumber).trim();
      if (!term) return null;

      try {
        const resp = await api.get(`/pos/sales/by-number/${term}`);
        return resp.data.data;
      } catch (err) {
        useNotificationStore().error(`لم يتم العثور على أي فاتورة برقم: ${term}`);
        return null;
      }
    },

    /**
     * Get returnable items summary for a sale.
     */
    async getReturnableItems(saleId) {
      try {
        const resp = await api.get(`/pos/sales/${saleId}/returnable-items`);
        return resp.data.data;
      } catch (err) {
        console.error('Failed to fetch returnable items:', err);
        return null;
      }
    },

    /**
     * Submit partial/full return.
     */
    async submitReturn({ pos_sale_id, items, notes = '' }) {
      const notify = useNotificationStore();

      if (!this.isOpenShift) {
        notify.error('الوردية مغلقة. يجب فتح وردية قبل إجراء عمليات الإرجاع.');
        return { success: false };
      }

      try {
        const resp = await api.post('/pos/returns', {
          shift_id: this.activeShift.id,
          pos_sale_id: pos_sale_id,
          warehouse_id: this.currentWarehouse?.id,
          notes: notes.trim() || null,
          items: items.map((it) => ({
            pos_sale_item_id: it.pos_sale_item_id,
            quantity: Number(it.quantity),
          })),
        });

        const ret = resp.data.data;
        notify.success(`تم إنشاء إشعار المرتجع رقم ${ret.return_number} ورد المبلغ بنجاح.`);
        return { success: true, return: ret };
      } catch (err) {
        const msg = err.response?.data?.message || 'فشل إجراء عملية الإرجاع.';
        notify.error(msg);
        return { success: false, message: msg };
      }
    },

    /**
     * Quick create customer.
     */
    async createCustomer({ name, phone = '', type = 'individual' }) {
      const notify = useNotificationStore();
      try {
        const resp = await api.post('/contacts', {
          name: name.trim(),
          phone: phone.trim() || null,
          type: type || 'individual',
          is_customer: true,
          is_active: true,
        });

        const created = resp.data.data;
        this.customers.unshift(created);
        this.setCustomer(created);
        notify.success(`تمت إضافة العميل '${created.name}' واختياره للسلة.`);
        return { success: true, customer: created };
      } catch (err) {
        const msg = err.response?.data?.message || 'فشل إنشاء العميل.';
        notify.error(msg);
        return { success: false, message: msg };
      }
    },
  },
});
