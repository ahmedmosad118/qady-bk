import { defineStore } from 'pinia';
import api from '../services/api.js';
import i18n from '../i18n';
import { useAuthStore } from './auth.js';
import { useNotificationStore } from './notification.js';

const t = (key, params) => i18n.global.t(key, params);

const HELD_SALES_KEY = 'qady_pos_held_sales';
const OFFLINE_QUEUE_KEY = 'qady_pos_offline_queue';
const POS_PRODUCTS_PER_PAGE = 32;

export const usePosStore = defineStore('pos', {
  state: () => ({
    // Session & Shift
    activeShift: null,
    availableOpenShifts: [],
    currentWarehouse: null,
    paymentMethods: [],
    
    // Catalog
    products: [],
    categories: [],
    selectedCategoryId: null,
    searchQuery: '',
    loadingProducts: false,
    loadingMoreProducts: false,
    productsMeta: {
      currentPage: 1,
      lastPage: 1,
      perPage: POS_PRODUCTS_PER_PAGE,
      total: 0,
      from: 0,
      to: 0,
    },
    _productsSeq: 0,
    
    // Customers
    customers: [],
    loadingCustomers: false,

    // Current Cart
    cart: {
      held_id: null,
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
    syncingQueue: false,

    // UI & Status
    isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
    submittingSale: false,
    lastCompletedSale: null,
    networkListenersAttached: false,
  }),

  getters: {
    isOpenShift: (state) => !!state.activeShift && !state.activeShift.closed_at,
    
    cartItemsList: (state) => Object.values(state.cart.items),

    cartTotalPieces: (state) => {
      return Object.values(state.cart.items).reduce((acc, item) => acc + Number(item.quantity || 0), 0);
    },

    cartLinesCount: (state) => Object.keys(state.cart.items).length,

    pendingOfflineCount: (state) => state.offlineQueue.filter((q) => q.sync_status !== 'conflict').length,

    conflictOfflineCount: (state) => state.offlineQueue.filter((q) => q.sync_status === 'conflict').length,

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
      // Online: backend /api/pos/products is authoritative for search, category (incl. children), and 32-per-page pagination.
      if (state.isOnline) {
        return state.products;
      }

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
        const barcodeMatch = p.barcode && p.barcode.toLowerCase().includes(q);

        return nameMatch || barcodeMatch;
      });
    },
  },

  actions: {
    /**
     * Initialize Local Storage state for Held Sales and Offline queue safely.
     * Guaranteed safe against malformed JSON or corrupted entries.
     * Network listeners attached strictly once.
     */
    initLocalState() {
      try {
        const rawHeld = localStorage.getItem(HELD_SALES_KEY);
        const parsedHeld = rawHeld ? JSON.parse(rawHeld) : [];
        this.heldSales = Array.isArray(parsedHeld)
          ? parsedHeld.filter((h) => h && typeof h === 'object' && h.id && h.items)
          : [];
      } catch (e) {
        console.error('Error loading held sales from localStorage:', e);
        this.heldSales = [];
      }

      try {
        const rawQueue = localStorage.getItem(OFFLINE_QUEUE_KEY);
        const parsedQueue = rawQueue ? JSON.parse(rawQueue) : [];
        this.offlineQueue = Array.isArray(parsedQueue)
          ? parsedQueue.filter((q) => q && typeof q === 'object' && q.client_uuid && Array.isArray(q.items) && q.items.length > 0)
          : [];
      } catch (e) {
        console.error('Error loading offline queue from localStorage:', e);
        this.offlineQueue = [];
      }

      // Online/Offline listeners: registered strictly once
      if (typeof window !== 'undefined' && !this.networkListenersAttached) {
        this._onlineHandler = () => {
          this.isOnline = true;
          this.syncOfflineQueue();
        };
        this._offlineHandler = () => {
          this.isOnline = false;
        };

        window.addEventListener('online', this._onlineHandler);
        window.addEventListener('offline', this._offlineHandler);
        this.networkListenersAttached = true;
      }
    },

    cleanupNetworkListeners() {
      if (typeof window !== 'undefined' && this.networkListenersAttached) {
        if (this._onlineHandler) {
          window.removeEventListener('online', this._onlineHandler);
          this._onlineHandler = null;
        }
        if (this._offlineHandler) {
          window.removeEventListener('offline', this._offlineHandler);
          this._offlineHandler = null;
        }
        this.networkListenersAttached = false;
      }
    },

    /**
     * Save held sales array strictly to localStorage.
     * Returns true on verified physical storage write, false on error/quota.
     */
    persistHeldSales() {
      try {
        localStorage.setItem(HELD_SALES_KEY, JSON.stringify(this.heldSales));
        return true;
      } catch (e) {
        console.error('Failed to persist held sales in localStorage:', e);
        const isQuota = e?.name === 'QuotaExceededError' || e?.code === 22 || e?.number === -2147024882;
        const msg = isQuota
          ? t('pos.store.heldQuotaExceeded')
          : t('pos.store.heldPersistFailed');
        useNotificationStore().error(msg);
        return false;
      }
    },

    /**
     * Save offline queue to localStorage.
     * Returns true on verified write, false on failure.
     */
    persistOfflineQueue() {
      try {
        localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(this.offlineQueue));
        return true;
      } catch (e) {
        console.error('Failed to persist offline queue in localStorage:', e);
        const isQuota = e?.name === 'QuotaExceededError' || e?.code === 22 || e?.number === -2147024882;
        const msg = isQuota
          ? t('pos.store.offlineQuotaExceeded')
          : t('pos.store.offlinePersistFailed');
        useNotificationStore().error(msg);
        return false;
      }
    },

    /**
     * Bootstrap POS environment: Catalog, Active Shifts, Warehouse, Payment Methods, Categories.
     */
    async bootstrap() {
      this.initLocalState();

      try {
        // 1. Fetch lightweight session bootstrap (warehouse, payment methods, open shifts) without dumping all products
        const bootResp = await api.get('/pos/sync/bootstrap', {
          params: { include_products: 0 },
        });
        const data = bootResp.data.data;

        this.paymentMethods = data.payment_methods || [];
        this.currentWarehouse = data.warehouse || null;

        // Shift Selection: strictly load available open shifts and resolve user's shift
        const openShifts = data.open_shifts || [];
        this.availableOpenShifts = openShifts;

        const authStore = useAuthStore();
        const currentUserId = authStore.user?.id;

        // Only auto-select if current user has exactly one open shift
        const myShifts = openShifts.filter((s) => s.opened_by === currentUserId);
        if (myShifts.length === 1) {
          this.activeShift = myShifts[0];
        } else {
          // If current user has 0 open shifts or multiple open shifts,
          // NEVER automatically hijack another user's shift!
          this.activeShift = null;
        }

        // 2. Fetch categories & first paginated page of products (32 per page) in parallel
        this.fetchCategories();
        await this.reloadProducts({
          query: this.searchQuery,
          categoryId: this.selectedCategoryId,
          page: 1,
        });

        // 3. Try syncing any pending offline sales if online
        if (this.isOnline && this.offlineQueue.length > 0 && !this.syncingQueue) {
          this.syncOfflineQueue();
        }

        return { success: true };
      } catch (err) {
        console.error('POS bootstrap failed:', err);
        // If network failed, try fallback to cached bootstrap data
        return { success: false, error: err };
      }
    },

    /**
     * Explicitly select an active shift by user choice.
     */
    selectShift(shiftId) {
      const shift = this.availableOpenShifts.find((s) => s.id === shiftId);
      if (shift) {
        this.activeShift = shift;
        useNotificationStore().success(t('pos.store.shiftSelected', { number: shift.shift_number }));
        return true;
      }
      return false;
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

    async reloadProducts(queryOrOptions = '', categoryId = null, page = 1) {
      let query = '';
      let catId = null;
      let targetPage = 1;

      if (typeof queryOrOptions === 'object' && queryOrOptions !== null) {
        query = queryOrOptions.query !== undefined ? queryOrOptions.query : this.searchQuery;
        catId = queryOrOptions.categoryId !== undefined ? queryOrOptions.categoryId : this.selectedCategoryId;
        targetPage = Math.max(1, Number(queryOrOptions.page ?? 1));
      } else {
        query = queryOrOptions;
        catId = categoryId;
        targetPage = Math.max(1, Number(page || 1));
      }

      this.loadingProducts = true;

      const seq = ++this._productsSeq;

      try {
        const params = {
          page: targetPage,
          per_page: POS_PRODUCTS_PER_PAGE,
        };
        const trimmedQuery = String(query || '').trim();
        if (trimmedQuery) params.search = trimmedQuery;
        if (catId) params.category_id = catId;
        if (this.currentWarehouse?.id) params.warehouse_id = this.currentWarehouse.id;

        const resp = await api.get('/pos/products', { params });
        if (seq !== this._productsSeq) return;

        const list = resp.data.data || [];
        const meta = resp.data.meta || {};
        const perPage = Number(meta.per_page || POS_PRODUCTS_PER_PAGE);
        const currentPage = Number(meta.current_page || targetPage);
        const total = Number(meta.total ?? list.length);
        const lastPage = Math.max(1, Number(meta.last_page || Math.ceil(total / perPage) || 1));

        const formatted = list.map((p) => {
          const whStock = p.warehouse_stocks && p.warehouse_stocks.length > 0
            ? Number(p.warehouse_stocks[0].quantity || 0)
            : Number(p.stock_quantity || 0);

          return {
            ...p,
            stock_quantity: whStock,
            selling_price: Number(p.selling_price || 0),
          };
        });

        this.products = formatted;

        const computedFrom = formatted.length > 0 ? (currentPage - 1) * perPage + 1 : 0;
        const computedTo = formatted.length > 0 ? computedFrom + formatted.length - 1 : 0;

        this.productsMeta = {
          currentPage,
          lastPage,
          perPage,
          total,
          from: Number(meta.from ?? computedFrom),
          to: Number(meta.to ?? computedTo),
        };
      } catch (err) {
        console.error('Failed to reload POS products:', err);
      } finally {
        if (seq === this._productsSeq) {
          this.loadingProducts = false;
          this.loadingMoreProducts = false;
        }
      }
    },

    async goToProductsPage(page) {
      const target = Math.min(this.productsMeta.lastPage || 1, Math.max(1, Number(page || 1)));
      if (target === this.productsMeta.currentPage && this.products.length > 0) return;

      await this.reloadProducts({
        query: this.searchQuery,
        categoryId: this.selectedCategoryId,
        page: target,
      });
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

          this.addToCart(formatted);
          return formatted;
        }
      } catch (err) {
        useNotificationStore().error(t('pos.store.barcodeNotFound', { code }));
        return null;
      }
    },

    /**
     * Add product to cart or increment quantity if exists.
     */
    addToCart(product, qtyToAdd = 1) {
      const notify = useNotificationStore();

      if (!this.isOpenShift) {
        notify.error(t('pos.store.shiftClosedAdd'));
        return false;
      }

      const pId = product.id;
      const currentQty = this.cart.items[pId] ? Number(this.cart.items[pId].quantity) : 0;
      const newQty = currentQty + qtyToAdd;
      const availStock = Number(product.stock_quantity ?? 0);

      if (newQty > availStock) {
        notify.error(t('pos.store.insufficientStock', { name: product.name, stock: availStock }));
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
        useNotificationStore().error(t('pos.store.insufficientStock', { name: item.name, stock: availStock }));
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
          useNotificationStore().error(t('pos.store.noOverridePricePerm'));
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
          useNotificationStore().error(t('pos.store.noItemDiscountPerm'));
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
     * Clear entire cart and reset transaction state.
     */
    clearCart() {
      this.cart = {
        held_id: null,
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
          useNotificationStore().error(t('pos.store.noInvoiceDiscountPerm'));
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
     * Safe against QuotaExceededError/SecurityError: Cart is NEVER cleared if storage fails.
     */
    holdCurrentCart(referenceNote = '') {
      const notify = useNotificationStore();

      if (this.cartLinesCount === 0) {
        notify.info(t('pos.store.noItemsToHold'));
        return false;
      }

      const heldEntry = {
        id: 'hold_' + Date.now() + '_' + Math.random().toString(36).substring(2, 6),
        created_at: new Date().toISOString(),
        reference_note: referenceNote.trim() || t('pos.store.heldDefaultNote', { number: this.heldSales.length + 1 }),
        customer: this.cart.customer ? { ...this.cart.customer } : null,
        items: JSON.parse(JSON.stringify(this.cart.items)),
        invoice_discount_type: this.cart.invoice_discount_type,
        invoice_discount_value: this.cart.invoice_discount_value,
        notes: this.cart.notes,
        total: this.cartGrandTotal,
        pieces_count: this.cartTotalPieces,
        lines_count: this.cartLinesCount,
      };

      const backup = [...this.heldSales];
      this.heldSales.unshift(heldEntry);
      const saved = this.persistHeldSales();

      if (!saved) {
        // Rollback in-memory state so it matches physical storage
        this.heldSales = backup;
        return false;
      }

      // ONLY clear cart after successful, verified physical persistence
      this.clearCart();
      notify.success(t('pos.store.heldSuccess', { note: heldEntry.reference_note }));
      return true;
    },

    /**
     * RESUME HELD CART:
     * Restores cart from Local Storage without silent mutation.
     * 1. Preserves original held copy untouched in localStorage.
     * 2. Inspects availability, prices, and permissions.
     * 3. Does NOT silently reduce quantity, does NOT silently drop missing items,
     *    and does NOT silently change prices or discounts.
     * 4. Reports any discrepancies clearly to the cashier.
     */
    resumeHeldCart(heldId) {
      const notify = useNotificationStore();

      if (this.cartLinesCount > 0) {
        notify.error(t('pos.store.cartNotEmptyResume'));
        return { success: false, reason: 'cart_not_empty' };
      }

      const held = this.heldSales.find((h) => h.id === heldId);
      if (!held) {
        notify.error(t('pos.store.heldNotFound'));
        return { success: false, reason: 'not_found' };
      }

      const authStore = useAuthStore();
      const discrepancies = [];

      for (const [pId, rawItem] of Object.entries(held.items)) {
        const prod = this.products.find((p) => p.id === Number(pId))
          || (this.productsMeta.lastPage > 1 ? rawItem.product : null);
        if (!prod) {
          discrepancies.push({
            type: 'missing_product',
            name: rawItem.name,
            message: t('pos.store.heldMissingProduct', { name: rawItem.name }),
          });
          continue;
        }

        const availStock = Number(prod.stock_quantity ?? 0);
        const reqQty = Number(rawItem.quantity || 0);

        if (availStock < reqQty) {
          discrepancies.push({
            type: 'stock_deficit',
            name: rawItem.name,
            heldQty: reqQty,
            availableStock: availStock,
            message: t('pos.store.heldStockDeficit', { name: rawItem.name, req: reqQty, avail: availStock }),
          });
        }

        if (Number(prod.selling_price || 0) !== Number(rawItem.original_unit_price || 0)) {
          discrepancies.push({
            type: 'price_changed',
            name: rawItem.name,
            oldPrice: rawItem.original_unit_price,
            newPrice: prod.selling_price,
            message: t('pos.store.heldPriceChanged', { name: rawItem.name, oldPrice: rawItem.original_unit_price, newPrice: prod.selling_price }),
          });
        }

        // Permissions check
        if (rawItem.unit_price !== rawItem.original_unit_price && !authStore.hasPermission('pos.override_price')) {
          discrepancies.push({
            type: 'permission_override_price',
            name: rawItem.name,
            message: t('pos.store.heldNoPriceOverridePerm', { name: rawItem.name, price: rawItem.unit_price }),
          });
        }

        if (rawItem.discount_amount > 0 && !authStore.hasPermission('pos.apply_item_discount')) {
          discrepancies.push({
            type: 'permission_item_discount',
            name: rawItem.name,
            message: t('pos.store.heldNoItemDiscountPerm', { name: rawItem.name }),
          });
        }
      }

      if (Number(held.invoice_discount_value || 0) > 0 && !authStore.hasPermission('pos.apply_invoice_discount')) {
        discrepancies.push({
          type: 'permission_invoice_discount',
          message: t('pos.store.heldNoInvoiceDiscountPerm'),
        });
      }

      // DO NOT silently reduce quantity!
      // DO NOT silently delete missing items!
      // DO NOT silently alter price or discount!
      // DO NOT delete held sale from localStorage on resume!

      // Restore cart with original held data intact
      const restoredItems = {};
      for (const [pId, rawItem] of Object.entries(held.items)) {
        const prod = this.products.find((p) => p.id === Number(pId))
          || (this.productsMeta.lastPage > 1 ? rawItem.product : null);
        restoredItems[pId] = {
          ...rawItem,
          product: prod || { id: Number(pId), name: rawItem.name, stock_quantity: 0 },
        };
      }

      this.cart = {
        held_id: held.id,
        customer: held.customer ? { ...held.customer } : null,
        items: restoredItems,
        invoice_discount_type: held.invoice_discount_type || 'fixed',
        invoice_discount_value: Number(held.invoice_discount_value || 0),
        notes: held.notes || '',
        client_uuid: null,
      };

      for (const pId of Object.keys(restoredItems)) {
        this.recalcItemTotals(pId);
      }

      if (discrepancies.length > 0) {
        const bullets = discrepancies.map((d) => `• ${d.message}`).join('\n');
        notify.info(t('pos.store.heldAlertsBody', { bullets }), t('pos.store.heldAlertsTitle'));
      } else {
        notify.success(t('pos.store.heldResumedSuccess'));
      }

      return { success: true, discrepancies };
    },

    /**
     * Delete a held sale manually from Local Storage.
     */
    deleteHeldCart(heldId) {
      this.heldSales = this.heldSales.filter((h) => h.id !== heldId);
      this.persistHeldSales();
      useNotificationStore().success(t('pos.store.heldDeleted'));
    },

    /**
     * Finalize and submit official sale to Backend.
     * Enforces frontend idempotency:
     * - Prevents double clicking / parallel checkout calls.
     * - Retains stable client_uuid during retry or timeouts.
     * - Safely queues offline sales with confirmed storage write before clearing cart.
     */
    async checkout({ payment_method_id, paid_amount = 0, notes = '' }) {
      const notify = useNotificationStore();

      if (this.submittingSale) {
        return { success: false, message: t('pos.store.saleInProgress') };
      }

      if (!this.isOpenShift) {
        notify.error(t('pos.store.cannotCheckoutShiftClosed'));
        return { success: false, message: t('pos.store.shiftClosedShort') };
      }

      if (this.cartLinesCount === 0) {
        notify.error(t('pos.store.emptyCartCheckout'));
        return { success: false, message: t('pos.store.emptyCartShort') };
      }

      // Maintain stable client_uuid on the cart during retries
      if (!this.cart.client_uuid) {
        this.cart.client_uuid = (typeof crypto !== 'undefined' && crypto.randomUUID)
          ? crypto.randomUUID()
          : ('pos_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10));
      }
      const clientUuid = this.cart.client_uuid;

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

          // If cart came from a held sale, clean it up only upon confirmed successful checkout
          if (this.cart.held_id) {
            this.deleteHeldCart(this.cart.held_id);
          }

          // Clear cart on successful sale confirmation from server
          this.clearCart();
          notify.success(t('pos.store.salePostedSuccess', { number: sale.invoice_number }));

          return { success: true, sale: this.lastCompletedSale };
        } else {
          // Offline mode: queue in localStorage
          const offlineRecord = {
            ...payload,
            created_at: new Date().toISOString(),
            grand_total: this.cartGrandTotal,
            paid_amount: Number(paid_amount) || this.cartGrandTotal,
            sync_status: 'pending',
            conflict_reason: null,
            customer: this.cart.customer ? { ...this.cart.customer } : null,
            items_snapshot: Object.values(this.cart.items).map((it) => ({
              product_id: it.product_id,
              name: it.name,
              barcode: it.barcode,
              quantity: it.quantity,
              unit_price: it.unit_price,
              total: it.total,
            })),
          };

          const backupQueue = [...this.offlineQueue];
          const existingIdx = this.offlineQueue.findIndex((q) => q.client_uuid === clientUuid);
          if (existingIdx >= 0) {
            this.offlineQueue[existingIdx] = offlineRecord;
          } else {
            this.offlineQueue.push(offlineRecord);
          }

          const saved = this.persistOfflineQueue();
          if (!saved) {
            // Rollback in-memory queue so it matches physical storage
            this.offlineQueue = backupQueue;
            return { success: false, message: t('pos.store.offlineSaveFailed') };
          }

          // If cart came from held sale, delete it now that offline persistence succeeded
          if (this.cart.held_id) {
            this.deleteHeldCart(this.cart.held_id);
          }

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
              product_snapshot: { name: it.name, barcode: it.barcode },
              quantity: it.quantity,
              unit_price: it.unit_price,
              total: it.total,
            })),
          };

          this.lastCompletedSale = offlineSaleFake;
          this.clearCart();
          notify.info(t('pos.store.offlineSavedInfo'));

          return { success: true, sale: offlineSaleFake };
        }
      } catch (err) {
        console.error('Sale checkout failed:', err);
        const msg = err.response?.data?.message || err.message || t('pos.store.checkoutServerFailed');
        notify.error(msg);
        return { success: false, message: msg };
      } finally {
        this.submittingSale = false;
      }
    },

    /**
     * Process pending offline queue to server.
     * Prevents concurrent sync loops via syncingQueue guard.
     * Preserves conflicting or errored transactions without deleting them.
     */
    async syncOfflineQueue() {
      if (this.syncingQueue || !this.isOnline || this.offlineQueue.length === 0) return;
      this.syncingQueue = true;

      const remaining = [];
      let syncedCount = 0;
      let conflictCount = 0;

      try {
        for (const item of this.offlineQueue) {
          try {
            const resp = await api.post('/pos/sync/sales', item);
            if (resp.data.status === 'synced' || resp.data.success) {
              syncedCount++;
              // Successfully synced -> omit from remaining
            } else {
              remaining.push({
                ...item,
                sync_status: 'conflict',
                conflict_reason: resp.data.conflict_reason || resp.data.message || t('pos.store.syncConflictFallback'),
              });
              conflictCount++;
            }
          } catch (err) {
            const isConflict = err.response?.status === 409 || err.response?.data?.status === 'conflict';
            const reason = err.response?.data?.conflict_reason || err.response?.data?.message || err.message;
            if (isConflict) {
              remaining.push({
                ...item,
                sync_status: 'conflict',
                conflict_reason: reason,
              });
              conflictCount++;
            } else {
              // Network or transient server error: keep as pending
              remaining.push({
                ...item,
                sync_status: 'pending',
                last_error: reason,
              });
            }
          }
        }

        this.offlineQueue = remaining;
        this.persistOfflineQueue();

        if (syncedCount > 0) {
          useNotificationStore().success(t('pos.store.syncSuccessCount', { count: syncedCount }));
        }
        if (conflictCount > 0) {
          useNotificationStore().error(t('pos.store.syncConflictCount', { count: conflictCount }));
        }
      } finally {
        this.syncingQueue = false;
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
        notify.success(t('pos.store.shiftOpenedSuccess', { number: this.activeShift.shift_number }));

        // Ensure catalog, categories and warehouse info are fully loaded
        if (this.products.length === 0 || this.categories.length === 0) {
          await this.bootstrap();
        }

        return { success: true, shift: this.activeShift };
      } catch (err) {
        const msg = err.response?.data?.message || t('pos.store.shiftOpenFailed');
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
        notify.error(t('pos.store.noOpenShiftToClose'));
        return { success: false };
      }

      try {
        const closingId = this.activeShift.id;
        const resp = await api.post(`/pos/shifts/${closingId}/close`, {
          actual_cash: Number(actualCash || 0),
          notes: notes.trim() || null,
        });

        if (resp.data?.success === false) {
          const msg = resp.data?.message || t('pos.store.shiftCloseFailed');
          notify.error(msg);
          return { success: false, message: msg };
        }

        const summary = resp.data.data;
        this.activeShift = null;
        this.availableOpenShifts = (this.availableOpenShifts || []).filter((s) => s.id !== closingId);
        notify.success(t('pos.store.shiftClosedSuccess'));
        return { success: true, summary };
      } catch (err) {
        const msg = err.response?.data?.message || t('pos.store.shiftCloseFailed');
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

        notify.success(t('pos.store.expenseRecordedSuccess', { amount }));
        return { success: true };
      } catch (err) {
        const msg = err.response?.data?.message || t('pos.store.expenseRecordFailed');
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

        notify.success(t('pos.store.withdrawalRecordedSuccess', { amount }));
        return { success: true };
      } catch (err) {
        const msg = err.response?.data?.message || t('pos.store.withdrawalRecordFailed');
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
     * Search POS invoices with partial matching and server-side pagination.
     */
    async searchInvoices({ search = '', page = 1, perPage = 8 } = {}) {
      const term = String(search || '').trim();
      try {
        const params = { page, per_page: perPage };
        if (term) params.search = term;

        const resp = await api.get('/pos/sales', { params });
        const rows = Array.isArray(resp.data?.data) ? resp.data.data : [];
        const meta = resp.data?.meta || {
          current_page: page,
          last_page: 1,
          per_page: perPage,
          total: rows.length,
        };

        return { data: rows, meta };
      } catch (err) {
        console.error('Failed to search invoices:', err);
        return {
          data: [],
          meta: { current_page: 1, last_page: 1, per_page: perPage, total: 0 },
        };
      }
    },

    /**
     * Load full sale invoice details by ID (items, payment method, customer, returns).
     */
    async getSaleDetails(saleId) {
      if (!saleId) return null;
      try {
        const resp = await api.get(`/pos/sales/${saleId}`);
        return resp.data?.data || null;
      } catch (err) {
        console.error('Failed to fetch sale details:', err);
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
        useNotificationStore().error(t('pos.store.invoiceNotFound', { number: term }));
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
     * Submit partial/full return with idempotency support.
     */
    async submitReturn({ pos_sale_id, items, notes = '', client_uuid = null }) {
      const notify = useNotificationStore();

      if (!this.isOpenShift) {
        notify.error(t('pos.store.shiftClosedReturn'));
        return { success: false };
      }

      const clientUuid = client_uuid || ((typeof crypto !== 'undefined' && crypto.randomUUID)
        ? crypto.randomUUID()
        : ('ret_' + Date.now() + '_' + Math.random().toString(36).substring(2, 10)));

      try {
        const resp = await api.post('/pos/returns', {
          client_uuid: clientUuid,
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
        notify.success(t('pos.store.returnCreatedSuccess', { number: ret.return_number }));
        return { success: true, return: ret };
      } catch (err) {
        const msg = err.response?.data?.message || t('pos.store.returnCreateFailed');
        notify.error(msg);
        return { success: false, message: msg };
      }
    },

    /**
     * Quick create customer.
     */
    async createCustomer({ name, mobile = '', type = 'individual' }) {
      const notify = useNotificationStore();
      try {
        const resp = await api.post('/contacts', {
          name: name.trim(),
          mobile: mobile.trim(),
          type: type || 'individual',
          is_customer: true,
        });

        const created = resp.data.data;
        this.setCustomer(created);
        notify.success(t('pos.store.customerCreatedSuccess', { name: created.name }));
        return { success: true, customer: created };
      } catch (err) {
        const errors = err.response?.data?.errors || {};
        const msg = err.response?.data?.message || t('pos.store.customerCreateFailed');
        notify.error(msg);
        return { success: false, message: msg, errors };
      }
    },
  },
});
