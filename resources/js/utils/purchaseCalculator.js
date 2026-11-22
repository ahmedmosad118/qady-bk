/**
 * Pure financial calculation helpers shared across Purchase Orders and Purchase Invoices.
 */

export const roundMoney = (val) => Math.round((Number(val || 0) + Number.EPSILON) * 100) / 100;

export const calcLineGross = (item) =>
  roundMoney(Number(item?.quantity || 0) * Number(item?.unit_cost || 0));

export const calcLineDiscount = (item) => {
  const gross = calcLineGross(item);
  if (!item?.discount_type || item.discount_value === '' || item.discount_value === null) return 0;
  const val = Number(item.discount_value || 0);
  if (val <= 0) return 0;
  if (item.discount_type === 'percentage') {
    return roundMoney(gross * (Math.min(val, 100) / 100));
  }
  return roundMoney(Math.min(val, gross));
};

export const calcLineTotal = (item) => Math.max(0, roundMoney(calcLineGross(item) - calcLineDiscount(item)));

export const calcGrossTotal = (items = []) =>
  roundMoney(items.reduce((sum, item) => sum + calcLineGross(item), 0));

export const calcItemDiscountsTotal = (items = []) =>
  roundMoney(items.reduce((sum, item) => sum + calcLineDiscount(item), 0));

export const calcSubtotalAfterItems = (items = []) =>
  roundMoney(items.reduce((sum, item) => sum + calcLineTotal(item), 0));

export const calcDocumentDiscount = (subtotalAfterItems, discountType, discountValue) => {
  if (!discountType || discountValue === '' || discountValue === null) return 0;
  const val = Number(discountValue || 0);
  if (val <= 0) return 0;
  if (discountType === 'percentage') {
    return roundMoney(subtotalAfterItems * (Math.min(val, 100) / 100));
  }
  return roundMoney(Math.min(val, subtotalAfterItems));
};

export const calcExpensesTotal = (expenses = []) =>
  roundMoney(expenses.reduce((sum, exp) => sum + Number(exp?.amount || 0), 0));

export const calcTotalQuantity = (items = []) =>
  items.reduce((sum, item) => sum + Number(item?.quantity || 0), 0);
