import api from './api';

export function fetchJournals(params = {}) {
  return api.get('/accounting/journals', { params });
}

export function createJournal(payload) {
  return api.post('/accounting/journals', payload);
}

export function updateJournal(id, payload) {
  return api.put(`/accounting/journals/${id}`, payload);
}

export function fetchFiscalYears(params = {}) {
  return api.get('/accounting/fiscal-years', { params });
}

export function fetchFiscalYear(id) {
  return api.get(`/accounting/fiscal-years/${id}`);
}

export function createFiscalYear(payload) {
  return api.post('/accounting/fiscal-years', payload);
}

export function updateFiscalYear(id, payload) {
  return api.put(`/accounting/fiscal-years/${id}`, payload);
}

export function fetchAccountingPeriods(params = {}) {
  return api.get('/accounting/accounting-periods', { params });
}

export function createAccountingPeriod(payload) {
  return api.post('/accounting/accounting-periods', payload);
}

export function updateAccountingPeriod(id, payload) {
  return api.put(`/accounting/accounting-periods/${id}`, payload);
}
