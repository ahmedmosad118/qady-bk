import api from './api';

/**
 * Stateless POS API client for sales history, returns, shift summaries, and quick customer creation.
 */
export const posApi = {
  async searchInvoices(params = {}) {
    const resp = await api.get('/pos/sales', { params });
    return {
      data: resp.data?.data || [],
      meta: resp.data?.meta || { current_page: 1, last_page: 1, total: 0 },
    };
  },

  async getSaleDetails(saleId) {
    const resp = await api.get(`/pos/sales/${saleId}`);
    return resp.data?.data || null;
  },

  async lookupInvoice(invoiceNumber) {
    const term = String(invoiceNumber || '').trim();
    if (!term) return null;
    const resp = await api.get(`/pos/sales/by-number/${encodeURIComponent(term)}`);
    return resp.data?.data || null;
  },

  async getReturnableItems(saleId) {
    const resp = await api.get(`/pos/sales/${saleId}/returnable-items`);
    return resp.data?.data || null;
  },

  async submitReturn(payload) {
    const resp = await api.post('/pos/returns', payload);
    return resp.data?.data;
  },

  async getShiftSummary(shiftId) {
    const resp = await api.get(`/pos/shifts/${shiftId}`);
    return resp.data?.data || null;
  },

  async createCustomer(payload) {
    const resp = await api.post('/contacts', payload);
    return resp.data?.data;
  },
};

export default posApi;
