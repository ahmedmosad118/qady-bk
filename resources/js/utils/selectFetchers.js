import api from '../services/api';

/**
 * Generic server-side paginated fetcher for SearchableSelect.
 *
 * @param {string} endpoint
 * @param {Function} getParams - ({ search, page, perPage }) => object
 * @param {Function} mapItem - (item) => ({ value, label, ... })
 */
export function createPaginatedFetcher(endpoint, getParams, mapItem) {
  return async ({ search = '', page = 1, perPage = 10 } = {}) => {
    const extra = typeof getParams === 'function' ? getParams({ search, page, perPage }) : (getParams || {});
    const params = {
      ...extra,
      page,
      per_page: perPage,
    };
    if (search) {
      params.search = search;
    }

    const res = await api.get(endpoint, { params });
    const rows = Array.isArray(res.data?.data) ? res.data.data : [];
    const meta = res.data?.meta || null;

    return {
      options: rows.map(mapItem),
      hasMore: meta ? Number(meta.current_page) < Number(meta.last_page) : false,
      total: meta ? Number(meta.total) : rows.length,
      currentPage: meta ? Number(meta.current_page) : page,
      lastPage: meta ? Number(meta.last_page) : page,
    };
  };
}

export function createProductFetcher({ activeOnly = true, mapItem } = {}) {
  return createPaginatedFetcher(
    '/products',
    () => (activeOnly ? { is_active: 1 } : {}),
    mapItem || ((p) => ({
      value: p.id,
      label: p.name,
      barcode: p.barcode,
      purchase_price: p.purchase_price,
      selling_price: p.selling_price,
      raw: p,
    }))
  );
}

export function createContactFetcher({ isSupplier = false, isCustomer = false, activeOnly = true } = {}) {
  return createPaginatedFetcher(
    '/contacts',
    () => {
      const params = {};
      if (isSupplier) params.is_supplier = 1;
      if (isCustomer) params.is_customer = 1;
      if (activeOnly) params.is_active = 1;
      return params;
    },
    (c) => ({
      value: c.id,
      label: c.name,
      raw: c,
    })
  );
}

export function createWarehouseFetcher({ activeOnly = true, formatLabel } = {}) {
  return createPaginatedFetcher(
    '/warehouses',
    () => (activeOnly ? { active_only: 1 } : { active_only: 0 }),
    (w) => ({
      value: w.id,
      label: formatLabel ? formatLabel(w) : w.name,
      raw: w,
    })
  );
}

export function createExpenseCategoryFetcher() {
  return createPaginatedFetcher(
    '/expense-categories',
    () => ({}),
    (cat) => ({
      value: cat.id,
      label: cat.name,
      raw: cat,
    })
  );
}

export function createAccountFetcher({ accountType = '', accountPurpose = '' } = {}) {
  return createPaginatedFetcher(
    '/accounting/accounts',
    () => {
      const params = {};
      if (accountType) params.account_type = accountType;
      if (accountPurpose) params.account_purpose = accountPurpose;
      return params;
    },
    (acc) => ({
      value: acc.id,
      label: acc.code ? `${acc.code} — ${acc.name}` : acc.name,
      raw: acc,
    })
  );
}

export function createProductCategoryFetcher() {
  return createPaginatedFetcher(
    '/product-categories',
    () => ({}),
    (cat) => ({
      value: cat.id,
      label: cat.name,
      raw: cat,
    })
  );
}
