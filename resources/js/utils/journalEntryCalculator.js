/**
 * Decimal-safe (integer minor-unit / cents) calculator for Journal Entry lines.
 * Avoids IEEE 754 floating-point drift during live totals and balance checks.
 */

/**
 * Convert a numeric/string amount to integer minor units (cents, scale = 2).
 *
 * @param {string|number|null|undefined} value
 * @returns {number}
 */
export function toCents(value) {
  if (value === null || value === undefined || value === '') {
    return 0;
  }

  const raw = String(value).trim();
  if (!/^-?\d+(?:\.\d*)?$/.test(raw)) {
    return NaN;
  }

  const negative = raw.startsWith('-');
  const unsigned = negative ? raw.slice(1) : raw;
  const [intPart, fracPart = ''] = unsigned.split('.');
  const paddedFrac = (fracPart + '00').slice(0, 2);
  const cents = parseInt(intPart || '0', 10) * 100 + parseInt(paddedFrac, 10);

  return negative ? -cents : cents;
}

/**
 * Format integer cents back into a fixed 2-decimal string.
 *
 * @param {number} cents
 * @returns {string}
 */
export function centsToDecimalString(cents) {
  const safe = Number.isFinite(cents) ? Math.trunc(cents) : 0;
  const negative = safe < 0;
  const abs = Math.abs(safe);
  const whole = Math.floor(abs / 100);
  const frac = String(abs % 100).padStart(2, '0');

  return `${negative ? '-' : ''}${whole}.${frac}`;
}

/**
 * Calculate live totals and balance state across journal entry lines.
 *
 * @param {Array<{ debit?: string|number, credit?: string|number }>} lines
 * @returns {{
 *   totalDebitCents: number,
 *   totalCreditCents: number,
 *   differenceCents: number,
 *   totalDebit: string,
 *   totalCredit: string,
 *   difference: string,
 *   isBalanced: boolean,
 *   hasPositiveTotal: boolean,
 * }}
 */
export function calculateJournalEntryTotals(lines = []) {
  let totalDebitCents = 0;
  let totalCreditCents = 0;

  for (const line of lines) {
    const debitCents = toCents(line?.debit ?? 0);
    const creditCents = toCents(line?.credit ?? 0);

    if (!Number.isNaN(debitCents) && debitCents > 0) {
      totalDebitCents += debitCents;
    }

    if (!Number.isNaN(creditCents) && creditCents > 0) {
      totalCreditCents += creditCents;
    }
  }

  const differenceCents = totalDebitCents - totalCreditCents;
  const hasPositiveTotal = totalDebitCents > 0 || totalCreditCents > 0;
  const isBalanced = differenceCents === 0 && totalDebitCents > 0;

  return {
    totalDebitCents,
    totalCreditCents,
    differenceCents,
    totalDebit: centsToDecimalString(totalDebitCents),
    totalCredit: centsToDecimalString(totalCreditCents),
    difference: centsToDecimalString(differenceCents),
    isBalanced,
    hasPositiveTotal,
  };
}
