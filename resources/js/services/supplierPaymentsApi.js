import api from './api';

export function fetchSupplierPayments(params = {}) {
  return api.get('/supplier-payments', { params });
}

export function fetchSupplierPayment(id) {
  return api.get(`/supplier-payments/${id}`);
}

export function createSupplierPayment(payload) {
  return api.post('/supplier-payments', payload);
}

export function updateSupplierPayment(id, payload) {
  return api.put(`/supplier-payments/${id}`, payload);
}

export function postSupplierPayment(id) {
  return api.post(`/supplier-payments/${id}/post`);
}

export function cancelSupplierPayment(id) {
  return api.post(`/supplier-payments/${id}/cancel`);
}

export function deleteSupplierPayment(id) {
  return api.delete(`/supplier-payments/${id}`);
}

export function fetchOutstandingSupplierInvoices(supplierId) {
  return api.get('/supplier-payments/outstanding-invoices', {
    params: { supplier_id: supplierId },
  });
}
