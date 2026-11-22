/**
 * Extract a user-facing error message from an Axios/API error response.
 *
 * @param {any} err
 * @param {string} fallback
 * @returns {string}
 */
export const extractApiError = (err, fallback = '') => {
  const data = err?.response?.data;
  if (data?.message && typeof data.message === 'string') {
    return data.message;
  }

  if (data?.errors && typeof data.errors === 'object') {
    const firstKey = Object.keys(data.errors)[0];
    const firstVal = firstKey ? data.errors[firstKey] : null;
    if (Array.isArray(firstVal) && firstVal[0]) {
      return String(firstVal[0]);
    }
    if (typeof firstVal === 'string' && firstVal) {
      return firstVal;
    }
  }

  return err?.message || fallback;
};
