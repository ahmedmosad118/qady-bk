/**
 * QADY ERP — Comprehensive Form & Input Validators
 */
import i18n from '../i18n';

const t = (key, params) => i18n.global.t(key, params);

/**
 * Validate Egyptian and International phone numbers.
 * Supported valid formats:
 * - Egyptian Mobile: 010xxxxxxxx, 011xxxxxxxx, 012xxxxxxxx, 015xxxxxxxx (11 digits)
 * - Egyptian Landline: 02xxxxxxxx, 03xxxxxxxx, 04xxxxxxxx, 05xxxxxxxx, 08xxxxxxxx, 09xxxxxxxx (8 to 10 digits)
 * - International: +20..., +966..., +971..., 00966... (7 to 15 digits)
 *
 * @param {string|number} phone
 * @param {Object} options
 * @returns {{ isValid: boolean, message: string }}
 */
export function validatePhone(phone, { required = false, label = '' } = {}) {
  const fieldLabel = label || t('validators.phoneLabel');
  if (phone === undefined || phone === null || phone.toString().trim() === '') {
    if (required) {
      return { isValid: false, message: t('validators.required', { label: fieldLabel }) };
    }
    return { isValid: true, message: '' };
  }

  const raw = phone.toString().trim();
  const clean = raw.replace(/[\s\-_().]/g, '');

  if (!/^\+?[0-9]+$/.test(clean)) {
    return {
      isValid: false,
      message: t('validators.phoneInvalidChars', { label: fieldLabel }),
    };
  }

  if (/^01[0125]/.test(clean)) {
    if (clean.length !== 11) {
      return {
        isValid: false,
        message: t('validators.phoneEgyptianMobileLen', { label: fieldLabel, length: clean.length }),
      };
    }
    return { isValid: true, message: '' };
  }

  if (/^0[2345689]/.test(clean)) {
    if (clean.length < 8 || clean.length > 10) {
      return {
        isValid: false,
        message: t('validators.phoneEgyptianLandlineLen', { label: fieldLabel }),
      };
    }
    return { isValid: true, message: '' };
  }

  if (clean.startsWith('+') || clean.startsWith('00')) {
    const digitsOnly = clean.replace(/^\+|^00/, '');
    if (digitsOnly.length < 7 || digitsOnly.length > 15) {
      return {
        isValid: false,
        message: t('validators.phoneInternationalLen', { label: fieldLabel }),
      };
    }
    return { isValid: true, message: '' };
  }

  if (clean.length < 7 || clean.length > 15) {
    return {
      isValid: false,
      message: t('validators.phoneGeneralLen', { label: fieldLabel }),
    };
  }

  return { isValid: true, message: '' };
}

/**
 * Validate RFC 5322 compliant Email format.
 *
 * @param {string} email
 * @param {Object} options
 * @returns {{ isValid: boolean, message: string }}
 */
export function validateEmail(email, { required = false, label = '' } = {}) {
  const fieldLabel = label || t('validators.emailLabel');
  if (email === undefined || email === null || email.toString().trim() === '') {
    if (required) {
      return { isValid: false, message: t('validators.required', { label: fieldLabel }) };
    }
    return { isValid: true, message: '' };
  }

  const clean = email.toString().trim();
  const emailRegex = /^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/;

  if (!emailRegex.test(clean) || clean.endsWith('.')) {
    return {
      isValid: false,
      message: t('validators.emailInvalid', { label: fieldLabel }),
    };
  }

  return { isValid: true, message: '' };
}

/**
 * Validate Required string with minimum length.
 *
 * @param {any} val
 * @param {Object} options
 * @returns {{ isValid: boolean, message: string }}
 */
export function validateRequired(val, { minLength = 2, label = '' } = {}) {
  const fieldLabel = label || t('validators.fieldLabel');
  if (val === undefined || val === null || val.toString().trim() === '') {
    return { isValid: false, message: t('validators.requiredNonEmpty', { label: fieldLabel }) };
  }

  const str = val.toString().trim();
  if (str.length < minLength) {
    return { isValid: false, message: t('validators.minLength', { label: fieldLabel, min: minLength }) };
  }

  return { isValid: true, message: '' };
}
