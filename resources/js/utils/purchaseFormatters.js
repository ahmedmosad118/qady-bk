import i18n from '../i18n';
import { formatMoney } from './format';

const t = (key, params) => i18n.global.t(key, params);

export const itemDiscountsAmount = (doc) => {
  if (doc?.items_sum_discount_amount !== undefined && doc?.items_sum_discount_amount !== null) {
    return Number(doc.items_sum_discount_amount) || 0;
  }
  return (doc?.items || []).reduce((sum, i) => sum + (Number(i.discount_amount) || 0), 0);
};

export const totalDiscountAmount = (doc) => itemDiscountsAmount(doc) + (Number(doc?.discount_amount) || 0);

export const discountBreakdownTitle = (doc, i18nPrefix = 'purchaseOrders') => {
  const itemDisc = itemDiscountsAmount(doc);
  const docDisc = Number(doc?.discount_amount) || 0;
  const parts = [];
  if (itemDisc > 0) parts.push(`${t(`${i18nPrefix}.itemDiscounts`)}: ${formatMoney(itemDisc)}`);
  if (docDisc > 0) parts.push(`${t(`${i18nPrefix}.orderDiscount`)}: ${formatMoney(docDisc)}`);
  return parts.join(' | ');
};

export const purchaseStatusBadgeClass = (status) => {
  switch (status) {
    case 'draft':
      return 'bg-amber-500/10 text-amber-700 border border-amber-500/20 dark:text-amber-400';
    case 'converted':
    case 'confirmed':
    case 'posted':
      return 'bg-emerald-500/10 text-emerald-700 border border-emerald-500/20 dark:text-emerald-400';
    case 'cancelled':
      return 'bg-rose-500/10 text-rose-700 border border-rose-500/20 dark:text-rose-400';
    default:
      return 'bg-gray-500/10 text-gray-700 border border-gray-500/20 dark:text-gray-400';
  }
};
