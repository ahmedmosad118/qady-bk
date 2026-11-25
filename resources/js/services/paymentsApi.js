import api from './api';

export function fetchPayments(params = {}) {
  return api.get('/payments', { params });
}

export function fetchPayment(id) {
  return api.get(`/payments/${id}`);
}

export function createPayment(payload) {
  return api.post('/payments', payload);
}

export function updatePayment(id, payload) {
  return api.put(`/payments/${id}`, payload);
}

export function postPayment(id) {
  return api.post(`/payments/${id}/post`);
}

export function cancelPayment(id) {
  return api.post(`/payments/${id}/cancel`);
}

export function deletePayment(id) {
  return api.delete(`/payments/${id}`);
}

export function fetchOutstandingSupplierInvoices(supplierId) {
  return api.get('/supplier-payments/outstanding-invoices', {
    params: { supplier_id: supplierId },
  });
}
