import i18n from '../i18n';

const resolveLocale = (locale) => locale || i18n.global.locale.value || 'ar';

/**
 * Format a numeric monetary amount using the active locale.
 *
 * @param {number|string|null|undefined} value
 * @param {string|null} locale
 * @param {number} decimals
 * @returns {string}
 */
export const formatMoney = (value, locale = null, decimals = 2) => {
  const num = Number(value ?? 0);
  if (Number.isNaN(num)) return (0).toFixed(decimals);
  const loc = resolveLocale(locale) === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US';
  return num.toLocaleString(loc, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
};

/**
 * Format a numeric quantity with optional decimals.
 *
 * @param {number|string|null|undefined} value
 * @param {string|null} locale
 * @param {number} maxDecimals
 * @returns {string}
 */
export const formatQuantity = (value, locale = null, maxDecimals = 3) => {
  const num = Number(value ?? 0);
  if (Number.isNaN(num)) return '0';
  const loc = resolveLocale(locale) === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US';
  return num.toLocaleString(loc, {
    minimumFractionDigits: 0,
    maximumFractionDigits: maxDecimals,
  });
};
